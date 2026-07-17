<?php

use App\Models\Assignment;
use App\Models\AuditLog;
use App\Models\CustomField;
use App\Models\Facility;
use App\Models\Lead;
use App\Models\LeadCustomValue;
use App\Models\LeadStatusLog;
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

    public string $page = '';

    public string $camp = '';

    public string $insight = '';

    public string $link = '';

    public string $ad_source = '';

    public string $region = '';

    // --- INSIGHT ---
    public string $birthday = '';
    public string $address = '';
    public string $medical_history = '';
    public string $occupation = '';

    // --- Dịch vụ ---
    public string $service_name = '';

    // --- LIỆU TRÌNH ---
    public string $treatment_1 = '';
    public string $treatment_2 = '';
    public string $treatment_3 = '';
    public string $treatment_4 = '';

    // --- Bác sĩ thực hiện ---
    public ?int $performingDoctorId = null;

    // --- Đánh giá CLCM ---
    public string $quality_rating = '';

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

    /** Phase 6.6 — 1 trong 6 nhóm nguồn (Marketing / Data lạnh / BDM / Bạn giới thiệu / CTV / Khách tự đến). */
    public string $sourceGroup = '';

    public ?int $facilityId = null;
    public ?int $doctorId = null;
    public ?int $consultant1Id = null;
    public ?int $consultant2Id = null;
    public ?int $consultant3Id = null;

    /** @var array<int, string> custom_field_id => value */
    public array $custom = [];

    public ?int $duplicateLeadId = null;

    public function mount(?Lead $lead = null): void
    {
        if ($lead?->exists) {
            abort_unless($lead->isVisibleTo(auth()->user()), 403);
            $this->lead = $lead;
            $this->fillFromLead($lead);
        } else {
            $this->received_date = now()->toDateString();
        }
    }

    private function fillFromLead(Lead $lead): void
    {
        $this->name = $lead->name;
        $this->phone = $lead->phone;
        $this->received_date = $lead->received_date->toDateString();
        $this->page = $lead->page ?? '';
        $this->camp = $lead->camp ?? '';
        $this->insight = $lead->insight ?? '';
        $this->link = $lead->link ?? '';
        $this->ad_source = $lead->ad_source ?? '';
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
        $this->treatment_1 = $lead->treatment_1?->toDateString() ?? '';
        $this->treatment_2 = $lead->treatment_2?->toDateString() ?? '';
        $this->treatment_3 = $lead->treatment_3?->toDateString() ?? '';
        $this->treatment_4 = $lead->treatment_4?->toDateString() ?? '';
        $this->performingDoctorId = $lead->performing_doctor_id;
        $this->quality_rating = $lead->quality_rating ?? '';
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
        $this->duplicateLeadId = null;

        $allowedSources = Lead::allowedSourceGroupsFor(auth()->user());
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
            'treatment_1' => 'nullable|date',
            'treatment_2' => 'nullable|date',
            'treatment_3' => 'nullable|date',
            'treatment_4' => 'nullable|date',
            'upsellRows' => 'array',
            'upsellRows.*.service_id' => 'required|exists:services,id',
            'upsellRows.*.staff_member_id' => 'nullable|exists:staff_members,id',
            'upsellRows.*.amount' => 'required|string',
        ], [
            'upsellRows.*.service_id.required' => 'Chọn dịch vụ cho dòng upsell.',
            'upsellRows.*.amount.required' => 'Nhập số tiền cho dòng upsell.',
        ], ['name' => 'tên khách hàng', 'phone' => 'SĐT', 'received_date' => 'ngày', 'sourceGroup' => 'nhóm nguồn']);

        // Bạn giới thiệu bắt buộc phải chọn sale nhận ngay (không qua duyệt).
        if ($this->sourceGroup === Lead::SOURCE_REFERRAL && ! $this->personId) {
            $this->addError('personId', 'Nguồn "Bạn giới thiệu": bắt buộc chọn sale nhận.');
            return;
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
            'page' => $this->page ?: null,
            'camp' => $this->camp ?: null,
            'insight' => $this->insight ?: null,
            'link' => $this->link ?: null,
            'ad_source' => $this->ad_source ?: null,
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
            'treatment_1' => $this->treatment_1 ?: null,
            'treatment_2' => $this->treatment_2 ?: null,
            'treatment_3' => $this->treatment_3 ?: null,
            'treatment_4' => $this->treatment_4 ?: null,
            'performing_doctor_id' => $this->performingDoctorId ?: null,
            'quality_rating' => $this->quality_rating ?: null,
            'potential_service' => $this->potential_service ?: null,
            'source_group' => $this->sourceGroup,
            'approval_status' => $this->sourceGroup === Lead::SOURCE_WALK_IN ? Lead::APPROVAL_PENDING : Lead::APPROVAL_NONE,
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
        $attributes = array_merge($attributes, $this->poolAttributes());

        $lead = Lead::create($attributes);
        $this->syncCustomValues($lead, $cleanCustom);
        $this->syncUpsells($lead);
        $lead->load('customValues');
        $lead->generateCode();

        LeadStatusLog::record($lead, 'created', null, 'Nhập tay bởi ' . $user->name, $user->id);
        AuditLog::record('create', $lead);

        session()->flash('status', 'Đã tạo lead mới.');
        if ($user->hasPermission('lead.view')) {
            $this->redirectRoute('leads.show', $lead);
        } else {
            // Team trực page: không có quyền xem → quay lại form tạo mới.
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
        $lead->load('customValues');
        $lead->generateCode();
        AuditLog::record('update', $lead);

        session()->flash('status', 'Đã cập nhật thông tin khách hàng.');
        $this->redirectRoute('leads.show', $lead);
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

        return ['owner_id' => null, 'org_unit_id' => null, 'pool_level' => Lead::POOL_COMMON, 'assigned_at' => null];
    }

    /** Nhân sự có thể chia trực tiếp: trong phạm vi của người thao tác + chính mình. */
    private function assignableUsers()
    {
        $visibleOrgIds = auth()->user()->visibleOrgUnitIds();

        return User::where('status', User::STATUS_ACTIVE)
            ->where(fn ($q) => $q
                ->whereHas('assignments', fn ($qq) => $qq->effective()->when(
                    $visibleOrgIds !== [],
                    fn ($qqq) => $qqq->whereIn('org_unit_id', $visibleOrgIds)
                ))
                ->orWhere('id', auth()->id()))
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

        return $ids === [] ? collect() : OrgUnit::whereIn('id', $ids)->orderBy('path')->get();
    }

    public function with(): array
    {
        $users = $this->assignableUsers();
        $q = trim($this->personSearch);
        $results = ($q === '' ? $users : $users->filter(fn ($u) => str_contains(mb_strtolower($u->name), mb_strtolower($q))))
            ->take(15)->values();

        $facilities = Facility::with(['children' => fn ($q) => $q->active()])->roots()->active()->get();

        $allStaff = StaffMember::with('facility.parent')->active()->orderBy('name')->get();

        $staffTree = $facilities->map(fn ($fac) => [
            'name' => $fac->name,
            'depts' => $fac->children->map(fn ($dept) => [
                'id' => $dept->id,
                'name' => $dept->name,
                'doctors' => $allStaff->where('facility_id', $dept->id)->where('role', 'doctor')->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values()->all(),
                'consultants' => $allStaff->where('facility_id', $dept->id)->where('role', 'consultant')->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values()->all(),
            ])->all(),
        ])->all();

        $assignedConsultantIds = array_filter([$this->consultant1Id, $this->consultant2Id, $this->consultant3Id]);
        $assignedConsultants = $assignedConsultantIds
            ? $allStaff->whereIn('id', $assignedConsultantIds)->values()
            : collect();

        return [
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
        ];
    }
};
?>

<div x-data="{ extraConsultants: {{ $consultant3Id ? 2 : ($consultant2Id ? 1 : 0) }} }">
<?php $canDistribute = auth()->user()->hasPermission('lead.distribute'); ?>
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="text-3xl font-bold mb-1">{{ $lead ? 'Cập nhật Khách Hàng' : 'Thêm Mới Khách Hàng' }}</h1>
            <p class="text-sm text-ink/60">Vui lòng điền đầy đủ thông tin để cập nhật vào hệ thống sales pipeline.</p>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ $lead ? route('leads.show', $lead) : (auth()->user()->hasPermission('lead.view') ? route('leads.index') : route('dashboard')) }}" class="text-sm font-semibold text-ink/60 border border-gold-200 px-5 py-2.5 rounded-md hover:bg-gold-50">Hủy</a>
            <button wire:click="save" class="bg-gold-600 hover:bg-gold-700 text-white font-semibold text-sm px-6 py-2.5 rounded-md">Lưu thông tin</button>
        </div>
    </div>

    @if ($errors->has('phone') && $duplicateLeadId)
        <div class="mb-5 bg-red-50 border border-red-200 rounded-lg px-4 py-3 text-sm text-red-700">
            SĐT đã tồn tại — <a href="{{ route('leads.show', $duplicateLeadId) }}" class="font-semibold underline">mở lead hiện có</a> để cập nhật thay vì tạo mới.
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
        {{-- CỘT TRÁI --}}
        <div class="space-y-6">
            {{-- Thông tin khách hàng --}}
            <div class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
                <h2 class="font-bold text-gold-700 mb-5 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg>
                    Thông tin khách hàng
                </h2>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium mb-1.5">Tên khách hàng <span class="text-red-500">*</span></label>
                        <input type="text" wire:model="name" placeholder="Nhập họ và tên" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500">
                        @error('name')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1.5">SĐT <span class="text-red-500">*</span></label>
                        <input type="text" wire:model="phone" placeholder="0xxx xxx xxx" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm font-mono focus:outline-none focus:border-gold-500">
                        @error('phone')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1.5">Ngày <span class="text-red-500">*</span></label>
                            <x-date-input field="received_date" />
                            @error('received_date')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1.5">Nhóm nguồn <span class="text-red-500">*</span></label>
                            @php($_allowedSources = \App\Models\Lead::allowedSourceGroupsFor(auth()->user()))
                            <select wire:model.live="sourceGroup" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm bg-white focus:outline-none focus:border-gold-500">
                                <option value="">— Chọn nhóm nguồn —</option>
                                @foreach ($_allowedSources as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('sourceGroup')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                            @if ($sourceGroup === \App\Models\Lead::SOURCE_REFERRAL)
                                <p class="text-xs text-amber-700 mt-1">Chọn sale nhận ở khối "Chia cho" bên dưới (bắt buộc).</p>
                            @elseif ($sourceGroup === \App\Models\Lead::SOURCE_WALK_IN)
                                <p class="text-xs text-amber-700 mt-1">Lead sẽ ở trạng thái "chờ CM cơ sở duyệt".</p>
                            @endif
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1.5">PAGE</label>
                            <input type="text" wire:model="page" placeholder="Tên fanpage" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500">
                        </div>
                        <div></div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1.5">Camp</label>
                            <input type="text" wire:model="camp" placeholder="Tên chiến dịch" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1.5">Nguồn QC</label>
                            <input type="text" wire:model="ad_source" placeholder="VD: Facebook, Google..." class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1.5">Link</label>
                        <input type="text" wire:model="link" placeholder="https://facebook.com/..." class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500">
                        @error('link')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1.5">Insight</label>
                        <textarea wire:model="insight" rows="2" placeholder="Ghi chú insight khách hàng..." class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500"></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1.5">NOTE</label>
                        <textarea wire:model="note" rows="2" placeholder="Ghi chú thêm..." class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500"></textarea>
                    </div>
                </div>
            </div>

            {{-- Nhóm INSIGHT --}}
            <div class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
                <h2 class="font-bold text-gold-700 mb-5 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18v-5.25m0 0a6.01 6.01 0 001.5-.189m-1.5.189a6.01 6.01 0 01-1.5-.189m3.75 7.478a12.06 12.06 0 01-4.5 0m3.75 2.383a14.406 14.406 0 01-3 0M14.25 18v-.192c0-.983.658-1.823 1.508-2.316a7.5 7.5 0 10-7.517 0c.85.493 1.509 1.333 1.509 2.316V18"/></svg>
                    INSIGHT
                </h2>
                <div class="space-y-4">
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

            {{-- DV tiềm năng + UPSELL --}}
            <div class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
                <h2 class="font-bold text-gold-700 mb-5 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.281m5.94 2.28l-2.28 5.941"/></svg>
                    Dịch vụ tiềm năng & UPSELL
                </h2>
                <div class="space-y-4">
                    {{-- Dịch vụ tiềm năng — searchable tree --}}
                    <div x-data="{
                        tree: {{ $svcTreeJson }},
                        search: '', open: false, selected: null,
                        modelValue: @entangle('potential_service'),
                        mode: 'name',
                        init() { this.resolveSelected(); this.$watch('modelValue', () => this.resolveSelected()); },
                        resolveSelected() {
                            if (!this.modelValue) { this.selected = null; return; }
                            for (const c of this.tree) for (const ch of c.children) {
                                if (!ch.is_cat && ch.name === this.modelValue) { this.selected = ch.name; return; }
                                for (const s of (ch.children||[])) if (s.name === this.modelValue) { this.selected = s.name; return; }
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
                        pick(item) { this.modelValue = item.name; this.selected = item.name; this.search = ''; this.open = false; },
                        clear() { this.modelValue = ''; this.selected = null; this.search = ''; }
                    }">
                        <label class="block text-sm font-medium mb-1.5">Dịch vụ tiềm năng</label>
                        <div class="relative">
                            <input type="text" x-model="search" @focus="open = true" @click="open = true"
                                   :placeholder="selected ? selected : 'Tìm kiếm dịch vụ...'"
                                   class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500">
                            <template x-if="modelValue">
                                <button type="button" @click="clear()" class="absolute right-2 top-1/2 -translate-y-1/2 text-ink/30 hover:text-red-500">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </template>
                            <div x-show="open" @click.outside="open = false" x-cloak
                                 class="absolute z-40 left-0 right-0 top-full mt-1 bg-white border border-gold-200 rounded-xl shadow-lg max-h-72 overflow-y-auto">
                                <template x-for="cat in filtered" :key="cat.id">
                                    <div>
                                        <div class="px-3 py-1.5 text-xs font-bold uppercase tracking-wider text-ink/40 bg-gold-50 sticky top-0" x-text="cat.name"></div>
                                        <template x-for="child in cat.children" :key="child.id">
                                            <div>
                                                <template x-if="child.is_cat">
                                                    <div>
                                                        <div class="pl-5 pr-3 py-1 text-xs font-semibold text-ink/50" x-text="child.name"></div>
                                                        <template x-for="sub in child.children" :key="sub.id">
                                                            <button type="button" @click="pick(sub)"
                                                                    class="w-full text-left pl-9 pr-3 py-1.5 text-sm hover:bg-gold-50 flex items-center justify-between">
                                                                <span x-text="sub.name"></span>
                                                                <span class="text-[10px] text-ink/30 font-mono" x-text="sub.code"></span>
                                                            </button>
                                                        </template>
                                                    </div>
                                                </template>
                                                <template x-if="!child.is_cat">
                                                    <button type="button" @click="pick(child)"
                                                            class="w-full text-left pl-5 pr-3 py-1.5 text-sm hover:bg-gold-50 flex items-center justify-between">
                                                        <span x-text="child.name"></span>
                                                        <span class="text-[10px] text-ink/30 font-mono" x-text="child.code"></span>
                                                    </button>
                                                </template>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                                <div x-show="filtered.length === 0" class="px-3 py-3 text-sm text-ink/40 text-center">Không tìm thấy dịch vụ</div>
                            </div>
                        </div>
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
                                                            <option value="{{ $cv->id }}">{{ $cv->name }}</option>
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

            {{-- Phân phối & Cơ sở — chỉ admin vận hành --}}
            @if ($canDistribute)
            <div class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
                <h2 class="font-bold text-gold-700 mb-5 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5"/></svg>
                    Phân phối & Nguồn
                </h2>
                <div class="space-y-4">
                    @if ($lead?->code)
                    <div>
                        <label class="block text-sm font-medium mb-1.5">Mã khách hàng</label>
                        <p class="text-sm mt-2"><code class="font-mono text-gold-700">{{ $lead->code }}</code></p>
                        <p class="text-xs text-ink/50 mt-1.5">Mã tự sinh theo trường phân loại của phòng.</p>
                    </div>
                    @endif
                    <div>
                        <label class="block text-sm font-medium mb-1.5">CHIA VÀO KHO</label>
                        <select wire:model.live="poolTarget" @disabled($selectedPerson) @class(['w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm bg-white focus:outline-none focus:border-gold-500', 'opacity-50 cursor-not-allowed' => $selectedPerson])>
                            <option value="">— Chọn —</option>
                            <option value="company">Kho chung công ty</option>
                            @if ($assignableOrgs->isNotEmpty())
                                <optgroup label="Kho chung phòng / team">
                                    @foreach ($assignableOrgs as $o)
                                        <option value="org:{{ $o->id }}">{{ str_repeat('— ', $o->depth) }}Kho chung {{ $o->name }}</option>
                                    @endforeach
                                </optgroup>
                            @endif
                        </select>
                        <p class="text-xs text-ink/50 mt-1.5">
                            @if ($selectedPerson)
                                Đã gán sale phụ trách → lead không nằm trong kho chung.
                            @else
                                Kho chung phòng/team: chỉ người trong phòng/team đó thấy được.
                            @endif
                        </p>
                        @error('poolTarget')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div x-data="{ open: false }" @click.outside="open = false">
                        <label class="block text-sm font-medium mb-1.5">SALE PHỤ TRÁCH</label>
                        @if ($selectedPerson)
                            <div class="flex items-center justify-between gap-2 border border-gold-300 bg-gold-50 rounded-md px-3 py-2.5">
                                <span class="text-sm font-semibold text-gold-800">{{ $selectedPerson->name }}</span>
                                <button type="button" wire:click="clearPerson" class="text-xs font-semibold text-ink/50 hover:text-red-600">Bỏ chọn ✕</button>
                            </div>
                            <p class="text-xs text-ink/50 mt-1.5">Lead rời kho chung, chuyển vào kho cá nhân của sale này.</p>
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
                    <div>
                        <label class="block text-sm font-medium mb-1.5">KHU VỰC</label>
                        <input type="text" wire:model="region" placeholder="VD: TP. Hồ Chí Minh" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500">
                    </div>
                </div>
            </div>
            @endif
        </div>

        {{-- CỘT PHẢI --}}
<?php $staffTreeJson = json_encode($staffTree, JSON_UNESCAPED_UNICODE); ?>
        <script>window.__staffTree = {!! $staffTreeJson !!};</script>
        <div class="space-y-6">
            {{-- Cơ sở & Nhân sự --}}
            <div class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
                <h2 class="font-bold text-gold-700 mb-5 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"/></svg>
                    Cơ sở & Nhân sự
                </h2>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium mb-1.5">CƠ SỞ</label>
                        <select wire:model.live="facilityId" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm bg-white focus:outline-none focus:border-gold-500">
                            <option value="">— Chọn cơ sở —</option>
                            @foreach ($facilities as $fac)
                                <optgroup label="{{ $fac->name }}">
                                    @foreach ($fac->children as $dept)
                                        <option value="{{ $dept->id }}">{{ $fac->name }} › {{ $dept->name }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </div>

                    @foreach ([
                        ['label' => 'BÁC SĨ TƯ VẤN', 'wireModel' => 'doctorId', 'role' => 'doctors', 'placeholder' => 'Chọn bác sĩ', 'current' => $doctorId, 'slot' => 0],
                        ['label' => 'CHUYÊN VIÊN TƯ VẤN 1', 'wireModel' => 'consultant1Id', 'role' => 'consultants', 'placeholder' => 'Chọn chuyên viên', 'current' => $consultant1Id, 'slot' => 0],
                        ['label' => 'CHUYÊN VIÊN TƯ VẤN 2', 'wireModel' => 'consultant2Id', 'role' => 'consultants', 'placeholder' => 'Chọn chuyên viên', 'current' => $consultant2Id, 'slot' => 1],
                        ['label' => 'CHUYÊN VIÊN TƯ VẤN 3', 'wireModel' => 'consultant3Id', 'role' => 'consultants', 'placeholder' => 'Chọn chuyên viên', 'current' => $consultant3Id, 'slot' => 2],
                    ] as $dd)
                        <div @if($dd['slot'] > 0) x-show="extraConsultants >= {{ $dd['slot'] }}" x-cloak @endif>
                            <label class="block text-sm font-medium mb-1.5">{{ $dd['label'] }}</label>
                            <div x-data="{
                                open: false,
                                search: '',
                                role: '{{ $dd['role'] }}',
                                selectedId: {{ $dd['current'] ?: 'null' }},
                                selectedName: '{{ $dd['current'] ? $allStaff->firstWhere('id', $dd['current'])?->name : '' }}',
                                get hasSelection() { return this.selectedId != null && this.selectedId > 0; },
                                get filtered() {
                                    let q = this.search.toLowerCase();
                                    let fid = parseInt($wire.facilityId) || 0;
                                    let tree = window.__staffTree;
                                    let base = q
                                        ? tree.map(fac => ({
                                            ...fac,
                                            depts: fac.depts.map(dept => ({
                                                ...dept,
                                                [this.role]: dept[this.role].filter(s => s.name.toLowerCase().includes(q))
                                            })).filter(dept => dept[this.role].length > 0)
                                        })).filter(fac => fac.depts.length > 0)
                                        : tree.filter(fac => fac.depts.some(d => d[this.role].length > 0));
                                    if (!fid) return base;
                                    let matched = base.filter(fac => fac.depts.some(d => d.id === fid));
                                    let rest = base.filter(fac => !fac.depts.some(d => d.id === fid));
                                    return [...matched, ...rest];
                                },
                                pick(id, name) {
                                    this.selectedId = id;
                                    this.selectedName = name;
                                    this.open = false;
                                    this.search = '';
                                    $wire.set('{{ $dd['wireModel'] }}', id);
                                },
                                clear() {
                                    this.selectedId = null;
                                    this.selectedName = '';
                                    $wire.set('{{ $dd['wireModel'] }}', null);
                                }
                            }" @click.outside="open = false; search = ''" class="relative">
                                <div x-show="hasSelection" x-cloak class="flex items-center justify-between gap-2 border border-gold-300 bg-gold-50 rounded-md px-3 py-2.5">
                                    <span class="text-sm font-semibold text-gold-800" x-text="selectedName"></span>
                                    <button type="button" @click="clear()" class="text-xs font-semibold text-ink/50 hover:text-red-600">✕</button>
                                </div>
                                <button x-show="!hasSelection" type="button" @click="open = !open"
                                        class="w-full flex items-center justify-between border border-gold-200 rounded-md px-3 py-2.5 text-sm text-ink/40 bg-white hover:border-gold-400">
                                    <span>— {{ $dd['placeholder'] }} —</span>
                                    <svg class="w-4 h-4 text-ink/30" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
                                </button>
                                <div x-show="open" x-cloak
                                     class="absolute z-30 mt-1 w-full bg-white border border-gold-200 rounded-lg shadow-lg max-h-72 flex flex-col">
                                    <div class="p-2 border-b border-gold-100">
                                        <input type="text" x-model="search" placeholder="Nhập tên..." @keydown.escape="open = false; search = ''"
                                               class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500" x-ref="searchInput">
                                    </div>
                                    <div class="overflow-y-auto flex-1 py-1">
                                        <template x-for="fac in filtered" :key="fac.name">
                                            <div>
                                                <div class="px-3 py-1.5 text-xs font-bold text-gold-700 uppercase tracking-wider bg-gold-50" x-text="fac.name"></div>
                                                <template x-for="dept in fac.depts" :key="dept.name">
                                                    <div>
                                                        <div class="px-5 py-1 text-xs font-semibold text-ink/50" x-text="dept.name"></div>
                                                        <template x-for="s in dept[role]" :key="s.id">
                                                            <button type="button" @click="pick(s.id, s.name)"
                                                                    class="block w-full text-left pl-8 pr-3 py-1.5 text-sm hover:bg-gold-50"
                                                                    :class="{'bg-gold-100 font-semibold text-gold-800': selectedId === s.id}">
                                                                <span x-text="s.name"></span>
                                                            </button>
                                                        </template>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>
                                        <template x-if="filtered.length === 0">
                                            <p class="px-3 py-2 text-sm text-ink/40">Không tìm thấy.</p>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach

                    <button x-show="extraConsultants < 2" type="button" @click="extraConsultants++"
                            class="inline-flex items-center gap-1.5 text-sm font-medium text-gold-700 hover:text-gold-800">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                        Thêm chuyên viên tư vấn
                    </button>

                    <div class="border-t border-gold-100 pt-4">
                        <label class="block text-sm font-medium mb-1.5">DỊCH VỤ</label>
                        <input type="text" wire:model="service_name" placeholder="Tên dịch vụ tổng" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500">
                    </div>
                </div>
            </div>

            {{-- Nhóm LIỆU TRÌNH --}}
            <div class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
                <h2 class="font-bold text-gold-700 mb-5 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>
                    LIỆU TRÌNH
                </h2>
                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1.5">Lần 1</label>
                            <x-date-input field="treatment_1" />
                            @error('treatment_1')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1.5">Lần 2</label>
                            <x-date-input field="treatment_2" />
                            @error('treatment_2')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1.5">Lần 3</label>
                            <x-date-input field="treatment_3" />
                            @error('treatment_3')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1.5">Lần 4</label>
                            <x-date-input field="treatment_4" />
                            @error('treatment_4')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <div class="border-t border-gold-100 pt-4">
                        {{-- Bác sĩ thực hiện — dùng cùng staffTree --}}
                        <label class="block text-sm font-medium mb-1.5">BÁC SĨ THỰC HIỆN</label>
                        <div x-data="{
                            open: false,
                            search: '',
                            role: 'doctors',
                            selectedId: {{ $performingDoctorId ?: 'null' }},
                            selectedName: '{{ $performingDoctorId ? $allStaff->firstWhere('id', $performingDoctorId)?->name : '' }}',
                            get hasSelection() { return this.selectedId != null && this.selectedId > 0; },
                            get filtered() {
                                let q = this.search.toLowerCase();
                                let tree = window.__staffTree;
                                return q
                                    ? tree.map(fac => ({
                                        ...fac,
                                        depts: fac.depts.map(dept => ({
                                            ...dept,
                                            doctors: dept.doctors.filter(s => s.name.toLowerCase().includes(q))
                                        })).filter(dept => dept.doctors.length > 0)
                                    })).filter(fac => fac.depts.length > 0)
                                    : tree.filter(fac => fac.depts.some(d => d.doctors.length > 0));
                            },
                            pick(id, name) {
                                this.selectedId = id;
                                this.selectedName = name;
                                this.open = false;
                                this.search = '';
                                $wire.set('performingDoctorId', id);
                            },
                            clear() {
                                this.selectedId = null;
                                this.selectedName = '';
                                $wire.set('performingDoctorId', null);
                            }
                        }" @click.outside="open = false; search = ''" class="relative">
                            <div x-show="hasSelection" x-cloak class="flex items-center justify-between gap-2 border border-gold-300 bg-gold-50 rounded-md px-3 py-2.5">
                                <span class="text-sm font-semibold text-gold-800" x-text="selectedName"></span>
                                <button type="button" @click="clear()" class="text-xs font-semibold text-ink/50 hover:text-red-600">✕</button>
                            </div>
                            <button x-show="!hasSelection" type="button" @click="open = !open"
                                    class="w-full flex items-center justify-between border border-gold-200 rounded-md px-3 py-2.5 text-sm text-ink/40 bg-white hover:border-gold-400">
                                <span>— Chọn bác sĩ thực hiện —</span>
                                <svg class="w-4 h-4 text-ink/30" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
                            </button>
                            <div x-show="open" x-cloak
                                 class="absolute z-30 mt-1 w-full bg-white border border-gold-200 rounded-lg shadow-lg max-h-72 flex flex-col">
                                <div class="p-2 border-b border-gold-100">
                                    <input type="text" x-model="search" placeholder="Nhập tên..." @keydown.escape="open = false; search = ''"
                                           class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500">
                                </div>
                                <div class="overflow-y-auto flex-1 py-1">
                                    <template x-for="fac in filtered" :key="fac.name">
                                        <div>
                                            <div class="px-3 py-1.5 text-xs font-bold text-gold-700 uppercase tracking-wider bg-gold-50" x-text="fac.name"></div>
                                            <template x-for="dept in fac.depts" :key="dept.name">
                                                <div>
                                                    <div class="px-5 py-1 text-xs font-semibold text-ink/50" x-text="dept.name"></div>
                                                    <template x-for="s in dept.doctors" :key="s.id">
                                                        <button type="button" @click="pick(s.id, s.name)"
                                                                class="block w-full text-left pl-8 pr-3 py-1.5 text-sm hover:bg-gold-50"
                                                                :class="{'bg-gold-100 font-semibold text-gold-800': selectedId === s.id}">
                                                            <span x-text="s.name"></span>
                                                        </button>
                                                    </template>
                                                </div>
                                            </template>
                                        </div>
                                    </template>
                                    <template x-if="filtered.length === 0">
                                        <p class="px-3 py-2 text-sm text-ink/40">Không tìm thấy.</p>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1.5">Đánh giá chất lượng chuyên môn</label>
                        <textarea wire:model="quality_rating" rows="2" placeholder="Đánh giá CLCM..." class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500"></textarea>
                    </div>
                </div>
            </div>

            {{-- Trạng thái chăm sóc --}}
            <div class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
                <h2 class="font-bold text-gold-700 mb-5 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Trạng thái chăm sóc
                </h2>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium mb-1.5">Ghi nhận tình trạng lần 1</label>
                        <input type="text" wire:model="status_1" placeholder="VD: Đã liên hệ" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1.5">Ghi nhận tình trạng lần 2</label>
                        <input type="text" wire:model="status_2" placeholder="VD: Đã tư vấn" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1.5">PHÂN LOẠI KẾT QUẢ</label>
                            <select wire:model="classification" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm bg-white focus:outline-none focus:border-gold-500">
                                @foreach (\App\Models\Lead::CLASSIFICATIONS as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1.5">TRẠNG THÁI ĐẶT LỊCH</label>
                            <select wire:model="bookingStatus" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm bg-white focus:outline-none focus:border-gold-500">
                                @foreach (\App\Models\Lead::BOOKING_STATUSES as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <p class="text-xs text-ink/50 mt-1">Team booking đổi khi khách đồng ý gặp.</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Trường bổ sung --}}
            @if ($customFields->isNotEmpty())
            <div class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
                <h2 class="font-bold text-gold-700 mb-1 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.28z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    Trường bổ sung
                    <span class="text-xs font-normal text-ink/50">({{ $lead?->orgUnit?->name ?? $this->targetOrgUnit()?->name ?? 'mức công ty' }})</span>
                </h2>
                <p class="text-xs text-ink/50 mb-5">Bộ trường buộc khai theo quy định hiện hành.</p>
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
        </div>
    </div>

    <div class="border-t border-gold-100 mt-6 pt-5 flex justify-end gap-3">
        <a href="{{ $lead ? route('leads.show', $lead) : (auth()->user()->hasPermission('lead.view') ? route('leads.index') : route('dashboard')) }}" class="text-sm font-semibold text-ink/60 border border-gold-200 px-5 py-2.5 rounded-md hover:bg-gold-50">Hủy</a>
        <button wire:click="save" class="bg-gold-600 hover:bg-gold-700 text-white font-semibold text-sm px-6 py-2.5 rounded-md">Lưu thông tin khách hàng</button>
    </div>
</div>

