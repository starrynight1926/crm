<?php

namespace App\Jobs;

use App\Models\Assignment;
use App\Models\CustomField;
use App\Models\Lead;
use App\Models\LeadCustomValue;
use App\Models\LeadStatusLog;
use App\Models\RawLead;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pipeline chuẩn hóa raw → clean (scope.md mục 3):
 * validate/chuẩn hóa SĐT → check trùng (trùng thì gộp, không chia mới) → ghi MySQL kèm raw_lead_id.
 */
class ProcessRawLead implements ShouldQueue
{
    use Queueable;

    /** Retry khi deadlock MySQL lúc nhiều worker chia số cùng lúc (job idempotent). */
    public int $tries = 3;

    public array $backoff = [1, 5];

    public function __construct(public int $rawLeadId)
    {
    }

    public function handle(): void
    {
        $raw = RawLead::find($this->rawLeadId);

        if (! $raw) {
            return;
        }

        // Retry sau deadlock: lead đã tạo nhưng chia số dở dang → chia tiếp thay vì bỏ qua
        if ($raw->status === RawLead::STATUS_PROCESSED && $raw->clean_lead_id) {
            $lead = Lead::find($raw->clean_lead_id);
            if ($lead && $lead->pool_level !== Lead::POOL_PERSONAL) {
                app(\App\Services\DistributionEngine::class)->distribute($lead);
            }

            return;
        }

        if ($raw->status !== RawLead::STATUS_PENDING) {
            return; // failed / duplicate — đã xử lý
        }

        $payload = $raw->payload ?? [];

        // --- Validate ---
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            $this->fail_($raw, 'Thiếu tên khách hàng.');
            return;
        }

        $rawPhone = trim((string) ($payload['phone'] ?? ''));
        if ($rawPhone === '') {
            $this->fail_($raw, 'Thiếu số điện thoại.');
            return;
        }

        $phone = Lead::normalizePhone($rawPhone);
        if ($phone === null) {
            $this->fail_($raw, "SĐT không hợp lệ: \"{$rawPhone}\".");
            return;
        }

        // --- Check trùng: gộp vào lead cũ, không tạo mới ---
        $existing = Lead::where('phone', $phone)->first();
        if ($existing) {
            $this->mergeInto($existing, $raw, $payload);
            return;
        }

        // --- Xác định org đích của lead trước khi validate ---
        // Nếu có "CHIA CHO" khớp user → org = org của user đó. Nếu không → kho chung (null).
        // Phase C1.f 2026-08-02: tách rõ 2 owner theo phase.
        //   - sale_owner (alias 'owner' cũ) → owner_id sale.
        //   - booking_owner → receiver_id (Tele/Booker phụ trách).
        $saleOwner = $this->resolveOwner(trim((string) ($payload['sale_owner'] ?? $payload['owner'] ?? '')));
        $bookingOwner = $this->resolveOwner(trim((string) ($payload['booking_owner'] ?? '')));
        $targetOwner = $saleOwner ?? $bookingOwner;
        $targetOrg = $targetOwner
            ? \App\Models\OrgUnit::find(Assignment::where('user_id', $targetOwner->id)->value('org_unit_id'))
            : null;

        // --- Validate trường tùy biến bắt buộc THEO SCOPE ORG ĐÍCH ---
        // Kho chung (null) → chỉ áp trường bắt buộc cấp công ty. Có owner → thêm trường cấp phòng/nhóm của owner.
        $applicable = CustomField::applicableTo($targetOrg);
        $missingCf = [];
        foreach ($applicable->where('required', true) as $cf) {
            if ($cf->field_type === 'code' && ($cf->rules['code_kind'] ?? '') === 'fixed') {
                continue;
            }
            $val = trim((string) ($payload['cf_' . $cf->id] ?? ''));
            if ($val === '') {
                $code = $cf->import_code ? " (#{$cf->import_code})" : '';
                $missingCf[] = $cf->label . $code;
            }
        }
        if ($missingCf !== []) {
            $orgLabel = $targetOrg?->name ?? 'Kho chung công ty';
            $this->fail_($raw, "Thiếu trường bắt buộc (cho {$orgLabel}): " . implode(', ', $missingCf));
            return;
        }

        // --- Vượt quá thẩm quyền / sai mẫu: payload chứa cf ngoài scope org đích ---
        $applicableIds = $applicable->pluck('id')->all();
        $outOfScope = [];
        foreach ($payload as $k => $v) {
            if (! is_string($k) || ! str_starts_with($k, 'cf_')) continue;
            $val = trim((string) $v);
            if ($val === '') continue;
            $cfId = (int) substr($k, 3);
            if ($cfId <= 0) continue;
            if (! in_array($cfId, $applicableIds, true)) {
                $cf = CustomField::find($cfId);
                if (! $cf) {
                    $outOfScope[] = "#{$cfId} (không tồn tại)";
                } else {
                    $scope = $cf->org_unit_id === null ? 'công ty' : ($cf->orgUnit?->name ?? "org#{$cf->org_unit_id}");
                    $outOfScope[] = "{$cf->label} (thuộc {$scope}, ngoài phạm vi)";
                }
            }
        }
        if ($outOfScope !== []) {
            $orgLabel = $targetOrg?->name ?? 'Kho chung công ty';
            $this->fail_($raw, "Dữ liệu vượt phạm vi/sai mẫu — lead đang vào {$orgLabel} nhưng payload có: " . implode(', ', $outOfScope));
            return;
        }

        // --- Tạo lead sạch ---
        // Người nhập lead: lấy từ import_batch (nếu raw đến từ import Excel/CSV).
        $importedBy = null;
        if ($raw->import_batch_id) {
            $importedBy = \App\Models\ImportBatch::where('id', $raw->import_batch_id)->value('uploaded_by');
        }

        // Nhóm nguồn: excel import từ trực page marketing default = MKT (nhóm 1) → phase=booking
        // (kho booking chờ CM booking chia). Nguồn direct-sale từ file phải khai qua source_group
        // trong payload, còn không thì fallback MKT là an toàn nhất cho trực page.
        $sourceGroup = trim((string) ($payload['source_group'] ?? Lead::SOURCE_MKT));
        // Chuẩn hoá alias: cho phép nhập chữ hoa/thường/có gạch. VD "MKT BR" → "mkt_br".
        $sourceGroup = strtolower(str_replace([' ', '-'], '_', $sourceGroup));
        if (! isset(Lead::SOURCE_GROUPS[$sourceGroup])) {
            $this->fail_($raw, "Nhóm nguồn không hợp lệ: '{$payload['source_group']}'. Xem sheet 'List nguồn' — dùng 1 trong: " . implode(', ', array_keys(Lead::SOURCE_GROUPS)));
            return;
        }
        // Phase C1.f 2026-08-02: block up nguồn ngoài quyền uploader.
        //   VD Trực Page chỉ có source.up.trucpage → chỉ up MKT. Up BOD/BDM → block.
        if ($importedBy) {
            $uploader = \App\Models\User::find($importedBy);
            if ($uploader) {
                $allowed = Lead::allowedSourceGroupsFor($uploader);
                if (! isset($allowed[$sourceGroup])) {
                    $this->fail_($raw, "Bạn không có quyền up nguồn '{$sourceGroup}'. Được phép: " . implode(', ', array_keys($allowed)));
                    return;
                }
            }
        }
        [$initPhase, $initStatus] = Lead::initialPipelineFor($sourceGroup, $targetOwner?->id);

        $lead = Lead::create([
            'raw_lead_id' => $raw->id,
            'received_date' => $this->parseDate($payload['received_date'] ?? null) ?? $raw->created_at?->toDateString() ?? now()->toDateString(),
            'name' => $name,
            'phone' => $phone,
            'insight' => $payload['insight'] ?? null,
            'link' => $payload['link'] ?? null,
            'birthday' => $this->parseDate($payload['birthday'] ?? null),
            'occupation' => $payload['occupation'] ?? null,
            'address' => $payload['address'] ?? null,
            'medical_history' => $payload['medical_history'] ?? null,
            'region' => $payload['region'] ?? null,
            'note' => $payload['note'] ?? null,
            'classification' => 'new',
            'imported_by' => $importedBy,
            'receiver_id' => $bookingOwner?->id,
            'source_group' => $sourceGroup,
            'pipeline_phase' => $initPhase,
            'pipeline_status' => $initStatus,
            'pool_level' => Lead::POOL_COMMON, // vào kho chung, chờ engine chia số (Phase 4)
        ]);
        // Trường tùy biến map từ file (payload key 'cf_<id>') — ghi trước khi sinh mã
        $this->writeCustomValues($lead, $payload);
        // Phase 6.20 — page/camp giờ là custom field cấp công ty
        $this->writeCoreCustom($lead, $payload, ['page', 'camp']);
        $lead->load('customValues');
        $lead->generateCode();

        LeadStatusLog::record($lead, 'created', null, 'Pipeline từ nguồn ' . $raw->source_type . ($raw->source_ref ? " ({$raw->source_ref})" : ''), null);

        app(\App\Services\NotificationDispatcher::class)->sendToRoles(
            \App\Support\NotificationEvents::LEAD_CREATED,
            [
                'tieu_de'  => 'Lead mới vào hệ thống',
                'noi_dung' => $lead->name . ($lead->code ? " ({$lead->code})" : ''),
                'link'     => '/leads/'.$lead->id,
                'lead_id'  => $lead->id,
            ],
            ['owner_id' => $lead->owner_id, 'org_unit_id' => $lead->org_unit_id]
        );

        $raw->update([
            'status' => RawLead::STATUS_PROCESSED,
            'clean_lead_id' => $lead->id,
            'processed_at' => now(),
        ]);

        // 2026-08-05: cột "Phương thức chia" (import xlsx trực page) — auto UPS hoặc thả kho theo perm.
        //   Payload key '_distribution_target' đã được parse ở LeadImport (auto | pool:<id>).
        //   Strict gate: pool ngoài phạm vi quyền user → HỦY BỎ upload (xóa lead vừa tạo + fail raw).
        if (! empty($payload['_distribution_target']) && $sourceGroup === Lead::SOURCE_MKT) {
            $target = $payload['_distribution_target'];
            $uploader = $importedBy ? \App\Models\User::find($importedBy) : null;

            if ($target === 'auto') {
                $facility = $uploader ? $this->resolveTrucPageFacility($uploader) : null;
                if ($facility) {
                    $picked = app(\App\Services\Ups\UpsDispatcher::class)->pickMkt($facility->id);
                    if ($picked) {
                        $this->assignToOwner($lead, $picked);
                        \App\Models\LeadStatusLog::record($lead, 'note', null, "Import: auto chia UPS → {$picked->name}", null);
                    } else {
                        \App\Models\LeadStatusLog::record($lead, 'note', null, 'Import: UPS list rỗng ở '.$facility->name.' — giữ ở kho chung.', null);
                    }
                }
            } elseif (str_starts_with($target, 'pool:') && $uploader) {
                $poolId = (int) substr($target, 5);
                $allowed = $this->allowedPoolIds($uploader);
                $pool = \App\Models\PoolUnit::find($poolId);
                if (! $pool || ! in_array($poolId, $allowed, true)) {
                    // Hủy upload row này.
                    $lead->forceDelete();
                    $this->fail_($raw, 'Kho "'.($pool?->name ?? '?').'" NGOÀI phạm vi quyền — cần lead.distribute_branch (toàn Chi nhánh) hoặc lead.distribute_company (toàn Công ty).');
                    return;
                }
                $lead->update(['pool_unit_id' => $poolId, 'pool_level' => Lead::POOL_TEAM]);
                \App\Models\LeadStatusLog::record($lead, 'note', null, "Import: thả vào kho {$pool->name}", null);
            }
        } elseif (! empty($payload['_distribution_error'])) {
            // Không nhận diện được tên kho — hủy upload row.
            $lead->forceDelete();
            $this->fail_($raw, $payload['_distribution_error']);
            return;
        }

        // Có cột CHIA CHO khớp được người → gán thẳng cho sale đó + team của họ.
        if ($targetOwner) {
            $this->assignToOwner($lead, $targetOwner);

            return;
        }

        // Không có/không khớp CHIA CHO → vào kho chung, engine chia số chạy ngay.
        app(\App\Services\DistributionEngine::class)->distribute($lead);
    }

    /**
     * Khớp giá trị cột CHIA CHO với 1 user active.
     * - Có "@" → coi là email, khớp chính xác (unique, không mơ hồ).
     * - Ngược lại → khớp theo tên: đủ họ tên → đuôi tên → chứa chuỗi; trùng nhiều thì bỏ qua.
     */
    private function resolveOwner(string $value): ?User
    {
        // Phase C1.f 2026-08-02: match CHỈ theo email — tránh trùng tên ăn cứt data.
        //   User phải nhập email chính xác (xem sheet "List Booking"/"List Sale" trong file mẫu).
        $value = trim($value);
        if ($value === '' || ! str_contains($value, '@')) {
            return null;
        }

        return User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($value)])
            ->first();
    }

    /** @deprecated giữ code cũ dưới đây để reference — không dùng nữa. */
    private function resolveOwnerByNameLegacy(string $value): ?User
    {
        $norm = fn ($s) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $s)));
        $target = $norm($value);
        $users = User::query()->where('status', User::STATUS_ACTIVE)->get(['id', 'name']);

        foreach ([
            fn ($u) => $norm($u->name) === $target,
            fn ($u) => str_ends_with($norm($u->name), ' ' . $target),
            fn ($u) => str_contains($norm($u->name), $target),
        ] as $matcher) {
            $hit = $users->filter($matcher);
            if ($hit->count() === 1) {
                return User::find($hit->first()->id);
            }
            if ($hit->count() > 1) {
                return null; // mơ hồ (VD nhiều "Giang") → user phải điền email để chắc chắn
            }
        }

        return null;
    }

    /**
     * 2026-08-05 — Danh sách pool_unit_id user được phép thả lead vào khi import.
     * Rule (khớp UI /leads/create):
     *   - lead.distribute_company → toàn bộ PoolUnit (mọi cấp).
     *   - lead.distribute_branch  → subtree Chi nhánh của user (Chi nhánh + Địa điểm con + PKD con).
     *   - mặc định                → chỉ Địa điểm của user (facility) + PKD con.
     */
    private function allowedPoolIds(User $user): array
    {
        if ($user->hasPermission('lead.distribute_company')) {
            return \App\Models\PoolUnit::pluck('id')->all();
        }
        $facility = $this->resolveTrucPageFacility($user);
        if (! $facility) return [];

        // subtree Địa điểm (facility + department con)
        $facilitySubtree = \App\Models\PoolUnit::where('path', 'like', $facility->path.'%')->pluck('id')->all();

        if ($user->hasPermission('lead.distribute_branch')) {
            $branch = $facility;
            while ($branch && $branch->kind !== 'branch') $branch = $branch->parent;
            if ($branch) {
                return \App\Models\PoolUnit::where('path', 'like', $branch->path.'%')->pluck('id')->all();
            }
        }
        return $facilitySubtree;
    }

    /**
     * 2026-08-05 — resolveTrucPageFacility: cơ sở PoolUnit của user (assignment → org_pool_map).
     * Mirror của LeadForm::trucPageFacility() — dùng cho import job (auto UPS chia).
     */
    private function resolveTrucPageFacility(User $user): ?\App\Models\PoolUnit
    {
        $ancestorOrgIds = [];
        foreach ($user->effectiveAssignments() as $assignment) {
            foreach (array_filter(explode('/', trim((string) $assignment->orgUnit->path, '/'))) as $seg) {
                $ancestorOrgIds[(int) $seg] = true;
            }
        }
        if ($ancestorOrgIds === []) return null;

        $facilities = \App\Models\PoolUnit::where('kind', 'facility')->where('is_active', true)
            ->whereIn('id', function ($q) use ($ancestorOrgIds) {
                $q->select('pool_unit_id')->from('org_pool_map')->whereIn('org_unit_id', array_keys($ancestorOrgIds));
            })->get();
        return $facilities->count() === 1 ? $facilities->first() : null;
    }

    /** Gán lead cho owner + team của owner (kho cá nhân), bỏ qua engine chia số. */
    private function assignToOwner(Lead $lead, User $owner): void
    {
        $orgId = Assignment::where('user_id', $owner->id)->value('org_unit_id');
        // 2026-08-12: set pool_unit_id (facility) theo sale để BookingEventController
        //   auto-chia Sale Tiếp Đón khi khách "đã tới" hoạt động. Trước đây pool_unit_id
        //   null → check $lead->pool_unit_id fail → không pick Sale Tiếp Đón.
        $poolUnitId = null;
        if ($orgId) {
            $ancestors = [];
            $orgUnit = \App\Models\OrgUnit::find($orgId);
            if ($orgUnit) {
                foreach (array_filter(explode('/', trim($orgUnit->path, '/'))) as $seg) {
                    $ancestors[(int) $seg] = true;
                }
                $poolUnitId = \App\Models\PoolUnit::where('kind', 'facility')
                    ->where('is_active', true)
                    ->whereIn('id', function ($q) use ($ancestors) {
                        $q->select('pool_unit_id')->from('org_pool_map')->whereIn('org_unit_id', array_keys($ancestors));
                    })
                    ->value('id');
            }
        }
        $lead->forceFill([
            'owner_id' => $owner->id,
            'org_unit_id' => $orgId,
            'pool_unit_id' => $poolUnitId,
            'pool_level' => Lead::POOL_PERSONAL,
            'assigned_at' => now(),
            'last_care_at' => now(),
        ])->save();
        $lead->load('customValues');
        $lead->generateCode(); // org đổi → mã KH có thể đổi đoạn phân loại

        LeadStatusLog::record($lead, 'note', null, 'Gán từ import (CHIA CHO): ' . $owner->name, null);
    }

    /**
     * Ghi giá trị trường tùy biến từ payload (key 'cf_<id>' => value) vào lead.
     * Lưu mọi cf hợp lệ (không lọc theo org — org quyết định lúc HIỂN THỊ, không cản LƯU),
     * để lead chuyển phòng sau vẫn có sẵn dữ liệu.
     */
    private function writeCustomValues(Lead $lead, array $payload): void
    {
        foreach ($payload as $key => $value) {
            if (! is_string($key) || ! str_starts_with($key, 'cf_')) {
                continue;
            }
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $cfId = (int) substr($key, 3);
            if ($cfId > 0 && CustomField::whereKey($cfId)->exists()) {
                LeadCustomValue::updateOrCreate(
                    ['lead_id' => $lead->id, 'custom_field_id' => $cfId],
                    ['value' => $value]
                );
            }
        }
    }

    /**
     * Phase 6.21 — Ghi các field payload có key trùng với `key` của custom_field áp cho org của lead
     * (VD 'page', 'camp' — hiện là cấp phòng Marketing). Nếu lead chưa có org → skip.
     */
    private function writeCoreCustom(Lead $lead, array $payload, array $keys): void
    {
        $applicable = CustomField::applicableTo($lead->orgUnit);
        foreach ($keys as $key) {
            $field = $applicable->firstWhere('key', $key);
            if (! $field) continue;
            $value = trim((string) ($payload[$key] ?? ''));
            if ($value === '') continue;
            LeadCustomValue::updateOrCreate(
                ['lead_id' => $lead->id, 'custom_field_id' => $field->id],
                ['value' => $value]
            );
        }
    }

    /** Gộp thông tin mới vào lead cũ: chỉ điền field còn trống, log lại. */
    private function mergeInto(Lead $existing, RawLead $raw, array $payload): void
    {
        $merged = [];
        foreach (['insight', 'link', 'region', 'note'] as $field) {
            $value = $payload[$field] ?? null;
            if ($value && ! $existing->{$field}) {
                $existing->{$field} = $value;
                $merged[] = $field;
            }
        }
        // Phase 6.21 — page/camp: field áp theo org của lead (cấp phòng Marketing)
        $applicable = CustomField::applicableTo($existing->orgUnit);
        foreach (['page', 'camp'] as $key) {
            $field = $applicable->firstWhere('key', $key);
            if (! $field) continue;
            $value = trim((string) ($payload[$key] ?? ''));
            if ($value === '') continue;
            $existingValue = LeadCustomValue::where('lead_id', $existing->id)->where('custom_field_id', $field->id)->value('value');
            if ($existingValue) continue;
            LeadCustomValue::updateOrCreate(
                ['lead_id' => $existing->id, 'custom_field_id' => $field->id],
                ['value' => $value]
            );
            $merged[] = $key;
        }

        if ($merged !== []) {
            $existing->save();
        }

        LeadStatusLog::record(
            $existing,
            'note',
            null,
            'Lead về trùng SĐT từ nguồn ' . $raw->source_type
                . ($merged !== [] ? ' — đã gộp thêm: ' . implode(', ', $merged) : ' — không có thông tin mới'),
            null
        );

        $raw->update([
            'status' => RawLead::STATUS_DUPLICATE,
            'clean_lead_id' => $existing->id,
            'error_reason' => 'Trùng SĐT với lead #' . $existing->id . ' — đã gộp.',
            'processed_at' => now(),
        ]);
    }

    private function fail_(RawLead $raw, string $reason): void
    {
        $raw->update([
            'status' => RawLead::STATUS_FAILED,
            'error_reason' => $reason,
            'processed_at' => now(),
        ]);
    }

    private function parseDate(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y'] as $format) {
            try {
                return \Carbon\Carbon::createFromFormat($format, trim($value))->toDateString();
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}
