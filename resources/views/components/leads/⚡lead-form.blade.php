<?php

use App\Models\Assignment;
use App\Models\AuditLog;
use App\Models\CustomField;
use App\Models\Facility;
use App\Models\Lead;
use App\Models\LeadCustomValue;
use App\Models\LeadStatusLog;
use App\Models\OrgUnit;
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
            $this->poolTarget = 'company'; // đang ở kho chung công ty
        }
        $this->status_1 = $lead->status_1 ?? '';
        $this->status_2 = $lead->status_2 ?? '';
        $this->note = $lead->note ?? '';
        $this->classification = $lead->classification;
        $this->facilityId = $lead->facility_id;
        $this->doctorId = $lead->doctor_id;
        $this->consultant1Id = $lead->consultant_1_id;
        $this->consultant2Id = $lead->consultant_2_id;
        $this->consultant3Id = $lead->consultant_3_id;
        $this->custom = $lead->customValues->pluck('value', 'custom_field_id')
            ->map(fn ($v) => (string) $v)->all();
    }

    /** Chọn cá nhân → lead rời kho chung, chuyển hẳn sang kho cá nhân. */
    public function selectPerson(int $id): void
    {
        if ($this->assignableUserIds()->contains($id)) {
            $this->personId = $id;
            $this->personSearch = '';
            $this->poolTarget = ''; // không còn thuộc kho chung nào
        }
    }

    public function clearPerson(): void
    {
        $this->personId = null;
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

        // Kho chung: khi sửa lead đã có phòng thì vẫn theo phòng đó
        return $this->lead?->org_unit_id ? $this->lead->orgUnit : null;
    }

    /** Validate + trả về [field_id => value] chỉ gồm các trường áp dụng. Trả null nếu có lỗi. */
    private function validateCustomFields(): ?array
    {
        $fields = CustomField::applicableTo($this->targetOrgUnit());
        $clean = [];
        $hasError = false;

        foreach ($fields as $field) {
            // Mã cố định: giá trị tự động, người dùng không nhập → bỏ qua
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
                    continue; // cố định — không lấy từ input
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

        $this->validate([
            'name' => 'required|string|max:150',
            'phone' => 'required|string',
            'received_date' => 'required|date',
            'classification' => 'required|in:' . implode(',', array_keys(Lead::CLASSIFICATIONS)),
            'link' => 'nullable|string|max:500',
        ], [], ['name' => 'tên khách hàng', 'phone' => 'SĐT', 'received_date' => 'ngày']);

        // Kiểm tra đích chia hợp lệ trong phạm vi cho phép
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

        // Chống trùng
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
            'facility_id' => $this->facilityId ?: null,
            'doctor_id' => $this->doctorId ?: null,
            'consultant_1_id' => $this->consultant1Id ?: null,
            'consultant_2_id' => $this->consultant2Id ?: null,
            'consultant_3_id' => $this->consultant3Id ?: null,
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
        $attributes['receiver_id'] = $user->id; // người nhập là người nhận lead
        $attributes = array_merge($attributes, $this->poolAttributes());

        $lead = Lead::create($attributes);
        $this->syncCustomValues($lead, $cleanCustom);
        $lead->load('customValues');
        $lead->generateCode();

        LeadStatusLog::record($lead, 'created', null, 'Nhập tay bởi ' . $user->name, $user->id);
        AuditLog::record('create', $lead);

        session()->flash('status', 'Đã tạo lead mới.');
        $this->redirectRoute('leads.show', $lead);
    }

    private function updateLead(array $attributes, array $cleanCustom): void
    {
        $lead = $this->lead;
        $user = auth()->user();

        // Log các field chăm sóc thay đổi
        foreach (['classification', 'status_1', 'status_2', 'note'] as $field) {
            $old = $lead->{$field};
            $new = $attributes[$field];
            if ($old !== $new) {
                LeadStatusLog::record($lead, $field, $old, $new, $user->id);
            }
        }

        // Cập nhật đích chia (kho chung công ty / phòng-team / cá nhân)
        $attributes = array_merge($attributes, $this->poolAttributes($lead));

        if (in_array('classification', array_keys($attributes)) && $attributes['classification'] !== $lead->classification) {
            $attributes['last_care_at'] = now();
        }

        $lead->update($attributes);
        $this->syncCustomValues($lead, $cleanCustom);
        $lead->load('customValues');
        $lead->generateCode(); // classification đổi/đổi phòng → mã cập nhật theo
        AuditLog::record('update', $lead);

        session()->flash('status', 'Đã cập nhật thông tin khách hàng.');
        $this->redirectRoute('leads.show', $lead);
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
                'name' => $dept->name,
                'doctors' => $allStaff->where('facility_id', $dept->id)->where('role', 'doctor')->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values()->all(),
                'consultants' => $allStaff->where('facility_id', $dept->id)->where('role', 'consultant')->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values()->all(),
            ])->all(),
        ])->all();

        return [
            'assignableOrgs' => $this->assignableOrgs(),
            'personResults' => $results,
            'selectedPerson' => $this->personId ? $users->firstWhere('id', $this->personId) : null,
            'customFields' => CustomField::applicableTo($this->targetOrgUnit()),
            'facilities' => $facilities,
            'staffTree' => $staffTree,
            'allStaff' => $allStaff,
        ];
    }
};
?>

<div x-data="{ tab: 'assign' }">
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="text-3xl font-bold mb-1">{{ $lead ? 'Cập nhật Khách Hàng' : 'Thêm Mới Khách Hàng' }}</h1>
            <p class="text-sm text-ink/60">Vui lòng điền đầy đủ thông tin để cập nhật vào hệ thống sales pipeline.</p>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ $lead ? route('leads.show', $lead) : route('leads.index') }}" class="text-sm font-semibold text-ink/60 border border-gold-200 px-5 py-2.5 rounded-md hover:bg-gold-50">Hủy</a>
            <button wire:click="save" class="bg-gold-600 hover:bg-gold-700 text-white font-semibold text-sm px-6 py-2.5 rounded-md">Lưu thông tin</button>
        </div>
    </div>

    @if ($errors->has('phone') && $duplicateLeadId)
        <div class="mb-5 bg-red-50 border border-red-200 rounded-lg px-4 py-3 text-sm text-red-700">
            SĐT đã tồn tại — <a href="{{ route('leads.show', $duplicateLeadId) }}" class="font-semibold underline">mở lead hiện có</a> để cập nhật thay vì tạo mới.
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
        {{-- CỘT TRÁI: Thông tin khách — luôn hiện --}}
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
                        <input type="date" wire:model="received_date" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500">
                        @error('received_date')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1.5">PAGE</label>
                        <input type="text" wire:model="page" placeholder="Tên fanpage" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5">Camp</label>
                    <input type="text" wire:model="camp" placeholder="Tên chiến dịch" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5">Insight</label>
                    <textarea wire:model="insight" rows="2" placeholder="Ghi chú insight khách hàng..." class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500"></textarea>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5">Link</label>
                    <input type="text" wire:model="link" placeholder="https://facebook.com/..." class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500">
                    @error('link')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5">NOTE</label>
                    <textarea wire:model="note" rows="2" placeholder="Ghi chú thêm..." class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500"></textarea>
                </div>
            </div>
        </div>

        {{-- CỘT PHẢI: Tab Phân phối | Chăm sóc --}}
        <div>
            <div class="flex gap-1 mb-4 border-b border-gold-200">
                <button type="button" @click="tab = 'assign'" :class="tab === 'assign' ? 'border-gold-600 text-gold-700 bg-white' : 'border-transparent text-ink/50 hover:text-ink/70 hover:border-gold-300'"
                        class="px-4 py-2.5 text-sm font-semibold border-b-2 -mb-px rounded-t-md transition-colors flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5"/></svg>
                    Phân phối & Nhân sự
                    @if ($errors->hasAny(['poolTarget', 'personId']))
                        <span class="w-2 h-2 rounded-full bg-red-500"></span>
                    @endif
                </button>
                <button type="button" @click="tab = 'care'" :class="tab === 'care' ? 'border-gold-600 text-gold-700 bg-white' : 'border-transparent text-ink/50 hover:text-ink/70 hover:border-gold-300'"
                        class="px-4 py-2.5 text-sm font-semibold border-b-2 -mb-px rounded-t-md transition-colors flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Chăm sóc & Bổ sung
                    @if ($errors->hasAny(collect(range(1, 100))->map(fn ($i) => "custom.$i")->all()))
                        <span class="w-2 h-2 rounded-full bg-red-500"></span>
                    @endif
                </button>
            </div>

            {{-- Tab: Phân phối & Nhân sự --}}
            <div x-show="tab === 'assign'" class="space-y-6">
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

                <div class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
                    <h2 class="font-bold text-gold-700 mb-5 flex items-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"/></svg>
                        Cơ sở & Nhân sự tư vấn
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
                        @php $staffTreeJson = json_encode($staffTree, JSON_UNESCAPED_UNICODE); @endphp

                        @foreach ([
                            ['label' => 'BÁC SĨ TƯ VẤN', 'wireModel' => 'doctorId', 'role' => 'doctors', 'placeholder' => 'Chọn bác sĩ', 'current' => $doctorId],
                            ['label' => 'CHUYÊN VIÊN TƯ VẤN 1', 'wireModel' => 'consultant1Id', 'role' => 'consultants', 'placeholder' => 'Chọn chuyên viên', 'current' => $consultant1Id],
                            ['label' => 'CHUYÊN VIÊN TƯ VẤN 2', 'wireModel' => 'consultant2Id', 'role' => 'consultants', 'placeholder' => 'Chọn chuyên viên', 'current' => $consultant2Id],
                            ['label' => 'CHUYÊN VIÊN TƯ VẤN 3', 'wireModel' => 'consultant3Id', 'role' => 'consultants', 'placeholder' => 'Chọn chuyên viên', 'current' => $consultant3Id],
                        ] as $dd)
                            <div>
                                <label class="block text-sm font-medium mb-1.5">{{ $dd['label'] }}</label>
                                <div x-data="{
                                    open: false,
                                    search: '',
                                    tree: {{ $staffTreeJson }},
                                    role: '{{ $dd['role'] }}',
                                    selectedId: {{ $dd['current'] ?: 'null' }},
                                    selectedName: '{{ $dd['current'] ? $allStaff->firstWhere('id', $dd['current'])?->name : '' }}',
                                    get hasSelection() { return this.selectedId != null && this.selectedId > 0; },
                                    get filtered() {
                                        let q = this.search.toLowerCase();
                                        if (!q) return this.tree;
                                        return this.tree.map(fac => ({
                                            ...fac,
                                            depts: fac.depts.map(dept => ({
                                                ...dept,
                                                [this.role]: dept[this.role].filter(s => s.name.toLowerCase().includes(q))
                                            })).filter(dept => dept[this.role].length > 0)
                                        })).filter(fac => fac.depts.length > 0);
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
                    </div>
                </div>
            </div>

            {{-- Tab: Chăm sóc & Bổ sung --}}
            <div x-show="tab === 'care'" x-cloak class="space-y-6">
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
                        <div>
                            <label class="block text-sm font-medium mb-1.5">PHÂN LOẠI KẾT QUẢ</label>
                            <select wire:model="classification" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm bg-white focus:outline-none focus:border-gold-500">
                                @foreach (\App\Models\Lead::CLASSIFICATIONS as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                @if ($customFields->isNotEmpty())
                    <div class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
                        <h2 class="font-bold text-gold-700 mb-1 flex items-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.28z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            Trường bổ sung
                            <span class="text-xs font-normal text-ink/50">({{ $lead?->orgUnit?->name ?? $this->targetOrgUnit()?->name ?? 'mức công ty' }})</span>
                        </h2>
                        <p class="text-xs text-ink/50 mb-5">Bộ trường buộc khai theo quy định hiện hành.</p>
                        @php $cfLabels = \App\Models\CustomField::labelMap($customFields); @endphp
                        <div class="space-y-4">
                            @foreach ($customFields as $field)
                                @php $ck = $field->rules['code_kind'] ?? null; @endphp
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
                                                @php $ol = $field->optionLabel($option); @endphp
                                                <option value="{{ $option }}">{{ ($ol !== '' && $ol !== $option) ? "$ol ($option)" : $option }}</option>
                                            @endforeach
                                        </select>
                                    @elseif ($field->field_type === 'tick')
                                        <label class="inline-flex items-center gap-2 text-sm cursor-pointer select-none">
                                            <input type="checkbox" wire:model="custom.{{ $field->id }}" class="rounded border-gold-300 text-gold-600 focus:ring-gold-500 w-5 h-5">
                                            Có
                                        </label>
                                    @elseif ($field->field_type === 'date')
                                        <input type="date" wire:model="custom.{{ $field->id }}" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500">
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
    </div>

    <div class="border-t border-gold-100 mt-6 pt-5 flex justify-end gap-3">
        <a href="{{ $lead ? route('leads.show', $lead) : route('leads.index') }}" class="text-sm font-semibold text-ink/60 border border-gold-200 px-5 py-2.5 rounded-md hover:bg-gold-50">Hủy</a>
        <button wire:click="save" class="bg-gold-600 hover:bg-gold-700 text-white font-semibold text-sm px-6 py-2.5 rounded-md">Lưu thông tin khách hàng</button>
    </div>
</div>
