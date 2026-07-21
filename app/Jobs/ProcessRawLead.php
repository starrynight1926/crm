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
        $targetOwner = $this->resolveOwner(trim((string) ($payload['owner'] ?? '')));
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
        $lead = Lead::create([
            'raw_lead_id' => $raw->id,
            'received_date' => $this->parseDate($payload['received_date'] ?? null) ?? $raw->created_at?->toDateString() ?? now()->toDateString(),
            'name' => $name,
            'phone' => $phone,
            'insight' => $payload['insight'] ?? null,
            'link' => $payload['link'] ?? null,
            'region' => $payload['region'] ?? null,
            'note' => $payload['note'] ?? null,
            'classification' => 'new',
            'pool_level' => Lead::POOL_COMMON, // vào kho chung, chờ engine chia số (Phase 4)
        ]);
        // Trường tùy biến map từ file (payload key 'cf_<id>') — ghi trước khi sinh mã
        $this->writeCustomValues($lead, $payload);
        // Phase 6.20 — page/camp giờ là custom field cấp công ty
        $this->writeCoreCustom($lead, $payload, ['page', 'camp']);
        $lead->load('customValues');
        $lead->generateCode();

        LeadStatusLog::record($lead, 'created', null, 'Pipeline từ nguồn ' . $raw->source_type . ($raw->source_ref ? " ({$raw->source_ref})" : ''), null);

        $raw->update([
            'status' => RawLead::STATUS_PROCESSED,
            'clean_lead_id' => $lead->id,
            'processed_at' => now(),
        ]);

        // Có cột CHIA CHO khớp được người → gán thẳng cho sale đó + team của họ.
        if ($targetOwner) {
            $this->assignToOwner($lead, $targetOwner);

            return;
        }

        // Không có/không khớp CHIA CHO → vào kho chung, engine chia số chạy ngay.
        app(\App\Services\DistributionEngine::class)->distribute($lead);
    }

    /**
     * Khớp giá trị cột CHIA CHO với 1 user (chỉ nhận khi DUY NHẤT, tránh gán nhầm):
     * ưu tiên trùng đúng họ tên → trùng phần đuôi (tên gọi) → chứa chuỗi.
     */
    private function resolveOwner(string $name): ?User
    {
        if ($name === '') {
            return null;
        }
        $norm = fn ($s) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $s)));
        $target = $norm($name);
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
                return null; // mơ hồ (VD nhiều "Giang") → bỏ qua, không đoán
            }
        }

        return null;
    }

    /** Gán lead cho owner + team của owner (kho cá nhân), bỏ qua engine chia số. */
    private function assignToOwner(Lead $lead, User $owner): void
    {
        $orgId = Assignment::where('user_id', $owner->id)->value('org_unit_id');
        $lead->forceFill([
            'owner_id' => $owner->id,
            'org_unit_id' => $orgId,
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
