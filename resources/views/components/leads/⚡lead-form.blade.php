<?php

use App\Models\Assignment;
use App\Models\AuditLog;
use App\Models\BookingLog;
use App\Models\CallLog;
use App\Models\CustomField;
use App\Models\Facility;
use App\Models\Lead;
use App\Models\LeadCustomValue;
use App\Models\LeadPhaseClosure;
use App\Models\LeadStatusLog;
use App\Models\LeadTreatment;
use App\Models\LeadUpsell;
use App\Models\OrgUnit;
use App\Models\Service;
use App\Models\StaffMember;
use App\Models\User;
use Livewire\Component;

new class extends Component
{
    public ?Lead $lead = null;

    public string $name = '';

    public string $phone = '';

    public string $received_date = '';

    // Phase 6.20 — page/camp giờ là custom field cấp công ty (không còn property core)


    public string $insight = '';

    public string $link = '';

    public string $region = '';

    // --- INSIGHT ---
    public string $birthday = '';
    public string $address = '';
    public string $medical_history = '';
    public string $occupation = '';

    // --- Dịch vụ ---
    public string $service_name = '';

    // --- LIỆU TRÌNH (Phase 6.11 — dạng thẻ 1-N, mỗi lần bác sĩ + đánh giá riêng) ---
    /** @var array<int, array{performed_at: string, performing_doctor_id: string|int|null, quality_rating: string}> */
    public array $treatmentRows = [];

    // --- DV tiềm năng ---
    public string $potential_service = '';

    // --- UPSELL ---
    /** @var array<int, array{staff_member_id: string, service_id: string, amount: string}> */
    public array $upsellRows = [];

    /** Kho: '' = kho chung công ty | 'org:{id}' = kho chung phòng/team. */
    public string $poolTarget = '';

    /** Chia trực tiếp cho cá nhân (ưu tiên hơn kho nếu có). */
    public ?int $personId = null;

    /** Ô search tên khi chia cá nhân. */
    public string $personSearch = '';

    public string $status_1 = '';

    public string $status_2 = '';

    public string $note = '';

    public string $classification = 'new';

    /** Trạng thái đặt lịch booking (Chưa đặt / Đã đặt / Hẹn lại). */
    public string $bookingStatus = 'not_booked';

    /** 1 trong 7 nhóm nguồn (MKT / MKT BR / BDM / BOD / SA / BA / WI). */
    public string $sourceGroup = '';

    public ?int $facilityId = null;
    public ?int $doctorId = null;
    public ?int $consultant1Id = null;
    public ?int $consultant2Id = null;
    public ?int $consultant3Id = null;

    /** @var array<int, string> custom_field_id => value */
    public array $custom = [];

    public ?int $duplicateLeadId = null;

    // ============================================================
    // Phase 6.21 — Customer Flow 7 phase state + methods (2026-07-30)
    // ============================================================

    /** Tab phase đang active (1..7). Alpine wire :phase. */
    public int $activePhase = 1;

    /** Đến lần đầu — bind vào leads.is_first_visit. */
    public bool $isFirstVisit = true;

    // State form thêm call log
    public string $newCallStatus = 'thanh_cong';
    public string $newCallNote = '';

    // State form thêm booking log
    public string $newBookingType = ''; // '' = -- Chọn --, tham_kham | dich_vu (required)
    public string $newBookingStatus = 'cho_xac_nhan';
    public string $newBookingScheduledAt = '';
    public ?int $newBookingFacilityId = null;
    public ?int $newBookingDoctorId = null;
    public ?int $newBookingServiceId = null;
    public string $newBookingNote = '';
    /** @var array<int, int|null> Phase 4 rework 2026-08-01: multi-CV per booking. Mặc định 1 ô. */
    public array $newBookingConsultantIds = [null];

    // State form Check-in (Phase 5)
    public string $checkinTime = '';
    public ?int $checkinReceptionistId = null;
    public ?int $checkinDoctorId = null;
    public string $checkinNote = '';

    public function addCallLog(): void
    {
        if (! $this->lead) {
            session()->flash('cf_error', 'Lead chưa được tạo. Bấm "Lưu thông tin khách hàng" trước.');
            return;
        }
        $user = auth()->user();
        if (! $this->lead->canLogCall($user)) {
            session()->flash('cf_error', 'Bạn không có quyền ghi log gọi.');
            return;
        }
        $this->validate([
            'newCallStatus' => 'required|in:' . implode(',', array_keys(CallLog::STATUSES)),
            'newCallNote'   => 'nullable|string|max:1000',
        ]);
        CallLog::create([
            'lead_id'   => $this->lead->id,
            'user_id'   => $user->id,
            'status'    => $this->newCallStatus,
            'note'      => $this->newCallNote ?: null,
            'called_at' => now(),
        ]);
        $this->newCallNote = '';
        $this->lead->refresh();
        session()->flash('cf_ok', 'Đã ghi log cuộc gọi.');
    }

    public function addBookingLog(): void
    {
        if (! $this->lead) {
            session()->flash('cf_error', 'Lead chưa được tạo. Bấm "Lưu thông tin khách hàng" trước.');
            return;
        }
        $user = auth()->user();
        if (! $this->lead->canLogBooking($user)) {
            session()->flash('cf_error', 'Bạn không có quyền ghi log booking.');
            return;
        }
        $this->validate([
            'newBookingType'          => 'required|in:tham_kham,dich_vu',
            'newBookingStatus'        => 'required|in:' . implode(',', array_keys(BookingLog::STATUSES)),
            'newBookingScheduledAt'   => 'nullable|date',
            'newBookingFacilityId'    => 'nullable|exists:facilities,id',
            'newBookingDoctorId'      => 'nullable|exists:staff_members,id',
            'newBookingServiceId'     => 'nullable|exists:services,id',
            'newBookingNote'          => 'nullable|string|max:1000',
            'newBookingConsultantIds' => 'array',
            'newBookingConsultantIds.*' => 'nullable|exists:users,id',
        ], ['newBookingType.required' => 'Chọn loại booking (Thăm khám hoặc Dịch vụ).']);
        $bl = BookingLog::create([
            'lead_id'      => $this->lead->id,
            'user_id'      => $user->id,
            'type'         => $this->newBookingType,
            'status'       => $this->newBookingStatus,
            'scheduled_at' => $this->newBookingScheduledAt ?: null,
            'facility_id'  => $this->newBookingFacilityId,
            'doctor_id'    => $this->newBookingDoctorId,
            'service_id'   => $this->newBookingServiceId,
            'note'         => $this->newBookingNote ?: null,
        ]);
        // Phase 4 rework 2026-08-01: attach multi-CV pivot theo thứ tự chọn (position=1..n).
        $cvIds = array_values(array_filter($this->newBookingConsultantIds, fn ($v) => (int) $v > 0));
        $syncData = [];
        foreach ($cvIds as $i => $uid) {
            $syncData[(int) $uid] = ['position' => $i + 1];
        }
        if ($syncData) {
            $bl->consultants()->sync($syncData);
        }
        BookingLog::syncLeadBookingStatus($this->lead->id);
        // Nếu booking đã duyệt + có CV1 + lead chưa có Sale → handoff CV1 thành Sale phụ trách.
        if ($bl->status === BookingLog::STATUS_DA_XAC_NHAN && ! empty($cvIds) && ! $this->lead->fresh()->owner_id) {
            $this->assignToSale((int) $cvIds[0], 1);
        }
        $this->reset([
            'newBookingType', 'newBookingScheduledAt', 'newBookingFacilityId',
            'newBookingDoctorId', 'newBookingServiceId', 'newBookingNote',
        ]);
        $this->newBookingConsultantIds = [null];
        $this->lead->refresh();
        $this->bookingStatus = $this->lead->booking_status ?? 'not_booked';
        session()->flash('cf_ok', 'Đã ghi booking mới. Đã đồng bộ trạng thái.');
    }

    public function addBookingConsultantSlot(): void
    {
        $this->newBookingConsultantIds[] = null;
    }

    public function removeBookingConsultantSlot(int $index): void
    {
        if (count($this->newBookingConsultantIds) <= 1) return;
        unset($this->newBookingConsultantIds[$index]);
        $this->newBookingConsultantIds = array_values($this->newBookingConsultantIds);
    }

    public function bulkSavePhases(): void
    {
        if (! $this->lead) {
            session()->flash('cf_error', 'Lead chưa được tạo. Bấm "Lưu thông tin khách hàng" trước để tạo lead, rồi chốt phase.');
            return;
        }
        try {
            $closed = $this->lead->bulkSave(auth()->user());
            $this->lead->refresh();
            $this->activePhase = min((int) $this->lead->phase, 5);
            session()->flash('cf_ok', 'Đã chốt ' . count($closed) . ' phase (' . min($closed) . '→' . max($closed) . ').');
        } catch (\Throwable $e) {
            session()->flash('cf_error', $e->getMessage());
        }
    }

    public function closePhaseNow(int $idx): void
    {
        if (! $this->lead) {
            session()->flash('cf_error', 'Lead chưa tồn tại.');
            return;
        }
        try {
            // Với Phase 5, gộp 4 field checkin vào note của closure
            $note = null;
            if ($idx === 5) {
                $recept = $this->checkinReceptionistId ? \App\Models\User::find($this->checkinReceptionistId)?->name : '?';
                $doc = $this->checkinDoctorId ? \App\Models\StaffMember::find($this->checkinDoctorId)?->name : '?';
                $note = "Check-in: {$this->checkinTime} | Lễ tân: {$recept} | BS tiếp nhận: {$doc}";
                if ($this->checkinNote) $note .= " | Note: {$this->checkinNote}";
            }
            $this->lead->closePhase($idx, auth()->user(), $note);
            $this->lead->refresh();
            $this->activePhase = min((int) $this->lead->phase, 5);
            session()->flash('cf_ok', 'Đã kết thúc phase ' . $idx . '.');
        } catch (\Throwable $e) {
            session()->flash('cf_error', $e->getMessage());
        }
    }

    public function rollbackToPhase(int $idx): void
    {
        if (! $this->lead) return;
        try {
            $this->lead->rollbackTo($idx, auth()->user());
            $this->lead->refresh();
            $this->activePhase = $idx;
            session()->flash('cf_ok', 'Đã lùi phase về ' . $idx . '.');
        } catch (\Throwable $e) {
            session()->flash('cf_error', $e->getMessage());
        }
    }

    /**
     * Phase 6.21i — Chuyển lead sang Sale phụ trách (chọn ở Phase 4).
     * Reuse pattern giống Phase 2 (chọn Tele), nhưng filter user theo role có lead.consult.
     */
    /**
     * Assign chuyên viên tư vấn (CV1 hoặc CV2) — dùng ở phase 4.
     * Rule (2026-08-01):
     *   - Yêu cầu booking_status = booked (rule cứng — không cho set sale nếu chưa booked).
     *   - Yêu cầu perm lead.distribute_sale / distribute / phase.rollback.
     *   - CV1 = handoff chính: set owner_id=userId, receiver_id=owner cũ (giữ tele lịch sử),
     *     pipeline_phase=sale, pipeline_status=in_care, consultant_1_id=userId. Auto-close P4.
     *   - CV2 = chỉ set consultant_2_id (phụ chăm sóc). Không đổi owner.
     */
    public function assignToSale(int $userId, int $slot = 1): void
    {
        if (! $this->lead) {
            session()->flash('cf_error', 'Lead chưa tồn tại.');
            return;
        }
        if ($this->lead->booking_status !== Lead::BOOKING_BOOKED) {
            session()->flash('cf_error', 'Chưa đặt booking cho khách. Đặt booking + duyệt xong mới gán được chuyên viên tư vấn.');
            return;
        }
        $viewer = auth()->user();
        if (! $viewer->hasPermission('lead.distribute_sale')
            && ! $viewer->hasPermission('phase.rollback')
            && ! $viewer->hasPermission('lead.distribute')) {
            session()->flash('cf_error', 'Không có quyền phân bổ Sale (cần lead.distribute_sale).');
            return;
        }
        $sale = User::find($userId);
        if (! $sale) {
            session()->flash('cf_error', 'Không tìm thấy user.');
            return;
        }
        if ($slot === 2) {
            $this->lead->update(['consultant_2_id' => $sale->id]);
            $this->consultant2Id = $sale->id;
            $this->lead->refresh();
            session()->flash('cf_ok', 'Đã gán CV tư vấn 2: ' . $sale->name);
            return;
        }
        // CV1 — handoff chính
        $prevOwnerId = $this->lead->owner_id;
        $this->lead->update([
            'owner_id'         => $sale->id,
            'receiver_id'      => $prevOwnerId ?: $this->lead->receiver_id, // giữ tele cũ nếu có
            'assigned_at'      => now(),
            'pool_level'       => Lead::POOL_PERSONAL,
            'pipeline_phase'   => Lead::PHASE_SALE,
            'pipeline_status'  => Lead::PSTATUS_IN_CARE,
            'consultant_1_id'  => $sale->id,
            'org_unit_id'      => $sale->assignments->first()?->org_unit_id ?: $this->lead->org_unit_id,
        ]);
        // Auto-close phase 4 để indicator xanh.
        \App\Models\LeadPhaseClosure::updateOrCreate(
            ['lead_id' => $this->lead->id, 'phase' => Lead::CF_PHASE_BOOKING],
            ['closed_by' => $viewer->id, 'closed_at' => now(), 'note' => 'Auto-close: gán CV tư vấn 1']
        );
        if ((int) $this->lead->phase <= Lead::CF_PHASE_BOOKING) {
            $this->lead->update(['phase' => Lead::CF_PHASE_CHECKIN]);
        }
        $this->lead->refresh();
        $this->consultant1Id = $sale->id;
        LeadStatusLog::record($this->lead, 'assigned_sale', null, 'Gán CV1 + chuyển phase Sale: ' . $sale->name, $viewer->id);
        session()->flash('cf_ok', 'Đã gán CV tư vấn 1 + chuyển lead sang phase Sale: ' . $sale->name);
    }

    /**
     * Phase 6.21h — Placeholder: đồng bộ trạng thái booking từ hệ thống booking (lara-sbooking).
     * Chưa có API thật — tạm flash message. Sẽ tích hợp sau khi có endpoint.
     */
    public function syncBookingsFromExternal(): void
    {
        if (! $this->lead) return;
        // TODO: gọi API lara-sbooking, lấy list booking + status → update booking_logs.
        session()->flash('cf_ok', 'Đã yêu cầu đồng bộ từ bên booking. (Chưa có API — placeholder.)');
    }

    public function markReturning(): void
    {
        if (! $this->lead) return;
        try {
            $this->lead->markReturning(auth()->user());
            $this->lead->refresh();
            $this->isFirstVisit = false;
            $this->activePhase = 3;
            session()->flash('cf_ok', 'Đã khởi động lần thăm khám mới.');
        } catch (\Throwable $e) {
            session()->flash('cf_error', $e->getMessage());
        }
    }

    public function selectPhaseTab(int $idx): void
    {
        if ($idx < 1 || $idx > 7) return;
        if ($this->lead && $this->lead->phaseState($idx) === 'skipped') return;
        $this->activePhase = $idx;
    }

    public function mount(?Lead $lead = null): void
    {
        if ($lead?->exists) {
            // Phase 6.21g — dùng canOpenEditForm để cho phép Team Tele (chỉ có lead.read_booking)
            // vào form ở chế độ readonly, có thể xem info + thao tác Customer Flow (call log, close phase).
            // Việc sửa info cá nhân vẫn bị chặn ở tầng save() qua canEditPersonalInfo.
            abort_unless($lead->canOpenEditForm(auth()->user()), 403,
                'Bạn không có quyền truy cập lead ở phase ' . ($lead->pipeline_phase ?? 'sale') . '.');
            $this->lead = $lead;
            $this->fillFromLead($lead);
            // Phase 6.21 — default tab = phase hiện tại (hoặc openFrom nếu bulk mode).
            $this->activePhase = $lead->isBulkOpen() ? $lead->openFrom() : (int) $lead->phase;
            if ($this->activePhase < 1 || $this->activePhase > 7) $this->activePhase = 1;
            $this->isFirstVisit = (bool) $lead->is_first_visit;
        } else {
            $this->received_date = now()->toDateString();
            $this->activePhase = 1;
        }
    }

    private function fillFromLead(Lead $lead): void
    {
        $this->name = $lead->name;
        $this->phone = $lead->phone;
        $this->received_date = $lead->received_date->toDateString();
        // Phase 6.20 — page/camp giờ trong custom_values, sẽ hiện trong section Trường bổ sung
        $this->insight = $lead->insight ?? '';
        $this->link = $lead->link ?? '';
        $this->region = $lead->region ?? '';
        $this->personId = $lead->owner_id;
        if ($lead->owner_id) {
            $this->poolTarget = '';
        } elseif ($lead->pool_level === Lead::POOL_TEAM && $lead->org_unit_id) {
            $this->poolTarget = 'org:' . $lead->org_unit_id;
        } else {
            $this->poolTarget = 'company';
        }
        $this->status_1 = $lead->status_1 ?? '';
        $this->status_2 = $lead->status_2 ?? '';
        $this->note = $lead->note ?? '';
        $this->classification = $lead->classification;
        $this->bookingStatus = $lead->booking_status ?? 'not_booked';
        $this->sourceGroup = $lead->source_group ?? '';
        $this->facilityId = $lead->facility_id;
        $this->doctorId = $lead->doctor_id;
        $this->consultant1Id = $lead->consultant_1_id;
        $this->consultant2Id = $lead->consultant_2_id;
        $this->consultant3Id = $lead->consultant_3_id;

        // Trường mới
        $this->birthday = $lead->birthday?->toDateString() ?? '';
        $this->address = $lead->address ?? '';
        $this->medical_history = $lead->medical_history ?? '';
        $this->occupation = $lead->occupation ?? '';
        $this->service_name = $lead->service_name ?? '';
        $this->treatmentRows = $lead->treatments->map(fn ($t) => [
            'performed_at' => $t->performed_at?->toDateString() ?? '',
            'performing_doctor_id' => (string) ($t->performing_doctor_id ?? ''),
            'quality_rating' => $t->quality_rating ?? '',
        ])->all();
        $this->potential_service = $lead->potential_service ?? '';
        $this->upsellRows = $lead->upsells->map(fn ($u) => [
            'staff_member_id' => (string) ($u->staff_member_id ?? ''),
            'service_id' => (string) ($u->service_id ?? ''),
            'amount' => $u->amount ? number_format($u->amount, 0, '', '.') : '',
        ])->all();

        $this->custom = $lead->customValues->pluck('value', 'custom_field_id')
            ->map(fn ($v) => (string) $v)->all();
    }

    /** Chọn cá nhân → lead rời kho chung, chuyển hẳn sang kho cá nhân. */
    public function selectPerson(int $id): void
    {
        if ($this->assignableUserIds()->contains($id)) {
            $this->personId = $id;
            $this->personSearch = '';
            $this->poolTarget = '';
        }
    }

    public function clearPerson(): void
    {
        $this->personId = null;
    }

    public function addUpsellRow(): void
    {
        $this->upsellRows[] = ['staff_member_id' => '', 'service_id' => '', 'amount' => ''];
    }

    public function removeUpsellRow(int $index): void
    {
        unset($this->upsellRows[$index]);
        $this->upsellRows = array_values($this->upsellRows);
    }

    public function addTreatmentRow(): void
    {
        $this->treatmentRows[] = ['performed_at' => '', 'performing_doctor_id' => '', 'quality_rating' => ''];
    }

    public function removeTreatmentRow(int $index): void
    {
        unset($this->treatmentRows[$index]);
        $this->treatmentRows = array_values($this->treatmentRows);
    }

    /** Chọn kho (dù là kho chung công ty) thì bỏ chia cá nhân. */
    public function updatedPoolTarget(): void
    {
        $this->personId = null;
    }

    /** Org của một user theo assignment hiệu lực. */
    private function userOrgId(int $userId): ?int
    {
        return Assignment::where('user_id', $userId)->effective()->first()?->org_unit_id;
    }

    /** Org quyết định bộ trường tùy biến theo đích chia đang chọn. */
    private function targetOrgUnit(): ?OrgUnit
    {
        if ($this->personId) {
            $orgId = $this->userOrgId($this->personId);
            return $orgId ? OrgUnit::find($orgId) : null;
        }
        if (str_starts_with($this->poolTarget, 'org:')) {
            return OrgUnit::find((int) substr($this->poolTarget, 4));
        }

        return $this->lead?->org_unit_id ? $this->lead->orgUnit : null;
    }

    /** Validate + trả về [field_id => value] chỉ gồm các trường áp dụng. Trả null nếu có lỗi. */
    private function validateCustomFields(): ?array
    {
        $fields = CustomField::applicableTo($this->targetOrgUnit());
        $clean = [];
        $hasError = false;

        foreach ($fields as $field) {
            if ($field->field_type === 'code' && ($field->rules['code_kind'] ?? '') === 'fixed') {
                continue;
            }

            $value = trim((string) ($this->custom[$field->id] ?? ''));

            if ($value === '') {
                if ($field->required) {
                    $this->addError("custom.{$field->id}", "Trường \"{$field->label}\" là bắt buộc.");
                    $hasError = true;
                }
                continue;
            }

            $rules = $field->rules ?? [];

            if ($field->field_type === 'number') {
                $num = str_replace(',', '.', $value);
                if (! is_numeric($num)) {
                    $this->addError("custom.{$field->id}", "\"{$field->label}\" phải là số.");
                    $hasError = true;
                    continue;
                }
                $num = (float) $num;
                if (isset($rules['min']) && $num < $rules['min']) {
                    $this->addError("custom.{$field->id}", "\"{$field->label}\" phải ≥ {$rules['min']}.");
                    $hasError = true;
                    continue;
                }
                if (isset($rules['max']) && $num > $rules['max']) {
                    $this->addError("custom.{$field->id}", "\"{$field->label}\" phải ≤ {$rules['max']}.");
                    $hasError = true;
                    continue;
                }
            } elseif ($field->field_type === 'text') {
                if (isset($rules['maxlength']) && mb_strlen($value) > $rules['maxlength']) {
                    $this->addError("custom.{$field->id}", "\"{$field->label}\" tối đa {$rules['maxlength']} ký tự.");
                    $hasError = true;
                    continue;
                }
            } elseif ($field->field_type === 'email') {
                if (! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->addError("custom.{$field->id}", "\"{$field->label}\" phải là email hợp lệ.");
                    $hasError = true;
                    continue;
                }
            } elseif ($field->field_type === 'select') {
                if (! in_array($value, $field->options ?? [], true)) {
                    $this->addError("custom.{$field->id}", "Giá trị không nằm trong danh sách của \"{$field->label}\".");
                    $hasError = true;
                    continue;
                }
            } elseif ($field->field_type === 'code') {
                $kind = $rules['code_kind'] ?? 'input';
                if ($kind === 'fixed') {
                    continue;
                }
                $value = CustomField::normalizeCode($value);
                if ($value === '') {
                    $this->addError("custom.{$field->id}", "\"{$field->label}\" cần chữ/số.");
                    $hasError = true;
                    continue;
                }
                if ($kind === 'select' && ! in_array($value, array_map([CustomField::class, 'normalizeCode'], $field->options ?? []), true)) {
                    $this->addError("custom.{$field->id}", "Mã không nằm trong danh sách của \"{$field->label}\".");
                    $hasError = true;
                    continue;
                }
            }

            $clean[$field->id] = $value;
        }

        return $hasError ? null : $clean;
    }

    private function parseAmount(string $raw): int
    {
        return (int) preg_replace('/\D/', '', $raw);
    }

    private function syncUpsells(Lead $lead): void
    {
        $lead->upsells()->delete();
        foreach ($this->upsellRows as $row) {
            if (empty($row['service_id'])) {
                continue;
            }
            LeadUpsell::create([
                'lead_id' => $lead->id,
                'staff_member_id' => $row['staff_member_id'] ?: null,
                'service_id' => $row['service_id'],
                'amount' => $this->parseAmount($row['amount'] ?? '0'),
            ]);
        }
    }

    /** Phase 6.11 — Sync liệu trình (delete + recreate theo thứ tự nhập). */
    private function syncTreatments(Lead $lead): void
    {
        $lead->treatments()->delete();
        $seq = 1;
        foreach ($this->treatmentRows as $row) {
            $hasContent = ($row['performed_at'] ?? '') !== ''
                || ($row['performing_doctor_id'] ?? '') !== ''
                || trim((string) ($row['quality_rating'] ?? '')) !== '';
            if (! $hasContent) {
                continue;
            }
            LeadTreatment::create([
                'lead_id' => $lead->id,
                'sequence' => $seq++,
                'performed_at' => $row['performed_at'] ?: null,
                'performing_doctor_id' => $row['performing_doctor_id'] ?: null,
                'quality_rating' => trim((string) ($row['quality_rating'] ?? '')) ?: null,
            ]);
        }
    }

    private function syncCustomValues(Lead $lead, array $clean): void
    {
        foreach ($clean as $fieldId => $value) {
            LeadCustomValue::updateOrCreate(
                ['lead_id' => $lead->id, 'custom_field_id' => $fieldId],
                ['value' => $value]
            );
        }
    }

    public function save(): void
    {
        // Phase 6.20 — Gate write: readonly user (VD Team booking chỉ có lead.read_booking) không được phép lưu.
        if ($this->lead) {
            abort_unless($this->lead->canEditPersonalInfo(auth()->user()), 403);
        } else {
            abort_unless(auth()->user()->hasPermission('lead.create'), 403);
        }

        $this->duplicateLeadId = null;

        $allowedSources = Lead::allowedSourceGroupsFor(auth()->user());
        // Phase 6.21g — UPDATE mode: nếu lead đã có sourceGroup cũ ngoài quyền user
        // (VD Tele update lead nguồn MKT do người khác up), vẫn cho pass source hiện có.
        if ($this->lead?->exists && $this->sourceGroup && ! isset($allowedSources[$this->sourceGroup])) {
            $allowedSources[$this->sourceGroup] = $this->sourceGroup;
        }
        $this->validate([
            'name' => 'required|string|max:150',
            'phone' => 'required|string',
            'received_date' => 'required|date',
            'classification' => 'required|in:' . implode(',', array_keys(Lead::CLASSIFICATIONS)),
            'bookingStatus' => 'required|in:' . implode(',', array_keys(Lead::BOOKING_STATUSES)),
            'sourceGroup' => 'required|in:' . implode(',', array_keys($allowedSources)),
            'link' => 'nullable|string|max:500',
            'birthday' => 'nullable|date',
            'address' => 'nullable|string|max:500',
            'occupation' => 'nullable|string|max:150',
            'treatmentRows' => 'array',
            'treatmentRows.*.performed_at' => 'nullable|date',
            'treatmentRows.*.performing_doctor_id' => 'nullable|exists:staff_members,id',
            'treatmentRows.*.quality_rating' => 'nullable|string|max:2000',
            'upsellRows' => 'array',
            'upsellRows.*.service_id' => 'required|exists:services,id',
            'upsellRows.*.staff_member_id' => 'nullable|exists:staff_members,id',
            'upsellRows.*.amount' => 'required|string',
        ], [
            'upsellRows.*.service_id.required' => 'Chọn dịch vụ cho dòng upsell.',
            'upsellRows.*.amount.required' => 'Nhập số tiền cho dòng upsell.',
        ], ['name' => 'tên khách hàng', 'phone' => 'SĐT', 'received_date' => 'ngày', 'sourceGroup' => 'nhóm nguồn']);

        // Nhóm 2 (BOD/SA/BA) — sale nhận trực tiếp, không qua duyệt.
        // Sale nhân viên không có quyền chia số → tự động nhận lead do chính họ up.
        if (in_array($this->sourceGroup, [Lead::SOURCE_BOD, Lead::SOURCE_SA, Lead::SOURCE_BA], true) && ! $this->personId) {
            if (! auth()->user()->hasPermission('lead.distribute')) {
                $this->personId = auth()->id();
            } else {
                $this->addError('personId', 'Nguồn ' . (Lead::SOURCE_GROUPS[$this->sourceGroup] ?? '') . ': bắt buộc chọn sale nhận.');
                return;
            }
        }

        if ($this->personId && ! $this->assignableUserIds()->contains($this->personId)) {
            $this->addError('personId', 'Không thể chia cho nhân sự này.');
            return;
        }
        if (! $this->personId && str_starts_with($this->poolTarget, 'org:')
            && ! in_array((int) substr($this->poolTarget, 4), auth()->user()->visibleOrgUnitIds(), true)) {
            $this->addError('poolTarget', 'Phòng/team không nằm trong phạm vi của bạn.');
            return;
        }

        $cleanCustom = $this->validateCustomFields();
        if ($cleanCustom === null) {
            return;
        }

        $normalized = Lead::normalizePhone($this->phone);
        if (! $normalized) {
            $this->addError('phone', 'SĐT không hợp lệ (cần dạng 0XXXXXXXXX hoặc +84...).');
            return;
        }

        $existing = Lead::where('phone', $normalized)
            ->when($this->lead, fn ($q) => $q->where('id', '!=', $this->lead->id))
            ->first();
        if ($existing) {
            $this->duplicateLeadId = $existing->isVisibleTo(auth()->user()) ? $existing->id : null;
            $this->addError('phone', 'SĐT này đã tồn tại trong hệ thống — không tạo lead trùng.');
            return;
        }

        $attributes = [
            'name' => $this->name,
            'phone' => $normalized,
            'received_date' => $this->received_date,
            // Phase 6.20 — page/camp: giờ ghi vào lead_custom_values qua form Trường bổ sung
            'insight' => $this->insight ?: null,
            'link' => $this->link ?: null,
            'region' => $this->region ?: null,
            'status_1' => $this->status_1 ?: null,
            'status_2' => $this->status_2 ?: null,
            'note' => $this->note ?: null,
            'classification' => $this->classification,
            'booking_status' => $this->bookingStatus,
            'facility_id' => $this->facilityId ?: null,
            'doctor_id' => $this->doctorId ?: null,
            'consultant_1_id' => $this->consultant1Id ?: null,
            'consultant_2_id' => $this->consultant2Id ?: null,
            'consultant_3_id' => $this->consultant3Id ?: null,
            // Trường mới
            'birthday' => $this->birthday ?: null,
            'address' => $this->address ?: null,
            'medical_history' => $this->medical_history ?: null,
            'occupation' => $this->occupation ?: null,
            'service_name' => $this->service_name ?: null,
            'potential_service' => $this->potential_service ?: null,
            'source_group' => $this->sourceGroup,
            'approval_status' => $this->sourceGroup === Lead::SOURCE_WI ? Lead::APPROVAL_PENDING : Lead::APPROVAL_NONE,
        ];

        if ($this->lead) {
            $this->updateLead($attributes, $cleanCustom);
        } else {
            $this->createLead($attributes, $cleanCustom);
        }
    }

    private function createLead(array $attributes, array $cleanCustom): void
    {
        $user = auth()->user();
        $attributes['receiver_id'] = $user->id;
        // Người tạo lead (dù nhập tay hay import Excel) đều được coi là "người nhập"
        // để có scope xem lại data sau khi engine chia cho sale khác (giống flow
        // trực page import Excel — thuộc [[imported-by-scope]]).
        $attributes['imported_by'] = $user->id;
        $pool = $this->poolAttributes();
        $attributes = array_merge($attributes, $pool);

        // Phase 6.8 — set pipeline_phase/status theo source_group + owner (giống initialPipelineFor).
        [$phase, $status] = Lead::initialPipelineFor($this->sourceGroup, $pool['owner_id'] ?? null);
        $attributes['pipeline_phase'] = $phase;
        $attributes['pipeline_status'] = $status;

        $lead = Lead::create($attributes);
        $this->syncCustomValues($lead, $cleanCustom);
        $this->syncUpsells($lead);
        $this->syncTreatments($lead);
        $lead->load('customValues');
        $lead->generateCode();

        // Auto-chốt phase 1 sau khi tạo lead (system event, không cần perm — Trực Page
        // không có phase.close.new nhưng vẫn tạo được lead → auto close cho họ).
        \App\Models\LeadPhaseClosure::updateOrCreate(
            ['lead_id' => $lead->id, 'phase' => Lead::CF_PHASE_NEW],
            ['closed_by' => $user->id, 'closed_at' => now(), 'note' => 'Auto-close khi tạo lead']
        );
        $lead->update(['phase' => min(Lead::CF_PHASE_NEW + 1, 5)]);

        LeadStatusLog::record($lead, 'created', null, 'Nhập tay bởi ' . $user->name, $user->id);
        AuditLog::record('create', $lead);

        session()->flash('status', 'Đã tạo lead mới.');
        // Phase 6.21g — sau tạo mới, đưa thẳng vào form Edit (giao diện 7 phase)
        // thay vì trang chi tiết (bất tiện, phải bấm sang edit).
        if ($lead->canOpenEditForm($user)) {
            $this->redirectRoute('leads.edit', $lead);
        } elseif ($user->hasPermission('lead.view')) {
            $this->redirectRoute('leads.show', $lead);
        } else {
            $this->redirectRoute('leads.create');
        }
    }

    private function updateLead(array $attributes, array $cleanCustom): void
    {
        $lead = $this->lead;
        $user = auth()->user();

        foreach (['classification', 'status_1', 'status_2', 'note'] as $field) {
            $old = $lead->{$field};
            $new = $attributes[$field];
            if ($old !== $new) {
                LeadStatusLog::record($lead, $field, $old, $new, $user->id);
            }
        }

        $attributes = array_merge($attributes, $this->poolAttributes($lead));

        if (in_array('classification', array_keys($attributes)) && $attributes['classification'] !== $lead->classification) {
            $attributes['last_care_at'] = now();
        }

        // Handoff Booking↔Sale theo booking_status:
        // - Booking→Sale khi đổi sang "Đã đặt" (từ trạng thái khác).
        // - Sale→Booking khi đổi ngược sang "Chưa đặt". "Hẹn lại" giữ ở Team Sale (còn hiệu lực).
        if ($lead->booking_status !== 'booked' && $this->bookingStatus === 'booked') {
            $saleOrgId = $this->siblingByName($lead->org_unit_id, 'Team Booking', 'Team Sale');
            if ($saleOrgId) {
                $attributes['org_unit_id'] = $saleOrgId;
                $attributes['pool_level'] = Lead::POOL_TEAM;
                $attributes['owner_id'] = null;
                $attributes['assigned_at'] = null;
                LeadStatusLog::record($lead, 'handoff', 'booking', 'sale', $user->id);
            }
        } elseif ($lead->booking_status === 'booked' && $this->bookingStatus === 'not_booked') {
            $bookingOrgId = $this->siblingByName($lead->org_unit_id, 'Team Sale', 'Team Booking');
            if ($bookingOrgId) {
                $attributes['org_unit_id'] = $bookingOrgId;
                $attributes['pool_level'] = Lead::POOL_TEAM;
                $attributes['owner_id'] = null;
                $attributes['assigned_at'] = null;
                LeadStatusLog::record($lead, 'handoff', 'sale', 'booking', $user->id);
            }
        }

        $lead->update($attributes);
        $this->syncCustomValues($lead, $cleanCustom);
        $this->syncUpsells($lead);
        $this->syncTreatments($lead);
        $lead->load('customValues');
        $lead->generateCode();
        AuditLog::record('update', $lead);

        session()->flash('status', 'Đã cập nhật thông tin khách hàng.');
        // Phase 6.21g — ở lại form edit thay vì nhảy sang trang chi tiết.
        $this->redirectRoute('leads.edit', $lead);
    }

    /**
     * Tìm sibling có $targetName của node có $fromName cùng cha.
     * VD (23, 'Team Booking', 'Team Sale') → id của Team Sale cùng cha team-giang.
     */
    private function siblingByName(?int $fromOrgId, string $fromName, string $targetName): ?int
    {
        if (! $fromOrgId) {
            return null;
        }
        $node = \App\Models\OrgUnit::find($fromOrgId);
        if (! $node || $node->name !== $fromName || ! $node->parent_id) {
            return null;
        }

        return \App\Models\OrgUnit::where('parent_id', $node->parent_id)
            ->where('name', $targetName)
            ->value('id');
    }

    /** Thuộc tính owner/org/pool theo đích chia; $existing để giữ assigned_at khi owner không đổi. */
    private function poolAttributes(?Lead $existing = null): array
    {
        if ($this->personId) {
            return [
                'owner_id' => $this->personId,
                'org_unit_id' => $this->userOrgId($this->personId),
                'pool_level' => Lead::POOL_PERSONAL,
                'assigned_at' => ($existing && $existing->owner_id === $this->personId) ? $existing->assigned_at : now(),
            ];
        }
        if (str_starts_with($this->poolTarget, 'org:')) {
            return ['owner_id' => null, 'org_unit_id' => (int) substr($this->poolTarget, 4), 'pool_level' => Lead::POOL_TEAM, 'assigned_at' => null];
        }

        // Fallback: nguồn "sale nhận trực tiếp" mà user không có perm distribute
        // (VD Sale up WI hoặc BOD/SA/BA chưa auto-set personId) → auto về kho team của user thao tác, chờ CM team chia.
        // Khớp với hint text "Bước tiếp theo: chia về kho team, chờ CM team sale chia."
        if (in_array($this->sourceGroup, [Lead::SOURCE_WI, Lead::SOURCE_BOD, Lead::SOURCE_SA, Lead::SOURCE_BA], true)
            && ! auth()->user()->hasPermission('lead.distribute')) {
            $userTeamOrg = $this->userOrgId(auth()->id());
            if ($userTeamOrg) {
                return ['owner_id' => null, 'org_unit_id' => $userTeamOrg, 'pool_level' => Lead::POOL_TEAM, 'assigned_at' => null];
            }
        }

        return ['owner_id' => null, 'org_unit_id' => null, 'pool_level' => Lead::POOL_COMMON, 'assigned_at' => null];
    }

    /**
     * Phase 6.9 — Danh sách chuyên viên tư vấn gán được cho lead.
     * Rule: user active + có permission `lead.update` + assignment.org_unit ∈ subtree của lead.org_unit
     * (nếu lead chưa có org_unit → dùng scope của người thao tác) + visible với người thao tác.
     */
    private function consultantUsers()
    {
        $viewer = auth()->user();
        $visibleOrgIds = $viewer->visibleOrgUnitIds();

        // Xác định "subtree hợp lệ" cho chuyên viên tư vấn: dùng team sale ROOT chứa lead.
        // Với lead ở Team Tele (id=6, path /1/2/3/4/6/) → team sale sibling ở depth 3 (id=4 = Team Giang).
        // Consultant nằm trong subtree Team Giang → mở rộng root lên cấp depth 3 (Team owner).
        $rootOrg = $this->targetOrgUnit();
        $subtreeIds = [];
        if ($rootOrg) {
            // Nếu lead ở sub-team (depth 4+), lùi lên depth 3 (team owner) làm root.
            $effectiveRoot = $rootOrg;
            while ($effectiveRoot && $effectiveRoot->depth > 3) {
                $effectiveRoot = $effectiveRoot->parent;
            }
            if ($effectiveRoot) {
                $subtreeIds = \App\Models\OrgUnit::where('path', 'like', $effectiveRoot->path . '%')->pluck('id')->all();
            }
        }
        if ($subtreeIds === []) $subtreeIds = $visibleOrgIds;

        // Phase 6.21g — Nếu viewer là owner/receiver của lead (đang giữ), bỏ intersect scope
        // (họ có quyền chọn consultant cho lead của mình dù scope viewer hẹp).
        $isOwnerViewing = $this->lead?->exists && (
            $this->lead->owner_id === $viewer->id
            || $this->lead->receiver_id === $viewer->id
        );
        $allowedOrgIds = $isOwnerViewing || $viewer->hasPermission(Lead::CF_ROLLBACK_PERM)
            ? array_values($subtreeIds)
            : array_values(array_intersect($subtreeIds ?: [], $visibleOrgIds ?: []));

        if ($allowedOrgIds === []) {
            return collect();
        }

        return User::where('status', User::STATUS_ACTIVE)
            ->whereHas('assignments', fn ($q) => $q->effective()
                ->whereIn('org_unit_id', $allowedOrgIds)
                ->whereHas('role.permissions', fn ($qq) => $qq->where('key', 'lead.consult')))
            ->orderBy('name')
            ->get()
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])
            ->values()
            ->all();
    }

    /** Nhân sự có thể chia trực tiếp: trong phạm vi của người thao tác + chính mình. */
    private function assignableUsers()
    {
        $visibleOrgIds = auth()->user()->visibleOrgUnitIds();

        // Fix 2026-08-01: filter theo phase của lead — không được chia lead phase
        // Booking cho Sale hoặc phase Sale cho Tele. Cũng KHÔNG show CM (Tele/Sale),
        // vì CM là quản lý chia, không phải nhân viên nhận lead trực tiếp.
        //   Booking → whitelist role name "Team Tele"
        //   Sale    → whitelist role name "Sale" | "Team sale" | "Team sale ĐN"
        //     (mở rộng khi có role sale mới — bổ sung vào mảng $allowRoles).
        $allowRoles = null;
        if ($this->lead?->exists) {
            $allowRoles = $this->lead->pipeline_phase === Lead::PHASE_BOOKING
                ? ['Team Tele']
                : ['Sale', 'Team sale', 'Team sale ĐN'];
        }

        return User::where('status', User::STATUS_ACTIVE)
            ->where(fn ($q) => $q
                ->whereHas('assignments', fn ($qq) => $qq->effective()->when(
                    $visibleOrgIds !== [],
                    fn ($qqq) => $qqq->whereIn('org_unit_id', $visibleOrgIds)
                ))
                ->orWhere('id', auth()->id()))
            ->when($allowRoles, fn ($q) => $q->whereHas('assignments.role', fn ($qq) => $qq->whereIn('name', $allowRoles)))
            ->orderBy('name')
            ->get();
    }

    private function assignableUserIds()
    {
        return $this->assignableUsers()->pluck('id');
    }

    /** Phòng/team có thể đưa vào kho chung: các org trong phạm vi của người thao tác. */
    private function assignableOrgs()
    {
        $ids = auth()->user()->visibleOrgUnitIds();
        if ($ids === []) return collect();
        $orgs = OrgUnit::whereIn('id', $ids)->orderBy('path')->get();

        // Sort custom: HN → DN → HCM → khác. Giữ subtree order theo path bên trong mỗi branch.
        $branchPriority = ['branch-hn' => 1, 'branch-dn' => 2, 'branch-hcm' => 3];
        $orgsById = $orgs->keyBy('id');
        return $orgs->sortBy(function ($o) use ($branchPriority, $orgsById) {
            $priority = 0;
            foreach (array_filter(explode('/', $o->path)) as $pid) {
                $node = $orgsById->get((int) $pid);
                if ($node && $node->depth === 1) {
                    $priority = $branchPriority[$node->code] ?? 90;
                    break;
                }
            }
            return sprintf('%02d-%s', $priority, $o->path);
        })->values();
    }

    public function with(): array
    {
        // Phase 6.21g — lock rule:
        //   - Phase 6, 7 luôn lock (chưa build — chỉ cho xem).
        //   - Phase 1..5 đã có closure → lock cho user không có phase.rollback.
        // Bug 2026-08-01: bổ sung khoá theo perm — user không có quyền thao tác
        // phase đó (VD Admin cơ sở với phase 3) → lock luôn dù chưa có closure.
        $phaseLocked = [6 => true, 7 => true];
        $u = auth()->user();
        $canRollback = $u->hasPermission(Lead::CF_ROLLBACK_PERM);
        $canLogCallHere = $this->lead?->exists ? $this->lead->canLogCall($u) : true;
        $canLogBookingHere = $this->lead?->exists ? $this->lead->canLogBooking($u) : true;
        $canCheckinHere = $this->lead?->exists ? $this->lead->canCheckin($u) : true;
        if ($this->lead?->exists) {
            $closedPhases = $this->lead->phaseClosures->pluck('phase')->all();
            for ($p = 1; $p <= 5; $p++) {
                $lockedByClosure = in_array($p, $closedPhases, true) && ! $canRollback;
                $lockedByPerm = ($p === 3 && ! $canLogCallHere)
                    || ($p === 4 && ! $canLogBookingHere)
                    || ($p === 5 && ! $canCheckinHere);
                $phaseLocked[$p] = $lockedByClosure || $lockedByPerm;
            }
        } else {
            for ($p = 1; $p <= 5; $p++) $phaseLocked[$p] = false;
        }

        $users = $this->assignableUsers();

        // Phase 6.21g — Nếu đã chọn kho org:{id} → filter user thuộc org đó + subtree.
        if ($this->poolTarget && str_starts_with($this->poolTarget, 'org:')) {
            $orgId = (int) substr($this->poolTarget, 4);
            $root = OrgUnit::find($orgId);
            if ($root) {
                $subtreePath = rtrim($root->path, '/') . '/';
                $subtreeIds = OrgUnit::where(function ($q) use ($orgId, $subtreePath) {
                    $q->where('id', $orgId)->orWhere('path', 'like', $subtreePath . '%');
                })->pluck('id')->all();
                $users = $users->filter(fn ($u) => $u->assignments
                    ->pluck('org_unit_id')
                    ->intersect($subtreeIds)
                    ->isNotEmpty());
            }
        }

        $q = trim($this->personSearch);
        $results = ($q === '' ? $users : $users->filter(fn ($u) => str_contains(mb_strtolower($u->name), mb_strtolower($q))))
            ->take(15)->values();

        $facilities = Facility::with(['children' => fn ($q) => $q->active()])->roots()->active()->get();

        $allStaff = StaffMember::with('facility.parent')->active()->orderBy('name')->get();

        // Bác sĩ giữ từ staff_members (title, chuyển API booking sau).
        $staffTree = $facilities->map(fn ($fac) => [
            'name' => $fac->name,
            'depts' => $fac->children->map(fn ($dept) => [
                'id' => $dept->id,
                'name' => $dept->name,
                'doctors' => $allStaff->where('facility_id', $dept->id)->where('role', 'doctor')->map(fn ($s) => ['id' => $s->id, 'name' => $s->displayName()])->values()->all(),
            ])->all(),
        ])->all();

        // Phase 6.9 — Chuyên viên tư vấn = user có lead.update, trong subtree org_unit của lead
        // (kể cả lead chưa có org_unit_id → lấy trong scope của người thao tác), + visible cho user hiện tại.
        $consultantUsers = $this->consultantUsers();

        $assignedConsultantIds = array_filter([$this->consultant1Id, $this->consultant2Id, $this->consultant3Id]);
        // Nếu chuyên viên đã gán không nằm trong list (VD user chuyển team), vẫn phải hiển thị tên.
        $assignedConsultants = $assignedConsultantIds
            ? User::whereIn('id', $assignedConsultantIds)->get()->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values()
            : collect();

        return [
            'phaseLocked' => $phaseLocked,
            'assignableOrgs' => $this->assignableOrgs(),
            'personResults' => $results,
            'selectedPerson' => $this->personId ? $users->firstWhere('id', $this->personId) : null,
            'customFields' => CustomField::applicableTo($this->targetOrgUnit()),
            'facilities' => $facilities,
            'staffTree' => $staffTree,
            'allStaff' => $allStaff,
            'serviceTree' => $serviceTree = Service::whereNull('parent_id')->where('active', true)
                ->with(['children' => fn ($q) => $q->where('active', true)->orderBy('name')
                    ->with(['children' => fn ($q2) => $q2->where('active', true)->orderBy('name')])])
                ->orderBy('name')->get(),
            'svcTreeJson' => $serviceTree->map(function ($cat) {
                return [
                    'id' => $cat->id, 'name' => $cat->name, 'code' => $cat->code, 'is_cat' => true,
                    'children' => $cat->children->map(function ($child) {
                        $sub = $child->children;
                        if ($sub->isNotEmpty()) {
                            return [
                                'id' => $child->id, 'name' => $child->name, 'code' => $child->code, 'is_cat' => true,
                                'children' => $sub->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'code' => $s->code, 'is_cat' => false, 'children' => []])->values(),
                            ];
                        }
                        return ['id' => $child->id, 'name' => $child->name, 'code' => $child->code, 'is_cat' => false, 'children' => []];
                    })->values(),
                ];
            })->values()->toJson(),
            'assignedConsultants' => $assignedConsultants,
            'consultantUsers' => $consultantUsers,
        ];
    }
};
?>

<div x-data="{ extraConsultants: {{ $consultant3Id ? 2 : ($consultant2Id ? 1 : 0) }} }">
<?php
    $canDistribute = auth()->user()->hasPermission('lead.distribute');
    // Phase 6.20 — readonly mode cho user chỉ có lead.read_booking (Team booking).
    $canWrite = $lead
        ? $lead->canEditPersonalInfo(auth()->user())
        : auth()->user()->hasPermission('lead.create');
    $isReadonly = ! $canWrite;
    $canBookAction = $lead && $lead->canBookAction(auth()->user());
    // Nút Đặt booking chỉ dùng khi phase Booking + có perm; tính URL sang lara-sbooking.
    $bookingClinicUrl = null;
    $bookingServiceUrl = null;
    if ($canBookAction) {
        $_facility = $lead->facility;
        $_coSoSlug = $lead->resolvedBookingSlug();
        $_bookingBase = \App\Models\AppSetting::get('booking_url', config('services.booking.url'));
        if ($_coSoSlug) {
            $_query = http_build_query([
                'ho_ten' => $lead->name,
                'so_dien_thoai' => $lead->phone,
                'khach_ma' => $lead->code,
                'return_url' => route('leads.booking-callback', $lead),
            ]);
            $_base = rtrim($_bookingBase, '/') . '/' . $_coSoSlug;
            $bookingClinicUrl = $_base . '/tao-moi?' . $_query;
            $bookingServiceUrl = $_base . '/dat-lich-dich-vu?' . $_query;
        }
    }
?>
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
            <div class="text-sm text-ink/50 mb-1">
                @if (auth()->user()->hasPermission('lead.view'))
                    <a href="{{ route('leads.index') }}" class="hover:text-gold-600">Khách hàng</a>
                    <span class="mx-1">›</span>
                @endif
                @if ($lead)
                    <a href="{{ route('leads.show', $lead) }}" class="hover:text-gold-600">Chi tiết khách hàng</a>
                    <span class="mx-1">›</span>
                    <span class="text-gold-700 font-medium">Cập nhật</span>
                @else
                    <span class="text-gold-700 font-medium">Thêm mới</span>
                @endif
            </div>
            <h1 class="text-3xl font-bold mb-1">{{ $lead ? 'Cập nhật - ' . ($lead->name ?: 'Khách hàng') : 'Thêm Mới Khách Hàng' }}</h1>
            @if ($lead)
                @php
                    $isBooking = $lead->pipeline_phase === \App\Models\Lead::PHASE_BOOKING;
                    $isWaiting = $lead->pipeline_status === \App\Models\Lead::PSTATUS_WAITING;
                    $badgeClass = $isBooking
                        ? ($isWaiting ? 'bg-amber-100 text-amber-800 border-amber-200' : 'bg-blue-100 text-blue-800 border-blue-200')
                        : ($isWaiting ? 'bg-purple-100 text-purple-800 border-purple-200' : 'bg-green-100 text-green-800 border-green-200');
                @endphp
                @if ($lead->code)
                    <div class="font-mono text-sm text-gold-700 mt-1">{{ $lead->code }}</div>
                @endif
                <span class="inline-block mt-2 text-xs font-semibold px-2.5 py-1 rounded-full border {{ $badgeClass }}"
                      title="Phase & trạng thái lifecycle">
                    {{ $lead->pipelineLabel() }}
                </span>
            @else
                <p class="text-sm text-ink/60">Vui lòng điền đầy đủ thông tin để cập nhật vào hệ thống sales pipeline.</p>
            @endif
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <a href="{{ $lead ? route('leads.show', $lead) : (auth()->user()->hasPermission('lead.view') ? route('leads.index') : route('dashboard')) }}" class="text-sm font-semibold text-ink/60 border border-gold-200 px-5 py-2.5 rounded-md hover:bg-gold-50">Hủy</a>
            @if ($canBookAction)
                @if ($bookingClinicUrl)
                    <div x-data="{ open: false }" @click.outside="open = false" class="relative">
                        <button type="button" @click="open = !open"
                                class="flex items-center gap-2 text-sm font-semibold text-white bg-blue-600 hover:bg-blue-700 px-5 py-2.5 rounded-md"
                                title="Mở form đặt lịch bên hệ thống Booking">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3.75 8.25h16.5M4.5 6h15a.75.75 0 01.75.75v12a.75.75 0 01-.75.75h-15a.75.75 0 01-.75-.75v-12A.75.75 0 014.5 6z"/></svg>
                            Đặt booking
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
                        </button>
                        <div x-show="open" x-cloak x-transition class="absolute z-20 mt-1 right-0 w-56 bg-white border border-gold-200 rounded-lg shadow-card overflow-hidden">
                            <a href="{{ $bookingClinicUrl }}" target="_blank" rel="noopener" @click="open = false"
                               class="block px-4 py-2.5 text-sm text-ink/70 hover:bg-gold-50 border-b border-gold-100">🏥 Đặt phòng khám</a>
                            <a href="{{ $bookingServiceUrl }}" target="_blank" rel="noopener" @click="open = false"
                               class="block px-4 py-2.5 text-sm text-ink/70 hover:bg-gold-50">💆 Đặt dịch vụ</a>
                        </div>
                    </div>
                @else
                    <span class="flex items-center gap-2 text-sm font-semibold text-ink/40 border border-ink/10 px-5 py-2.5 rounded-md cursor-not-allowed"
                          title="Cơ sở '{{ $lead->facility?->name ?? 'chưa gán' }}' chưa map sang cơ sở bên Booking. Admin vào Cài đặt › Kết nối Booking để nhập slug.">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3.75 8.25h16.5M4.5 6h15a.75.75 0 01.75.75v12a.75.75 0 01-.75.75h-15a.75.75 0 01-.75-.75v-12A.75.75 0 014.5 6z"/></svg>
                        Đặt booking (chưa map cơ sở)
                    </span>
                @endif
            @endif
            @if (! $isReadonly)
                <button wire:click="save" class="bg-gold-600 hover:bg-gold-700 text-white font-semibold text-sm px-6 py-2.5 rounded-md">Lưu thông tin</button>
            @endif
        </div>
    </div>

    @if ($isReadonly)
        <div class="mb-5 bg-blue-50 border border-blue-200 rounded-lg px-4 py-3 text-sm text-blue-700 flex items-center gap-2">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>
            <span>Bạn đang xem ở chế độ chỉ đọc — không có quyền chỉnh sửa thông tin ở phase này. @if ($canBookAction)Có thể bấm nút <strong>Đặt booking</strong> để chuyển sang hệ thống đặt lịch.@endif</span>
        </div>
    @endif

    @if ($errors->has('phone') && $duplicateLeadId)
        <div class="mb-5 bg-red-50 border border-red-200 rounded-lg px-4 py-3 text-sm text-red-700">
            SĐT đã tồn tại — <a href="{{ route('leads.show', $duplicateLeadId) }}" class="font-semibold underline">mở lead hiện có</a> để cập nhật thay vì tạo mới.
        </div>
    @endif

    {{-- Phase 6.21g — hiển thị tổng errors ở đầu form để user thấy dù đang ở tab nào --}}
    @if ($errors->any())
        <div class="mb-5 bg-red-50 border border-red-300 rounded-lg px-4 py-3 text-sm text-red-800">
            <div class="font-bold mb-1">⚠️ Không thể lưu — sửa các lỗi sau:</div>
            <ul class="list-disc pl-5 space-y-0.5 text-xs">
                @foreach ($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <fieldset {{ $isReadonly ? 'disabled' : '' }} class="min-w-0 border-0 p-0 m-0 {{ $isReadonly ? 'opacity-80' : '' }}">
    {{-- Phase 6.21d — layout tab-driven (đúng mockup): các card wrap theo x-show phase, chỉ 1 x-data outer --}}
    <div class="space-y-4" x-data="{
        phase: @entangle('activePhase').live,
        cfLocked: @js($phaseLocked ?? [])
    }">
        @php
            $cfPhases = \App\Models\Lead::CF_PHASE_LABELS;
            $cfStartPhase = $lead?->exists ? $lead->startPhase() : (($sourceGroup && isset(\App\Models\Lead::CF_START_PHASE_BY_SOURCE[$sourceGroup])) ? \App\Models\Lead::CF_START_PHASE_BY_SOURCE[$sourceGroup] : 1);
            $cfOpenFrom = $lead?->exists ? $lead->openFrom() : 1;
            $cfClosures = $lead?->exists ? $lead->phaseClosures->keyBy('phase') : collect();
        @endphp

        {{-- Header khách hàng — LUÔN HIỆN trên đầu (theo mockup) --}}
        <div class="bg-white border border-gold-200 rounded-xl shadow-card p-5">
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <div class="flex items-center gap-2 mb-1">
                        <span class="text-xs text-ink/50 font-mono">{{ $lead?->code ?? 'KH mới — chưa có mã' }}</span>
                        @if ($lead?->exists)
                            @if ($lead->is_first_visit)
                                <span class="text-[10px] px-2 py-0.5 rounded bg-slate-100 text-slate-600 font-semibold uppercase">Đến lần đầu</span>
                            @else
                                <span class="text-[10px] px-2 py-0.5 rounded bg-purple-100 text-purple-700 font-semibold uppercase">Khách quay lại</span>
                            @endif
                        @endif
                    </div>
                    <h1 class="text-xl font-bold text-ink/90">{{ $name ?: '— Chưa có tên —' }}</h1>
                    <div class="text-sm text-ink/60 mt-1">
                        @if ($phone) SĐT: <b>{{ $phone }}</b> @endif
                        @if ($birthday) · Sinh: {{ \Carbon\Carbon::parse($birthday)->format('d/m/Y') }} @endif
                        @if ($lead?->orgUnit) · Cơ sở: {{ $lead->orgUnit->name }} @endif
                        @if ($sourceGroup) · Nguồn: <b>{{ \App\Models\Lead::SOURCE_GROUP_CODES[$sourceGroup] ?? $sourceGroup }}</b> @endif
                    </div>
                </div>
                <div class="text-right space-y-1 min-w-[280px]">
                    <div class="text-xs text-ink/50 uppercase tracking-wide font-semibold">Trạng thái pipeline</div>
                    @if ($lead?->exists)
                        @if ($lead->isBulkOpen())
                            <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-blue-50 border border-blue-200 text-blue-800 text-sm font-semibold">
                                Đang nhập phase {{ $lead->openFrom() }}→{{ $lead->startPhase() }} (cần điền)
                            </div>
                        @else
                            <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-blue-50 border border-blue-200 text-blue-800 text-sm font-semibold">
                                {{ $lead->customerFlowLabel() }}
                            </div>
                        @endif
                        @php
                            // Người nhập lead: imported_by, fallback receiver_id (data cũ chưa có imported_by).
                            $inpUserId = $lead->imported_by ?: $lead->receiver_id;
                            $inpBy = $inpUserId ? \App\Models\User::find($inpUserId)?->name : null;

                            // Người phụ trách tele: theo priority
                            //   1) closer phase 3 (nếu đã chốt)
                            //   2) user call_log gần nhất
                            //   3) owner_id nếu lead đang ở phase Tele (pipeline_phase='booking' hoặc phase=3)
                            $teleUserId = $lead->phaseClosures->firstWhere('phase', 3)?->closed_by
                                ?? $lead->callLogs()->latest('called_at')->value('user_id');
                            if (! $teleUserId && ($lead->pipeline_phase === 'booking' || (int) $lead->phase === 3)) {
                                $teleUserId = $lead->owner_id;
                            }
                            $teleName = $teleUserId ? \App\Models\User::find($teleUserId)?->name : null;

                            // Người phụ trách tư vấn (Sale):
                            //   1) closer phase 4
                            //   2) owner_id nếu lead đã sang Sale (pipeline_phase='sale' hoặc phase>=4)
                            $svUserId = $lead->phaseClosures->firstWhere('phase', 4)?->closed_by;
                            if (! $svUserId && ($lead->pipeline_phase === 'sale' || (int) $lead->phase >= 4)) {
                                $svUserId = $lead->owner_id;
                            }
                            $svName = $svUserId ? \App\Models\User::find($svUserId)?->name : null;
                        @endphp
                        <div class="text-xs text-ink/60 space-y-0.5 mt-2 text-right">
                            <div>Người nhập lead: <b class="text-ink/80">{{ $inpBy ?? '—' }}</b></div>
                            <div>Người phụ trách tele: <b class="text-ink/80">{{ $teleName ?? '—' }}</b></div>
                            <div>Người phụ trách tư vấn: <b class="text-ink/80">{{ $svName ?? '—' }}</b></div>
                        </div>
                    @else
                        <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-slate-50 border border-slate-200 text-slate-600 text-sm font-semibold">
                            Chưa tạo lead
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Arrow-breadcrumb 7 phase — sizing lớn theo mockup (Phase 6.21e) --}}
        <div class="bg-white border border-gold-200 rounded-xl shadow-card p-5">
            <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
                <div class="text-sm uppercase tracking-wide text-gold-700 font-bold">Customer Flow — 7 phase</div>
                <div class="flex items-center gap-3 text-xs text-ink/60 flex-wrap">
                    <span class="inline-flex items-center gap-1.5"><span class="w-3.5 h-3.5 rounded bg-emerald-500"></span>Đã chốt</span>
                    <span class="inline-flex items-center gap-1.5"><span class="w-3.5 h-3.5 rounded bg-blue-400"></span>Cần điền thông tin</span>
                    <span class="inline-flex items-center gap-1.5"><span class="w-3.5 h-3.5 rounded bg-slate-200 border border-slate-300"></span>Chưa tới</span>
                    <span class="inline-flex items-center gap-1.5"><span class="w-3.5 h-3.5 rounded bg-slate-300 opacity-60"></span>Chưa build</span>
                </div>
            </div>
            @if (session('cf_ok'))<div class="mb-3 px-4 py-2 bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm rounded">✓ {{ session('cf_ok') }}</div>@endif
            @if (session('cf_error'))<div class="mb-3 px-4 py-2 bg-red-50 border border-red-200 text-red-800 text-sm rounded">✗ {{ session('cf_error') }}</div>@endif
            <div class="flex gap-1 overflow-x-auto pb-1">
                @foreach ($cfPhases as $idx => $label)
                    @php
                        $state = (in_array($idx, [6, 7], true))
    ? 'notbuilt'
    : ($cfClosures->has($idx)
        ? 'done'
        : (($idx <= $cfStartPhase || $idx == ($lead?->phase ?? 0)) ? 'open' : 'pending'));
                        $bg = match ($state) {
                            'done' => 'bg-emerald-500 text-white',
                            'current' => 'bg-blue-400 text-white',
                            'open' => 'bg-blue-400 text-white',
                            'pending' => 'bg-slate-200 text-slate-600',
                            'skipped' => 'bg-slate-100 text-slate-400 line-through cursor-not-allowed',
                            'notbuilt' => 'bg-slate-300 text-slate-500 opacity-60 cursor-not-allowed',
                        };
                        $disabled = in_array($state, ['skipped'], true);
                        $clip = $idx === 1 ? 'polygon(0 0,calc(100% - 14px) 0,100% 50%,calc(100% - 14px) 100%,0 100%)' : ($idx === 7 ? 'polygon(0 0,100% 0,100% 100%,0 100%,14px 50%)' : 'polygon(0 0,calc(100% - 14px) 0,100% 50%,calc(100% - 14px) 100%,0 100%,14px 50%)');
                    @endphp
                    <button type="button"
                            @if (! $disabled) wire:click="selectPhaseTab({{ $idx }})" @endif
                            :class="phase === {{ $idx }} ? 'ring-2 ring-offset-1 ring-indigo-500' : ''"
                            @if ($disabled) disabled @endif
                            style="clip-path: {{ $clip }};"
                            class="flex-1 min-w-[150px] px-5 py-3 text-left transition {{ $bg }}">
                        <div class="text-[10px] uppercase opacity-80 leading-none tracking-wide">Phase {{ $idx }}</div>
                        <div class="text-sm font-semibold mt-1 leading-tight">{{ $label }}</div>
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Tabbar horizontal — chung màu vàng, active gạch chân --}}
        <div class="flex flex-wrap items-center border-b border-gold-200 bg-white rounded-t-lg">
            @foreach ($cfPhases as $idx => $label)
                @php $state = (in_array($idx, [6, 7], true))
    ? 'notbuilt'
    : ($cfClosures->has($idx)
        ? 'done'
        : (($idx <= $cfStartPhase || $idx == ($lead?->phase ?? 0)) ? 'open' : 'pending')); @endphp
                <button type="button" wire:click="selectPhaseTab({{ $idx }})"
                        :class="phase === {{ $idx }} ? 'border-b-2 border-gold-700 font-bold' : 'border-b-2 border-transparent font-medium'"
                        class="px-5 py-3 -mb-px text-base text-gold-700 whitespace-nowrap transition-colors hover:font-bold">
                    {{ $idx }}. {{ $label }}
                    @if ($state === 'done')<span class="text-emerald-500 ml-1">✓</span>@endif
                    @if ($state === 'notbuilt')<span class="text-[11px] text-gold-500 ml-1">(chưa build)</span>@endif
                </button>
            @endforeach
        </div>

        {{-- Info khách + custom fields — Phase 1 (Thêm mới KH) --}}
        <div class="space-y-3" x-show="phase === 1" x-cloak>
            @if ($phaseLocked[1] ?? false)
                <div class="px-4 py-2.5 bg-slate-100 border border-slate-300 rounded-lg text-sm text-slate-700 flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
                    <span>Phase 1 đã chốt — thông tin khách hàng chỉ đọc. Chỉ Admin vận hành (perm <code>phase.rollback</code>) mới sửa được.</span>
                </div>
            @endif
            <fieldset @if ($phaseLocked[1] ?? false) disabled class="opacity-70 space-y-6 border-0 p-0 m-0" @else class="space-y-6 border-0 p-0 m-0" @endif>
            {{-- Thông tin khách hàng --}}
            <div class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
                <h2 class="font-bold text-gold-700 mb-5 flex items-center gap-2 flex-wrap">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg>
                    Thông tin khách hàng
                    @if ($lead?->exists && $lead->pipeline_phase)
                        <span class="ml-auto text-xs font-semibold px-2.5 py-1 rounded-full border
                            @if ($lead->pipeline_phase === \App\Models\Lead::PHASE_BOOKING) bg-blue-50 border-blue-200 text-blue-700
                            @else bg-gold-50 border-gold-200 text-gold-700 @endif"
                            title="Trạng thái pipeline hiện tại của lead">
                            {{ $lead->pipelineLabel() }}
                        </span>
                    @endif
                </h2>
                <div class="space-y-4">
                    {{-- Phase 6.21c — in ngang 4 cột (style AMIS) --}}
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1.5">Tên khách hàng <span class="text-red-500">*</span></label>
                            <input type="text" wire:model="name" placeholder="Nhập họ và tên" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500">
                            @error('name')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                            @if ($lead?->exists && ($lead->imported_by || $lead->receiver_id))
                                @php
                                    $_creator = \App\Models\User::find($lead->imported_by ?: $lead->receiver_id);
                                    $_createdAt = optional($lead->created_at)->format('d/m/Y H:i');
                                @endphp
                                <p class="text-[11px] text-ink/60 mt-1.5">
                                    Người tạo lead: <b class="text-ink/85">{{ $_creator?->name ?? '—' }}</b>
                                    @if ($_createdAt) · <span class="font-mono">{{ $_createdAt }}</span> @endif
                                </p>
                            @endif
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1.5">SĐT <span class="text-red-500">*</span></label>
                            <input type="text" wire:model="phone" placeholder="0xxx xxx xxx" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm font-mono focus:outline-none focus:border-gold-500">
                            @error('phone')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1.5">Ngày thu thập <span class="text-red-500">*</span></label>
                            <x-date-input field="received_date" />
                            @error('received_date')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1.5">Nhóm nguồn <span class="text-red-500">*</span></label>
                            @php
                                $_allowedSources = \App\Models\Lead::allowedSourceGroupsFor(auth()->user());
                                $_allSources = \App\Models\Lead::SOURCE_GROUPS;
                                $_sourceCodes = \App\Models\Lead::SOURCE_GROUP_CODES;
                            @endphp
                            <select wire:model.live="sourceGroup" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm bg-white focus:outline-none focus:border-gold-500">
                                <option value="">— Chọn nhóm nguồn —</option>
                                @foreach ($_allSources as $key => $label)
                                    @php
                                        $_code = $_sourceCodes[$key] ?? null;
                                        $_disabled = ! isset($_allowedSources[$key]);
                                        $_text = $label . ($_code ? ' (' . $_code . ')' : '') . ($_disabled ? ' - (không có quyền)' : '');
                                        $_title = $_disabled ? 'Bạn không có quyền up nguồn này' : '';
                                    @endphp
                                    <option value="{{ $key }}" {{ $_disabled ? 'disabled' : '' }} title="{{ $_title }}">{{ $_text }}</option>
                                @endforeach
                            </select>
                            @error('sourceGroup')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                            @php
                                $nextStep = null;
                                if (in_array($sourceGroup, [\App\Models\Lead::SOURCE_BOD, \App\Models\Lead::SOURCE_SA, \App\Models\Lead::SOURCE_BA], true)) {
                                    $nextStep = ! auth()->user()->hasPermission('lead.distribute')
                                        ? 'Lead sẽ tự động chia cho BẠN (' . auth()->user()->name . '). Không qua duyệt — nhập xong là xong.'
                                        : 'Chuyển sang tab "2. Chia số" để chọn sale nhận (bắt buộc, không qua duyệt).';
                                } elseif ($sourceGroup === \App\Models\Lead::SOURCE_WI) {
                                    $nextStep = 'Lead sẽ về kho team → chờ CM team sale chia cho nhân viên.';
                                } elseif (in_array($sourceGroup, [\App\Models\Lead::SOURCE_MKT, \App\Models\Lead::SOURCE_MKT_BR, \App\Models\Lead::SOURCE_BDM], true)) {
                                    $nextStep = 'Lead sẽ về kho team → chờ CM chia cho nhân viên booking.';
                                }
                            @endphp
                            @if ($nextStep)
                                <div class="mt-2 flex items-start gap-2 px-3 py-2 bg-amber-50 border-l-4 border-amber-400 rounded text-sm text-amber-900">
                                    <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    <span><b class="uppercase tracking-wide text-xs">Bước tiếp theo:</b> {{ $nextStep }}</span>
                                </div>
                            @endif
                        </div>
                    </div>
                    {{-- PAGE + Camp + Nguồn QC giờ là custom field phòng Marketing (Trường bổ sung); Link move sang tab Insight --}}
                    <div>
                        <label class="block text-sm font-medium mb-1.5">NOTE</label>
                        <textarea wire:model="note" rows="2" placeholder="Ghi chú thêm..." class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500"></textarea>
                    </div>
                </div>
            </div>

            {{-- Trường bổ sung — moved lên đây (Phase 6.14) --}}
            @if ($customFields->isNotEmpty())
            <div class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
                <h2 class="font-bold text-gold-700 mb-1 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.28z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    Trường bổ sung
                    <span class="text-xs font-normal text-ink/50">({{ $lead?->orgUnit?->name ?? $this->targetOrgUnit()?->name ?? 'mức công ty' }})</span>
                </h2>
                <p class="text-xs text-ink/50 mb-5">Trường có <span class="text-red-500">*</span> là bắt buộc.</p>
<?php $cfLabels = \App\Models\CustomField::labelMap($customFields); ?>
                <div class="space-y-4">
                    @foreach ($customFields as $field)
<?php $ck = $field->rules['code_kind'] ?? null; ?>
                        @continue($field->field_type === 'code' && $ck === 'fixed')
                        <div wire:key="cf-{{ $field->id }}">
                            <label class="block text-sm font-medium mb-1.5">
                                {{ $cfLabels[$field->id] ?? $field->label }}
                                @if ($field->required)<span class="text-red-500">*</span>@endif
                                @if ($field->affects_code)<span class="text-[10px] text-gold-700 ml-1">#mã KH</span>@endif
                                @if ($field->org_unit_id === null)
                                    <span class="text-[10px] uppercase tracking-wider text-ink/40 border border-gold-100 rounded px-1.5 py-0.5 ml-1">Công ty</span>
                                @endif
                            </label>
                            @if ($field->field_type === 'select' || ($field->field_type === 'code' && $ck === 'select'))
                                <select wire:model="custom.{{ $field->id }}" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm bg-white focus:outline-none focus:border-gold-500">
                                    <option value="">— chọn —</option>
                                    @foreach ($field->options ?? [] as $option)
<?php $ol = $field->optionLabel($option); ?>
                                        <option value="{{ $option }}">{{ ($ol !== '' && $ol !== $option) ? "$ol ($option)" : $option }}</option>
                                    @endforeach
                                </select>
                            @elseif ($field->field_type === 'tick')
                                <label class="inline-flex items-center gap-2 text-sm cursor-pointer select-none">
                                    <input type="checkbox" wire:model="custom.{{ $field->id }}" class="rounded border-gold-300 text-gold-600 focus:ring-gold-500 w-5 h-5">
                                    Có
                                </label>
                            @elseif ($field->field_type === 'date')
                                <x-date-input field="custom.{{ $field->id }}" />
                            @elseif ($field->field_type === 'number')
                                <input type="number" step="any" wire:model="custom.{{ $field->id }}" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500">
                            @elseif ($field->field_type === 'email')
                                <input type="email" wire:model="custom.{{ $field->id }}" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500">
                            @else
                                <input type="text" wire:model="custom.{{ $field->id }}" @if($field->field_type==='code') style="text-transform:uppercase" @endif class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500">
                            @endif
                            @error('custom.' . $field->id)<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- INSIGHT chuyển sang cột phải (tab) Phase 6.15 --}}
            </fieldset>
        </div>

        {{-- Content các tab-phase khác (2, 3, 4, 5, 6, 7) — nằm cùng x-data outer --}}
<?php $staffTreeJson = json_encode($staffTree, JSON_UNESCAPED_UNICODE); ?>
<?php $consultantUsersJson = json_encode($consultantUsers, JSON_UNESCAPED_UNICODE); ?>
        <script>
            window.__staffTree = {!! $staffTreeJson !!};
            window.__consultantUsers = {!! $consultantUsersJson !!};
        </script>
        {{-- Phase locked banner (reactive theo tab hiện tại) --}}
        <template x-if="cfLocked[phase]">
            <div class="px-4 py-2.5 bg-slate-100 border border-slate-300 rounded-lg text-sm text-slate-700 flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
                <span>Phase <span x-text="phase"></span> đã chốt — chỉ đọc. Chỉ Admin vận hành (perm <code>phase.rollback</code>) mới sửa được.</span>
            </div>
        </template>
        <fieldset :disabled="!!cfLocked[phase]" x-bind:class="cfLocked[phase] ? 'opacity-70 flex flex-col space-y-4 border-0 p-0 m-0' : 'flex flex-col space-y-4 border-0 p-0 m-0'">

            {{-- Arrow-breadcrumb + tabbar đã move lên đầu (dưới cùng x-data). Các phase blocks bên dưới đây. --}}

            {{-- ============= Phase 6.21i — Panel "Phân bổ nhân viên tư vấn" (Phase 4) — format giống Phase 2 ============= --}}
            @php
                $canAssignSale = auth()->user()->hasPermission('lead.distribute_sale')
                    || auth()->user()->hasPermission('phase.rollback')
                    || auth()->user()->hasPermission('lead.distribute');
                // Owner khi lead ở phase Booking = tele đang xử lý; khi phase Sale = sale.
                // Fix 2026-08-01: hiển thị đúng theo pipeline_phase, không quy tất cả về "sale".
                $inBookingPhase = $lead?->pipeline_phase === \App\Models\Lead::PHASE_BOOKING;
                $currentTele = $inBookingPhase ? $lead?->owner : null;
                $currentSale = $inBookingPhase ? null : $lead?->owner;
                // Fallback lịch sử: nếu phase 3 đã đóng nhưng owner đã sang sale → đọc closer.
                if (! $currentTele && $lead) {
                    $teleClosedBy = $lead->phaseClosures->firstWhere('phase', 3)?->closed_by;
                    $currentTele = $teleClosedBy ? \App\Models\User::find($teleClosedBy) : null;
                }
                // Team đang giữ: khi phase Booking, dùng team Tele mà tele viên thuộc về;
                // khi phase Sale, dùng org_unit_id hiện tại của lead.
                $holdingTeamName = $inBookingPhase
                    ? ($currentTele?->assignments->first()?->orgUnit?->name ?? $lead?->orgUnit?->name)
                    : ($lead?->orgUnit?->name);
            @endphp
            <div x-show="phase === 4" x-cloak class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
                <h2 class="font-bold text-gold-700 mb-5 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5"/></svg>
                    Phân bổ nhân viên tư vấn
                </h2>

                {{-- Info readonly 4 cột (format giống Phase 2) --}}
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 text-sm mb-4">
                    <div>
                        <label class="block text-xs font-medium text-ink/60 mb-1">Nguồn khách</label>
                        <div class="px-3 py-2 border border-gold-200 rounded-md bg-slate-50">{{ \App\Models\Lead::SOURCE_GROUPS[$sourceGroup] ?? '—' }}</div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-ink/60 mb-1">Team đang giữ</label>
                        <div class="px-3 py-2 border border-gold-200 rounded-md bg-slate-50">{{ $holdingTeamName ?? '—' }}</div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-ink/60 mb-1">Tele đã xử lý</label>
                        <div class="px-3 py-2 border border-gold-200 rounded-md {{ $currentTele ? 'bg-slate-50' : 'bg-slate-50 text-ink/50 italic' }}">{{ $currentTele?->name ?? '—' }}</div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-ink/60 mb-1">Sale phụ trách</label>
                        <div class="px-3 py-2 border border-gold-200 rounded-md {{ $currentSale ? 'bg-emerald-50 border-emerald-200 text-emerald-800 font-semibold' : 'bg-slate-50 text-ink/50 italic' }}">
                            {{ $currentSale?->name ?? '— chưa phân —' }}
                        </div>
                    </div>
                </div>

                {{-- Phase 4 rework 2026-08-01: Sale phụ trách = CV1 của booking mới nhất được duyệt.
                     Chọn CV ngay trong khung "Tạo booking" (Phase 4). --}}
                <div class="border-t border-gold-200 pt-4 text-xs text-ink/50 italic">
                    Chuyên viên tư vấn giờ chọn theo <b>từng lần booking</b> ở Phase 4. CV1 của booking được duyệt sẽ tự thành Sale phụ trách lead.
                </div>
            </div>

            {{-- ============= Phase 4 rework 2026-08-01 — LỊCH SỬ BOOKING (list record) ============= --}}
            {{-- Mỗi booking = 1 record chứa cơ sở/bác sĩ/dịch vụ/CV[]. Chờ duyệt lên đầu. --}}
            <div x-show="phase === 4" x-cloak class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
                <h2 class="font-bold text-gold-700 mb-5 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>
                    Lịch sử booking <span class="text-sm text-ink/50 font-normal">(mỗi lần đặt/đổi/hủy = 1 record — Chờ duyệt lên đầu)</span>
                </h2>
                @if ($lead?->exists && $lead->bookingLogs->isNotEmpty())
                    @php
                        $blOrder = [\App\Models\BookingLog::STATUS_CHO_XAC_NHAN => 0, \App\Models\BookingLog::STATUS_DA_XAC_NHAN => 1, \App\Models\BookingLog::STATUS_HUY_DOI_LICH => 2];
                        $bookingList = $lead->bookingLogs()
                            ->with(['user', 'facility.parent', 'doctor', 'service', 'consultants'])
                            ->get()
                            ->sort(function ($a, $b) use ($blOrder) {
                                $sa = $blOrder[$a->status] ?? 9;
                                $sb = $blOrder[$b->status] ?? 9;
                                if ($sa !== $sb) return $sa <=> $sb;
                                return ($b->scheduled_at?->timestamp ?? 0) <=> ($a->scheduled_at?->timestamp ?? 0);
                            })->values();
                    @endphp
                    <div class="border border-slate-200 rounded divide-y text-sm">
                        @foreach ($bookingList as $bl)
                            @php
                                $b = match ($bl->status) {
                                    'da_xac_nhan' => 'bg-emerald-100 text-emerald-700',
                                    'huy_doi_lich' => 'bg-red-100 text-red-700',
                                    default => 'bg-amber-100 text-amber-700 ring-1 ring-amber-300',
                                };
                                $tb = $bl->type === 'tham_kham' ? 'bg-sky-100 text-sky-700' : ($bl->type === 'dich_vu' ? 'bg-fuchsia-100 text-fuchsia-700' : 'bg-slate-100 text-slate-500');
                                $tlabel = $bl->type === 'tham_kham' ? '🩺 Thăm khám' : ($bl->type === 'dich_vu' ? '💆 Dịch vụ' : 'Chưa gán loại');
                                $facLabel = $bl->facility ? (($bl->facility->parent?->name ? $bl->facility->parent->name . ' › ' : '') . $bl->facility->name) : '—';
                            @endphp
                            <div class="p-3 space-y-1.5">
                                <div class="flex items-center flex-wrap gap-2">
                                    <span class="text-xs px-2 py-0.5 rounded whitespace-nowrap {{ $tb }}">{{ $tlabel }}</span>
                                    <span class="text-xs px-2 py-0.5 rounded whitespace-nowrap {{ $b }}">{{ $bl->statusLabel() }}</span>
                                    <span class="text-xs text-ink/50">Lịch: <b class="text-ink/80">{{ $bl->scheduled_at?->format('d/m/Y H:i') ?? 'chưa đặt' }}</b></span>
                                    <span class="text-xs text-ink/40 ml-auto">Người book: {{ $bl->user->name ?? 'system' }}</span>
                                </div>
                                <div class="text-xs text-ink/70 flex flex-wrap gap-x-4 gap-y-1">
                                    <span>🏥 <b>{{ $facLabel }}</b></span>
                                    <span>👨‍⚕️ BS: <b>{{ $bl->doctor->name ?? '—' }}</b></span>
                                    <span>💊 DV: <b>{{ $bl->service->name ?? '—' }}</b></span>
                                    <span>🧑‍💼 CV:
                                        @if ($bl->consultants->isNotEmpty())
                                            <b>{{ $bl->consultants->pluck('name')->implode(', ') }}</b>
                                        @else
                                            <span class="italic text-ink/40">—</span>
                                        @endif
                                    </span>
                                </div>
                                @if ($bl->note)<div class="text-ink/80 text-xs italic">📝 {{ $bl->note }}</div>@endif
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-xs text-ink/40 italic">Chưa có booking nào. Dùng khung "Tạo booking" bên dưới để tạo record mới.</p>
                @endif
            </div>

            {{-- INSIGHT — Phase 3 (Gọi điện) — order 3 (dưới cùng) --}}
            <div x-show="phase === 3" x-cloak class="order-3 bg-white border border-gold-200 rounded-xl shadow-card p-6">
                <h2 class="font-bold text-gold-700 mb-5 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18v-5.25m0 0a6.01 6.01 0 001.5-.189m-1.5.189a6.01 6.01 0 01-1.5-.189m3.75 7.478a12.06 12.06 0 01-4.5 0m3.75 2.383a14.406 14.406 0 01-3 0M14.25 18v-.192c0-.983.658-1.823 1.508-2.316a7.5 7.5 0 10-7.517 0c.85.493 1.509 1.333 1.509 2.316V18"/></svg>
                    INSIGHT
                </h2>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium mb-1.5">Ghi chú insight khách</label>
                        <textarea wire:model="insight" rows="2" placeholder="Ghi chú insight khách hàng..." class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500"></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1.5">Link</label>
                        <input type="text" wire:model="link" placeholder="https://facebook.com/..." class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500">
                        @error('link')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1.5">Ngày sinh</label>
                            <x-date-input field="birthday" />
                            @error('birthday')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1.5">Nghề nghiệp</label>
                            <input type="text" wire:model="occupation" placeholder="VD: Kinh doanh, Nội trợ..." class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1.5">Địa chỉ</label>
                        <input type="text" wire:model="address" placeholder="Địa chỉ khách hàng" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1.5">Khai thác tiền sử</label>
                        <textarea wire:model="medical_history" rows="2" placeholder="Bệnh lý, trước đó đã sử dụng dịch vụ gì, ở đâu,...?" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500"></textarea>
                    </div>
                </div>
            </div>

            {{-- Nhóm LIỆU TRÌNH — Phase 7 (Sử dụng DV) --}}
            <div x-show="phase === 7" x-cloak class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
                <div class="flex items-center justify-between mb-5">
                    <h2 class="font-bold text-gold-700 flex items-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>
                        LIỆU TRÌNH
                    </h2>
                    <button type="button" wire:click="addTreatmentRow"
                            class="inline-flex items-center gap-1.5 text-sm font-semibold text-white bg-gold-600 hover:bg-gold-700 px-3 py-1.5 rounded-md">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                        Thêm liệu trình
                    </button>
                </div>

                @if (empty($treatmentRows))
                    <p class="text-sm text-ink/40 italic">Chưa có lần liệu trình nào. Bấm "Thêm liệu trình" để bắt đầu.</p>
                @endif

                <div class="space-y-3">
                    @foreach ($treatmentRows as $idx => $row)
                        <div class="border border-gold-200 rounded-lg p-4 bg-gold-50/30 relative" wire:key="tr-{{ $idx }}">
                            <button type="button" wire:click="removeTreatmentRow({{ $idx }})"
                                    class="absolute top-2 right-2 text-xs font-semibold text-red-600 hover:text-red-800 inline-flex items-center gap-1"
                                    title="Xoá lần {{ $idx + 1 }}">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>

                            <div class="text-xs font-bold uppercase tracking-wider text-gold-700 mb-3">Lần {{ $idx + 1 }}</div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">
                                <div>
                                    <label class="block text-xs font-medium text-ink/60 mb-1">Ngày thực hiện</label>
                                    <x-date-input :field="'treatmentRows.' . $idx . '.performed_at'" />
                                    @error("treatmentRows.{$idx}.performed_at")<p class="text-xs text-red-600 mt-0.5">{{ $message }}</p>@enderror
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-ink/60 mb-1">Bác sĩ thực hiện</label>
                                    <select wire:model="treatmentRows.{{ $idx }}.performing_doctor_id"
                                            class="w-full border border-gold-200 rounded-md px-2 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                                        <option value="">— Chọn bác sĩ —</option>
                                        @foreach ($allStaff->where('role', 'doctor') as $doc)
                                            <option value="{{ $doc->id }}">{{ $doc->name }}{{ $doc->title ? ' — ' . $doc->title : '' }}</option>
                                        @endforeach
                                    </select>
                                    @error("treatmentRows.{$idx}.performing_doctor_id")<p class="text-xs text-red-600 mt-0.5">{{ $message }}</p>@enderror
                                </div>
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-ink/60 mb-1">Đánh giá chất lượng chuyên môn</label>
                                <textarea wire:model="treatmentRows.{{ $idx }}.quality_rating" rows="2"
                                          placeholder="Ghi nhận đánh giá lần này..."
                                          class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500"></textarea>
                                @error("treatmentRows.{$idx}.quality_rating")<p class="text-xs text-red-600 mt-0.5">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Trạng thái chăm sóc — Phase 3 (Gọi điện) — order 2 (giữa) --}}
            <div x-show="phase === 3" x-cloak class="order-2 bg-white border border-gold-200 rounded-xl shadow-card p-6 @if ($phaseLocked[3] ?? false) opacity-70 @endif">
                <h2 class="font-bold text-gold-700 mb-5 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Trạng thái chăm sóc
                    @if ($phaseLocked[3] ?? false)<span class="ml-2 text-xs px-2 py-0.5 rounded bg-slate-100 text-slate-500 font-normal">Chỉ đọc (không có quyền)</span>@endif
                </h2>

                <fieldset @if ($phaseLocked[3] ?? false) disabled @endif class="space-y-4 border-0 p-0 m-0">
                    <div>
                        <label class="block text-sm font-medium mb-1.5">Ghi nhận tình trạng lần 1</label>
                        <input type="text" wire:model="status_1" placeholder="VD: Đã liên hệ" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1.5">Ghi nhận tình trạng lần 2</label>
                        <input type="text" wire:model="status_2" placeholder="VD: Đã tư vấn" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1.5">PHÂN LOẠI KẾT QUẢ</label>
                        <select wire:model="classification" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm bg-white focus:outline-none focus:border-gold-500">
                            @foreach (\App\Models\Lead::CLASSIFICATIONS as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    {{-- TRẠNG THÁI ĐẶT LỊCH đã move sang Phase 4 (Booking) --}}

                    {{-- Panel Phân phối & Nguồn đã move sang tab Phase 2 (Chia số) — Phase 6.21g --}}
                </fieldset>
            </div>

            {{-- DV tiềm năng + UPSELL — Phase 6 (Bán hàng) --}}
            <div x-show="phase === 6" x-cloak class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
                <h2 class="font-bold text-gold-700 mb-5 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.281m5.94 2.28l-2.28 5.941"/></svg>
                    Dịch vụ tiềm năng & UPSELL
                </h2>
                <div class="space-y-4">
                    {{-- Dịch vụ tiềm năng — chọn từ danh mục services --}}
                    <div>
                        <label class="block text-sm font-medium mb-1.5">Dịch vụ tiềm năng</label>
                        <select wire:model="potential_service" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm bg-white focus:outline-none focus:border-gold-500">
                            <option value="">— Chọn dịch vụ —</option>
                            @foreach ($serviceTree as $cat)
                                <optgroup label="{{ $cat->name }}">
                                    @foreach ($cat->children as $child)
                                        @if ($child->children->isNotEmpty())
                                            @foreach ($child->children as $leaf)
                                                <option value="{{ $leaf->name }}">{{ $child->name }} · {{ $leaf->name }}</option>
                                            @endforeach
                                        @else
                                            <option value="{{ $child->name }}">{{ $child->name }}</option>
                                        @endif
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </div>

                    <div class="border-t border-gold-100 pt-4">
                        <div class="flex items-center justify-between mb-3">
                            <p class="text-xs font-bold uppercase tracking-wider text-ink/40">UPSELL — Doanh số phát sinh</p>
                            <button type="button" wire:click="addUpsellRow"
                                    class="inline-flex items-center gap-1.5 text-sm font-semibold text-gold-700 border border-gold-300 hover:bg-gold-50 px-3 py-1.5 rounded-md">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                                Upsell phát sinh
                            </button>
                        </div>
                        @error('upsellRows')<p class="text-xs text-red-600 mb-2">{{ $message }}</p>@enderror
                        <div class="space-y-3">
                            @foreach ($upsellRows as $idx => $row)
                                <div wire:key="upsell-{{ $idx }}" class="bg-gold-50/50 border border-gold-100 rounded-lg p-3">
                                    <div class="flex items-start gap-3">
                                        <div class="flex-1 space-y-2">
                                            <div class="grid grid-cols-2 gap-3">
                                                <div>
                                                    <label class="block text-xs font-medium text-ink/60 mb-1">Chuyên viên tư vấn</label>
                                                    <select wire:model="upsellRows.{{ $idx }}.staff_member_id"
                                                            class="w-full border border-gold-200 rounded-md px-2.5 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                                                        <option value="">— Chọn CVTV —</option>
                                                        @foreach ($assignedConsultants as $cv)
                                                            <option value="{{ $cv['id'] }}">{{ $cv['name'] }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                                <div>
                                                    <label class="block text-xs font-medium text-ink/60 mb-1">Số tiền (₫)</label>
                                                    <input type="text" wire:model="upsellRows.{{ $idx }}.amount" placeholder="0"
                                                           x-data x-on:input="$el.value = $el.value.replace(/\D/g, '').replace(/\B(?=(\d{3})+(?!\d))/g, '.')"
                                                           class="w-full border border-gold-200 rounded-md px-2.5 py-2 text-sm font-mono focus:outline-none focus:border-gold-500">
                                                    @error("upsellRows.{$idx}.amount")<p class="text-xs text-red-600 mt-0.5">{{ $message }}</p>@enderror
                                                </div>
                                            </div>
                                            <div x-data="{
                                                tree: {{ $svcTreeJson }},
                                                search: '', open: false, selected: null,
                                                modelValue: @entangle('upsellRows.' . $idx . '.service_id'),
                                                init() { this.resolveSelected(); this.$watch('modelValue', () => this.resolveSelected()); },
                                                resolveSelected() {
                                                    if (!this.modelValue) { this.selected = null; return; }
                                                    for (const c of this.tree) for (const ch of c.children) {
                                                        if (!ch.is_cat && ch.id == this.modelValue) { this.selected = ch.name; return; }
                                                        for (const s of (ch.children||[])) if (s.id == this.modelValue) { this.selected = s.name; return; }
                                                    }
                                                },
                                                get filtered() {
                                                    const q = this.search.toLowerCase().trim();
                                                    if (!q) return this.tree;
                                                    return this.tree.map(cat => {
                                                        const fc = cat.children.map(ch => {
                                                            if (ch.is_cat) { const fs = ch.children.filter(s => s.name.toLowerCase().includes(q)||(s.code&&s.code.toLowerCase().includes(q))); return fs.length?{...ch,children:fs}:null; }
                                                            return (ch.name.toLowerCase().includes(q)||(ch.code&&ch.code.toLowerCase().includes(q)))?ch:null;
                                                        }).filter(Boolean);
                                                        return fc.length?{...cat,children:fc}:null;
                                                    }).filter(Boolean);
                                                },
                                                pick(item) { this.modelValue = item.id; this.selected = item.name; this.search = ''; this.open = false; },
                                                clear() { this.modelValue = ''; this.selected = null; this.search = ''; }
                                            }">
                                                <label class="block text-xs font-medium text-ink/60 mb-1">Dịch vụ</label>
                                                <div class="relative">
                                                    <input type="text" x-model="search" @focus="open = true" @click="open = true"
                                                           :placeholder="selected ? selected : 'Tìm dịch vụ...'"
                                                           class="w-full border border-gold-200 rounded-md px-2.5 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                                                    <div x-show="open" @click.outside="open = false" x-cloak
                                                         class="absolute z-40 left-0 right-0 top-full mt-1 bg-white border border-gold-200 rounded-xl shadow-lg max-h-60 overflow-y-auto">
                                                        <template x-for="cat in filtered" :key="cat.id">
                                                            <div>
                                                                <div class="px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider text-ink/40 bg-gold-50 sticky top-0" x-text="cat.name"></div>
                                                                <template x-for="child in cat.children" :key="child.id">
                                                                    <div>
                                                                        <template x-if="child.is_cat">
                                                                            <div>
                                                                                <div class="pl-4 pr-3 py-1 text-[10px] font-semibold text-ink/50" x-text="child.name"></div>
                                                                                <template x-for="sub in child.children" :key="sub.id">
                                                                                    <button type="button" @click="pick(sub)"
                                                                                            class="w-full text-left pl-7 pr-3 py-1.5 text-sm hover:bg-gold-50">
                                                                                        <span x-text="sub.name"></span>
                                                                                    </button>
                                                                                </template>
                                                                            </div>
                                                                        </template>
                                                                        <template x-if="!child.is_cat">
                                                                            <button type="button" @click="pick(child)"
                                                                                    class="w-full text-left pl-4 pr-3 py-1.5 text-sm hover:bg-gold-50">
                                                                                <span x-text="child.name"></span>
                                                                            </button>
                                                                        </template>
                                                                    </div>
                                                                </template>
                                                            </div>
                                                        </template>
                                                        <div x-show="filtered.length === 0" class="px-3 py-3 text-sm text-ink/40 text-center">Không tìm thấy</div>
                                                    </div>
                                                </div>
                                                @error("upsellRows.{$idx}.service_id")<p class="text-xs text-red-600 mt-0.5">{{ $message }}</p>@enderror
                                            </div>
                                        </div>
                                        <button type="button" wire:click="removeUpsellRow({{ $idx }})"
                                                class="mt-5 text-red-400 hover:text-red-600 p-1" title="Xóa">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
                                        </button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        @if (empty($upsellRows))
                            <p class="text-sm text-ink/40 italic">Chưa có doanh số phát sinh. Bấm "+ Upsell phát sinh" để thêm.</p>
                        @endif
                    </div>
                </div>
            </div>

            {{-- "Phân phối & Nguồn" đã gộp vào tab Trạng thái (khi $canDistribute). --}}

            {{-- ============= Phase 6.21g — Section CHIA SỐ (Phase 2) — panel Phân phối động ============= --}}
            <div x-show="phase === 2" x-cloak class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
                <h2 class="font-bold text-gold-700 mb-5 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5"/></svg>
                    Chia số (Phân phối)
                </h2>

                {{-- Info readonly hiện tại --}}
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 text-sm mb-4">
                    <div>
                        <label class="block text-xs font-medium text-ink/60 mb-1">Nguồn khách</label>
                        <div class="px-3 py-2 border border-gold-200 rounded-md bg-slate-50">{{ \App\Models\Lead::SOURCE_GROUPS[$sourceGroup] ?? '—' }}</div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-ink/60 mb-1">Team đang giữ</label>
                        <div class="px-3 py-2 border border-gold-200 rounded-md bg-slate-50">{{ $lead?->orgUnit?->name ?? '— chưa chia —' }}</div>
                    </div>
                    @php
                        $_isBooking = $lead?->pipeline_phase === \App\Models\Lead::PHASE_BOOKING;
                        $_ownerLabel = $_isBooking ? 'Tele phụ trách' : 'Sale phụ trách';
                        $_importedName = $lead?->imported_by
                            ? \App\Models\User::find($lead->imported_by)?->name
                            : $lead?->receiver?->name;
                    @endphp
                    <div>
                        <label class="block text-xs font-medium text-ink/60 mb-1">{{ $_ownerLabel }}</label>
                        <div class="px-3 py-2 border border-gold-200 rounded-md bg-slate-50">{{ $lead?->owner?->name ?? '— chưa chia —' }}</div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-ink/60 mb-1">Người nhập</label>
                        <div class="px-3 py-2 border border-gold-200 rounded-md bg-slate-50">{{ $_importedName ?? '—' }}</div>
                    </div>
                </div>

                {{-- Form Phân phối — chỉ hiện khi user có perm chia --}}
                @if ($canDistribute)
                    <div class="border-t border-gold-200 pt-5">
                        <h3 class="font-bold text-gold-700 mb-4 flex items-center gap-2 text-sm">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5"/></svg>
                            Chia lead vào kho / cho sale
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            @if ($lead?->code)
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium mb-1.5">Mã khách hàng</label>
                                <p class="text-sm"><code class="font-mono text-gold-700">{{ $lead->code }}</code></p>
                            </div>
                            @endif
                            <div>
                                <label class="block text-sm font-medium mb-1.5">Chia số <span class="text-xs font-normal text-ink/50">(kho công ty / chi nhánh / địa điểm / team)</span></label>
                                <select wire:model.live="poolTarget" @disabled($selectedPerson) @class(['w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm bg-white focus:outline-none focus:border-gold-500', 'opacity-50 cursor-not-allowed' => $selectedPerson])>
                                    <option value="">— Chọn —</option>
                                    <option value="company">🏢 Công ty (kho chung)</option>
                                    @foreach ($assignableOrgs as $o)
                                        @php
                                            // Chỉ chia tới cấp Team owner (depth ≤ 3) — sub-team booking/tele/sale
                                            // (depth 4) là nội bộ, không cho chọn ở đây.
                                            if ($o->depth === 0 || $o->depth > 3) continue;
                                            $indent = str_repeat('　', $o->depth - 1);
                                            $prefix = match ($o->depth) {
                                                1 => '📍 Chi nhánh',
                                                2 => '🏬 Địa điểm',
                                                3 => '👥 Team',
                                            };
                                        @endphp
                                        <option value="org:{{ $o->id }}">{{ $indent }}{{ $prefix }}: {{ $o->name }}</option>
                                    @endforeach
                                </select>
                                <p class="text-xs text-ink/50 mt-1.5">
                                    @if ($selectedPerson)Đã gán nhân viên phụ trách → lead không nằm trong kho chung.
                                    @else Chọn kho nào thì nhân sự trong phạm vi kho đó thấy được lead. @endif
                                </p>
                                @error('poolTarget')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                            </div>
                            <div x-data="{ open: false }" @click.outside="open = false">
                                <label class="block text-sm font-medium mb-1.5">NHÂN VIÊN PHỤ TRÁCH</label>
                                @if ($selectedPerson)
                                    <div class="flex items-center justify-between gap-2 border border-gold-300 bg-gold-50 rounded-md px-3 py-2.5">
                                        <span class="text-sm font-semibold text-gold-800">{{ $selectedPerson->name }}</span>
                                        <button type="button" wire:click="clearPerson" class="text-xs font-semibold text-ink/50 hover:text-red-600">Bỏ chọn ✕</button>
                                    </div>
                                    <p class="text-xs text-ink/50 mt-1.5">Lead rời kho chung, chuyển vào kho cá nhân của nhân viên này.</p>
                                @else
                                    <div class="relative">
                                        <input type="text" wire:model.live.debounce.250ms="personSearch" @focus="open = true" placeholder="Gõ tên để tìm nhân sự..."
                                               class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500">
                                        <div x-show="open" x-cloak class="absolute z-20 mt-1 w-full max-h-56 overflow-y-auto bg-white border border-gold-200 rounded-lg shadow-card">
                                            @forelse ($personResults as $u)
                                                <button type="button" wire:click="selectPerson({{ $u->id }})" @click="open = false"
                                                        class="block w-full text-left px-3 py-2 text-sm hover:bg-gold-50">
                                                    {{ $u->name }}
                                                    <span class="text-xs text-ink/40">{{ $u->email }}</span>
                                                </button>
                                            @empty
                                                <p class="px-3 py-2 text-sm text-ink/40">Không tìm thấy nhân sự phù hợp.</p>
                                            @endforelse
                                        </div>
                                    </div>
                                    @error('personId')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                                @endif
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium mb-1.5">KHU VỰC</label>
                                <input type="text" wire:model="region" placeholder="VD: TP. Hồ Chí Minh" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500">
                            </div>
                        </div>
                        <p class="text-xs text-ink/50 mt-4 italic">Chọn xong bấm "Lưu thông tin khách hàng" ở footer để áp dụng. Sau đó bấm "Kết thúc phase 2" để chuyển sang phase Gọi điện.</p>
                    </div>
                @else
                    <div class="border-t border-gold-200 pt-4 text-sm text-ink/60 italic">
                        Bạn không có quyền chia số. Người có quyền (CM cơ sở / CM team / Admin) sẽ chia lead này.
                    </div>
                @endif
            </div>

            {{-- ============= Phase 6.21 — Section CALL LOGS (Phase 3) — order 1 (trên cùng) ============= --}}
            <div x-show="phase === 3" x-cloak class="order-1 bg-white border border-gold-200 rounded-xl shadow-card p-6">
                <h2 class="font-bold text-gold-700 mb-5 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/></svg>
                    Lịch sử cuộc gọi <span class="text-sm text-ink/50 font-normal">(mỗi lần gọi = 1 record)</span>
                </h2>
                @if ($lead?->exists)
                    @php
                        // Tele đang nắm: owner khi phase Booking. Sau khi handoff sang Sale
                        // (phase=sale), tele cũ = closer phase 3.
                        $_tele = $lead->pipeline_phase === \App\Models\Lead::PHASE_BOOKING
                            ? $lead->owner
                            : null;
                        if (! $_tele) {
                            $_teleId = $lead->phaseClosures->firstWhere('phase', 3)?->closed_by;
                            $_tele = $_teleId ? \App\Models\User::find($_teleId) : null;
                        }
                    @endphp
                    <div class="mb-4 p-3 bg-slate-50 border border-gold-200 rounded-md text-sm">
                        <span class="text-xs font-medium text-ink/60">Tele đang nắm:</span>
                        <b class="ml-2 {{ $_tele ? 'text-emerald-700' : 'text-ink/40 italic font-normal' }}">{{ $_tele?->name ?? '— chưa phân —' }}</b>
                    </div>
                @endif
                @if ($lead?->exists && $lead->callLogs->isNotEmpty())
                    <div class="border border-slate-200 rounded divide-y mb-4 text-sm">
                        @foreach ($lead->callLogs()->with('user')->orderByDesc('called_at')->get() as $cl)
                            <div class="p-3 flex items-start gap-3">
                                @php
                                    $b = match ($cl->status) {
                                        'thanh_cong' => 'bg-emerald-100 text-emerald-700',
                                        'that_bai' => 'bg-red-100 text-red-700',
                                        default => 'bg-slate-100 text-slate-600',
                                    };
                                @endphp
                                <span class="text-xs px-2 py-0.5 rounded whitespace-nowrap {{ $b }}">{{ $cl->statusLabel() }}</span>
                                <div class="flex-1">
                                    <div class="text-xs text-ink/50">{{ $cl->called_at->format('d/m/Y H:i') }} · {{ $cl->user->name ?? 'system' }}</div>
                                    @if ($cl->note)<div class="text-ink/80 mt-1">{{ $cl->note }}</div>@endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
                @if ($lead?->exists)
                    <div class="border border-dashed border-slate-300 bg-slate-50 rounded p-3 space-y-2">
                        <div class="text-xs font-semibold text-ink/60">Thêm cuộc gọi mới</div>
                        <div class="grid grid-cols-3 gap-2">
                            <select wire:model="newCallStatus" class="border border-slate-300 rounded px-2 py-1.5 text-sm">
                                @foreach (\App\Models\CallLog::STATUSES as $k => $lbl)
                                    <option value="{{ $k }}">{{ $lbl }}</option>
                                @endforeach
                            </select>
                            <input wire:model="newCallNote" placeholder="Ghi chú cuộc gọi..." class="col-span-2 border border-slate-300 rounded px-2 py-1.5 text-sm">
                        </div>
                        <button type="button" wire:click="addCallLog" class="text-sm bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-1.5 rounded">+ Ghi cuộc gọi</button>
                    </div>
                @else
                    <p class="text-xs text-ink/40 italic">Bấm "Lưu thông tin khách hàng" để tạo lead trước, rồi mới ghi được cuộc gọi.</p>
                @endif
            </div>

            {{-- ============= Phase 4 rework 2026-08-01 — GHI NHẬN BOOKING (log nội bộ) ============= --}}
            <div x-show="phase === 4" x-cloak class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
                <h2 class="font-bold text-gold-700 mb-2 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                    Ghi nhận booking <span class="text-sm text-ink/50 font-normal">(log nội bộ — tracking bên CRM)</span>
                </h2>
                <div class="mb-4 p-2.5 bg-blue-50 border border-blue-200 rounded text-xs text-blue-900 leading-relaxed">
                    ⚠️ Khung này chỉ <b>ghi log nội bộ</b> (cơ sở / bác sĩ / dịch vụ / CV cho từng lần định đặt) — <b>KHÔNG</b> đẩy sang hệ thống Booking.
                    Muốn tạo lịch thật (có phòng + khung giờ chuẩn), bấm nút <b>"Đặt booking"</b> màu xanh ở cuối form → mở lara-sbooking để đặt.
                </div>

                {{-- TRẠNG THÁI ĐẶT LỊCH (tổng thể) — readonly, tự sync từ booking_logs mới nhất --}}
                <div class="mb-4 pb-4 border-b border-slate-200">
                    <label class="block text-sm font-medium mb-1.5">TRẠNG THÁI ĐẶT LỊCH (tổng thể)</label>
                    <div class="inline-flex items-center gap-2 px-3 py-2 border border-gold-200 rounded-md bg-slate-50 text-sm min-w-[240px]">
                        <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                        <span class="font-semibold text-ink/80">{{ \App\Models\Lead::BOOKING_STATUSES[$bookingStatus] ?? $bookingStatus ?: 'Chưa đặt' }}</span>
                        <span class="text-xs text-ink/40 ml-auto">🔒 tự sync</span>
                    </div>
                    <p class="text-xs text-ink/50 mt-1">Tự cập nhật khi thêm booking mới hoặc bên hệ thống booking sync về — không chỉnh tay.</p>
                </div>

                @if ($lead?->exists)
                    <div class="flex items-center justify-between mb-2">
                        <span></span>
                        <button type="button" wire:click="syncBookingsFromExternal"
                                class="text-xs bg-blue-600 hover:bg-blue-700 text-white font-semibold px-3 py-1.5 rounded flex items-center gap-1.5">
                            🔄 Đồng bộ từ bên booking
                        </button>
                    </div>
                    <div class="border border-dashed border-slate-300 bg-slate-50 rounded p-3 space-y-2">
                        <div class="text-xs font-semibold text-ink/60">Thêm booking mới <span class="font-normal text-ink/40">— mặc định "Chờ xác nhận", bên booking cập nhật sẽ tự sync về đây</span></div>
                        {{-- Hàng 1: Loại | Trạng thái (lock) | Datetime --}}
                        <div class="grid grid-cols-2 md:grid-cols-3 gap-2">
                            <select wire:model.live="newBookingType" class="border border-slate-300 rounded px-2 py-1.5 text-sm">
                                <option value="">— Loại * —</option>
                                <option value="tham_kham">🩺 Thăm khám</option>
                                <option value="dich_vu">💆 Dịch vụ</option>
                            </select>
                            <div class="inline-flex items-center gap-1.5 border border-slate-300 rounded px-2 py-1.5 text-sm bg-slate-100 text-ink/60">
                                <span class="w-2 h-2 rounded-full bg-amber-400"></span>
                                Chờ xác nhận
                                <span class="ml-auto text-[10px]">🔒</span>
                            </div>
                            <input type="datetime-local" wire:model="newBookingScheduledAt" class="border border-slate-300 rounded px-2 py-1.5 text-sm">
                        </div>
                        {{-- Hàng 2: Cơ sở | Bác sĩ | Dịch vụ --}}
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
                            <select wire:model="newBookingFacilityId" class="border border-slate-300 rounded px-2 py-1.5 text-sm">
                                <option value="">— Cơ sở —</option>
                                @foreach ($facilities as $fac)
                                    <optgroup label="{{ $fac->name }}">
                                        @foreach ($fac->children as $dept)
                                            <option value="{{ $dept->id }}">{{ $fac->name }} › {{ $dept->name }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                            {{-- Phase A 2026-08-01: dropdown BS group Cơ sở > Phòng > BS, filter theo newBookingFacilityId. --}}
                            @php $__bsCurrent = $newBookingDoctorId ? ($allStaff->firstWhere('id', $newBookingDoctorId)) : null; @endphp
                            <div x-data="{
                                open: false,
                                search: '',
                                selectedId: {{ $newBookingDoctorId ?: 'null' }},
                                selectedName: @js($__bsCurrent?->displayName() ?? ''),
                                get hasSelection() { return this.selectedId != null && this.selectedId > 0; },
                                get filtered() {
                                    let q = this.search.toLowerCase().trim();
                                    let fid = parseInt($wire.newBookingFacilityId) || 0;
                                    let tree = window.__staffTree || [];
                                    // Nếu đã chọn cơ sở → chỉ hiện fac chứa dept đó, và chỉ dept đó.
                                    let base = fid
                                        ? tree.map(fac => ({...fac, depts: fac.depts.filter(d => d.id === fid)})).filter(fac => fac.depts.length > 0)
                                        : tree;
                                    if (!q) return base.map(f => ({...f, depts: f.depts.filter(d => (d.doctors || []).length > 0)})).filter(f => f.depts.length > 0);
                                    return base.map(fac => ({
                                        ...fac,
                                        depts: fac.depts.map(d => ({...d, doctors: (d.doctors || []).filter(s => s.name.toLowerCase().includes(q))})).filter(d => d.doctors.length > 0)
                                    })).filter(fac => fac.depts.length > 0);
                                },
                                pick(id, name) {
                                    this.selectedId = id; this.selectedName = name;
                                    this.open = false; this.search = '';
                                    $wire.set('newBookingDoctorId', id);
                                },
                                clear() {
                                    this.selectedId = null; this.selectedName = '';
                                    $wire.set('newBookingDoctorId', null);
                                }
                            }" @click.outside="open = false; search = ''" class="relative">
                                <div x-show="hasSelection" x-cloak class="flex items-center justify-between gap-2 border border-slate-300 bg-blue-50 rounded px-2 py-1.5 text-sm">
                                    <span class="font-medium text-ink/80 truncate" x-text="selectedName"></span>
                                    <button type="button" @click="clear()" class="text-xs text-ink/50 hover:text-red-600 shrink-0">✕</button>
                                </div>
                                <button x-show="!hasSelection" type="button" @click="open = !open"
                                        class="w-full flex items-center justify-between border border-slate-300 rounded px-2 py-1.5 text-sm text-ink/40 bg-white hover:border-slate-400">
                                    <span>— Bác sĩ —</span>
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
                                </button>
                                <div x-show="open" x-cloak class="absolute z-30 mt-1 w-full bg-white border border-slate-300 rounded-lg shadow-lg max-h-72 flex flex-col">
                                    <div class="p-2 border-b border-slate-100">
                                        <input type="text" x-model="search" placeholder="Nhập tên BS..." @keydown.escape="open = false; search = ''"
                                               class="w-full border border-slate-300 rounded px-2 py-1.5 text-sm focus:outline-none focus:border-blue-500">
                                    </div>
                                    <div class="overflow-y-auto flex-1 py-1">
                                        <template x-for="fac in filtered" :key="fac.name">
                                            <div>
                                                <div class="px-2 py-1 text-[10px] font-bold text-blue-700 uppercase tracking-wider bg-blue-50" x-text="'🏥 ' + fac.name"></div>
                                                <template x-for="dept in fac.depts" :key="dept.id">
                                                    <div>
                                                        <div class="px-3 py-1 text-[11px] font-semibold text-ink/60" x-text="'▸ ' + dept.name"></div>
                                                        <template x-for="s in dept.doctors" :key="s.id">
                                                            <button type="button" @click="pick(s.id, s.name)"
                                                                    class="block w-full text-left pl-6 pr-2 py-1 text-sm hover:bg-blue-50"
                                                                    :class="{'bg-blue-100 font-semibold text-blue-800': selectedId === s.id}">
                                                                <span x-text="s.name"></span>
                                                            </button>
                                                        </template>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>
                                        <template x-if="filtered.length === 0">
                                            <p class="px-3 py-2 text-sm text-ink/40 italic">Không tìm thấy BS phù hợp{{ '.' }}</p>
                                        </template>
                                    </div>
                                </div>
                            </div>
                            <select wire:model="newBookingServiceId" @disabled(! $newBookingType) class="border border-slate-300 rounded px-2 py-1.5 text-sm">
                                <option value="">
                                    {{ $newBookingType ? '— ' . ($newBookingType === 'tham_kham' ? 'Chọn thăm khám' : 'Chọn dịch vụ') . ' —' : '— Chọn loại trước —' }}
                                </option>
                                @if ($newBookingType)
                                    @foreach (\App\Models\Service::where('active',true)->where('service_type', $newBookingType)->orderBy('name')->get() as $s)
                                        <option value="{{ $s->id }}">{{ $s->name }}</option>
                                    @endforeach
                                @endif
                            </select>
                        </div>
                        {{-- Hàng 3: Chuyên viên tư vấn (multi) --}}
                        <div class="space-y-1.5">
                            <div class="text-xs font-semibold text-ink/60">Chuyên viên tư vấn <span class="font-normal text-ink/40">(có thể chọn nhiều — người đầu tiên = Sale phụ trách nếu booking được duyệt)</span></div>
                            @foreach ($newBookingConsultantIds as $cvIdx => $cvVal)
                                <div class="flex items-center gap-2" wire:key="new-cv-{{ $cvIdx }}">
                                    <span class="text-xs text-ink/50 w-6 text-right">#{{ $cvIdx + 1 }}</span>
                                    <select wire:model="newBookingConsultantIds.{{ $cvIdx }}" class="flex-1 border border-slate-300 rounded px-2 py-1.5 text-sm">
                                        <option value="">— Chọn CV —</option>
                                        @foreach ($consultantUsers ?? [] as $cu)
                                            <option value="{{ $cu['id'] }}">{{ $cu['name'] }}</option>
                                        @endforeach
                                    </select>
                                    @if (count($newBookingConsultantIds) > 1)
                                        <button type="button" wire:click="removeBookingConsultantSlot({{ $cvIdx }})" class="text-xs text-red-600 hover:text-red-800 px-2">✕</button>
                                    @endif
                                </div>
                            @endforeach
                            <button type="button" wire:click="addBookingConsultantSlot" class="text-xs font-semibold text-gold-700 hover:text-gold-800">+ Thêm CV</button>
                        </div>
                        <input wire:model="newBookingNote" placeholder="Ghi chú sau booking..." class="w-full border border-slate-300 rounded px-2 py-1.5 text-sm">
                        <button type="button" wire:click="addBookingLog" class="text-sm bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-1.5 rounded">+ Tạo booking</button>
                    </div>
                @else
                    <p class="text-xs text-ink/40 italic">Bấm "Lưu thông tin khách hàng" để tạo lead trước.</p>
                @endif
            </div>

            {{-- ============= Phase 6.21 — Section CHECK-IN (Phase 5) đúng field mockup ============= --}}
            <div x-show="phase === 5" x-cloak class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
                <h2 class="font-bold text-gold-700 mb-5 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
                    Check-in <span class="text-sm text-ink/50 font-normal">(tạm thời là bước cuối)</span>
                </h2>
                @if ($lead?->exists && $lead->phaseClosures->firstWhere('phase', 5))
                    @php $ci = $lead->phaseClosures->firstWhere('phase', 5); @endphp
                    <div class="p-3 bg-emerald-50 border border-emerald-200 rounded text-sm text-emerald-800 mb-4">
                        ✓ Đã check-in lúc {{ $ci->closed_at->format('d/m/Y H:i') }} bởi <b>{{ \App\Models\User::find($ci->closed_by)?->name ?? 'system' }}</b>
                        @if ($ci->note)<div class="text-xs text-emerald-700 mt-1">{{ $ci->note }}</div>@endif
                    </div>
                @else
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                        <div>
                            <label class="block text-xs font-medium text-ink/60 mb-1">Thời gian check-in</label>
                            <input type="datetime-local" wire:model="checkinTime" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-ink/60 mb-1">Lễ tân xử lý</label>
                            <select wire:model="checkinReceptionistId" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                                <option value="">— Chọn lễ tân —</option>
                                @foreach (\App\Models\User::orderBy('name')->limit(200)->get() as $u)
                                    <option value="{{ $u->id }}">{{ $u->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-ink/60 mb-1">Bác sĩ tiếp nhận</label>
                            <select wire:model="checkinDoctorId" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                                <option value="">— Chọn bác sĩ —</option>
                                @foreach (\App\Models\StaffMember::where('role','doctor')->where('active',true)->orderBy('name')->get() as $d)
                                    <option value="{{ $d->id }}">{{ $d->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="md:col-span-3">
                            <label class="block text-xs font-medium text-ink/60 mb-1">Ghi chú check-in</label>
                            <textarea wire:model="checkinNote" rows="2" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500" placeholder="VD: khách tới đúng giờ, mang theo hồ sơ..."></textarea>
                        </div>
                    </div>
                    <p class="text-xs text-ink/50 mt-3 italic">Nhập xong bấm "Kết thúc phase 5" ở footer — 4 field này sẽ gộp vào note của closure phase 5.</p>
                @endif
            </div>

            {{-- ============= Phase 6.21 — Section MARK RETURNING (Phase 5 done + first_visit) ============= --}}
            @if ($lead?->exists && (int) $lead->phase === 5 && $lead->is_first_visit && $lead->phaseClosures->firstWhere('phase', 5))
                <div class="bg-purple-50 border border-purple-200 rounded-xl p-4" x-show="phase === 5">
                    <div class="text-sm text-purple-800 mb-2 font-semibold">Khách quay lại thăm khám?</div>
                    <button type="button" wire:click="markReturning" onclick="return confirm('Reset lead về phase 3 (Gọi điện) cho lần khám mới? Lịch sử cũ vẫn giữ.')"
                            class="text-sm bg-purple-600 hover:bg-purple-700 text-white font-semibold px-4 py-1.5 rounded">
                        Khởi động lần thăm khám mới
                    </button>
                </div>
            @endif

            {{-- Trường bổ sung — đã chuyển lên dưới "Thông tin khách hàng" (Phase 6.14) --}}
        </fieldset>{{-- close phase-lock fieldset --}}
    </div>
    </fieldset>

    <div class="border-t border-gold-100 mt-6 pt-5 flex flex-wrap justify-end gap-3">
        <a href="{{ $lead ? route('leads.show', $lead) : (auth()->user()->hasPermission('lead.view') ? route('leads.index') : route('dashboard')) }}" class="text-sm font-semibold text-ink/60 border border-gold-200 px-5 py-2.5 rounded-md hover:bg-gold-50">Hủy</a>
        @if ($canBookAction && $bookingClinicUrl)
            <div x-data="{ open: false }" @click.outside="open = false" class="relative">
                <button type="button" @click="open = !open"
                        class="flex items-center gap-2 text-sm font-semibold text-white bg-blue-600 hover:bg-blue-700 px-5 py-2.5 rounded-md">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3.75 8.25h16.5M4.5 6h15a.75.75 0 01.75.75v12a.75.75 0 01-.75.75h-15a.75.75 0 01-.75-.75v-12A.75.75 0 014.5 6z"/></svg>
                    Đặt booking
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
                </button>
                <div x-show="open" x-cloak x-transition class="absolute z-20 mb-1 bottom-full right-0 w-56 bg-white border border-gold-200 rounded-lg shadow-card overflow-hidden">
                    <a href="{{ $bookingClinicUrl }}" target="_blank" rel="noopener" @click="open = false"
                       class="block px-4 py-2.5 text-sm text-ink/70 hover:bg-gold-50 border-b border-gold-100">🏥 Đặt phòng khám</a>
                    <a href="{{ $bookingServiceUrl }}" target="_blank" rel="noopener" @click="open = false"
                       class="block px-4 py-2.5 text-sm text-ink/70 hover:bg-gold-50">💆 Đặt dịch vụ</a>
                </div>
            </div>
        @endif
        @if (! $isReadonly)
            <button wire:click="save" class="bg-gold-600 hover:bg-gold-700 text-white font-semibold text-sm px-6 py-2.5 rounded-md">Lưu thông tin khách hàng</button>
        @endif

        {{-- ============= Phase 6.21 — Customer Flow action buttons ============= --}}
        @if ($lead?->exists && ! $isReadonly)
            @php
                $cfCurPhase = (int) $lead->phase;
                $cfIsBulk = $lead->isBulkOpen();
                $cfStart = $lead->startPhase();
                $cfOpen = $lead->openFrom();
                $cfCanRollback = auth()->user()->hasPermission(\App\Models\Lead::CF_ROLLBACK_PERM);
                $cfClosuresMap = $lead->phaseClosures->keyBy('phase');
            @endphp
            @if ($cfIsBulk && $activePhase >= $cfOpen && $activePhase <= $cfStart)
                <button type="button" wire:click="bulkSavePhases"
                        class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-sm px-5 py-2.5 rounded-md">
                    Lưu chốt {{ $cfStart - $cfOpen + 1 }} phase ({{ $cfOpen }}→{{ $cfStart }})
                </button>
            @elseif (! $cfIsBulk && $activePhase === $cfCurPhase && $activePhase <= 5)
                <button type="button" wire:click="closePhaseNow({{ $activePhase }})"
                        class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold text-sm px-5 py-2.5 rounded-md">
                    Kết thúc phase {{ $activePhase }}
                </button>
            @endif
            @if ($cfCanRollback && $activePhase < $cfCurPhase && $cfClosuresMap->has($activePhase))
                <button type="button" wire:click="rollbackToPhase({{ $activePhase }})"
                        onclick="return confirm('Lùi phase về {{ $activePhase }}? Xóa closure từ phase này trở đi.')"
                        class="bg-white border border-red-400 text-red-600 hover:bg-red-50 font-semibold text-sm px-5 py-2.5 rounded-md">
                    ⤺ Lùi phase {{ $activePhase }} (Admin)
                </button>
            @endif
        @endif
    </div>
</div>

