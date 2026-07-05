<?php

use App\Models\Assignment;
use App\Models\AuditLog;
use App\Models\CustomField;
use App\Models\Lead;
use App\Models\LeadCustomValue;
use App\Models\LeadStatusLog;
use App\Models\OrgUnit;
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

    public string $owner_id = '';

    public string $status_1 = '';

    public string $status_2 = '';

    public string $note = '';

    public string $classification = 'new';

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
        $this->owner_id = (string) ($lead->owner_id ?? '');
        $this->status_1 = $lead->status_1 ?? '';
        $this->status_2 = $lead->status_2 ?? '';
        $this->note = $lead->note ?? '';
        $this->classification = $lead->classification;
        $this->custom = $lead->customValues->pluck('value', 'custom_field_id')
            ->map(fn ($v) => (string) $v)->all();
    }

    /** Org quyết định bộ trường tùy biến: org lead đang giữ (sửa) / org của owner được chọn (tạo mới). */
    private function targetOrgUnit(): ?OrgUnit
    {
        if ($this->lead?->org_unit_id) {
            return $this->lead->orgUnit;
        }
        if ($this->owner_id) {
            $orgId = Assignment::where('user_id', (int) $this->owner_id)->effective()->first()?->org_unit_id;
            return $orgId ? OrgUnit::find($orgId) : null;
        }

        return null;
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
            'owner_id' => 'nullable|exists:users,id',
            'link' => 'nullable|string|max:500',
        ], [], ['name' => 'tên khách hàng', 'phone' => 'SĐT', 'received_date' => 'ngày']);

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
        $ownerId = $this->owner_id ? (int) $this->owner_id : null;

        $attributes['receiver_id'] = $user->id; // người nhập là người nhận lead
        $attributes['owner_id'] = $ownerId;

        if ($ownerId) {
            $ownerOrg = Assignment::where('user_id', $ownerId)->effective()->first()?->org_unit_id;
            $attributes['pool_level'] = Lead::POOL_PERSONAL;
            $attributes['org_unit_id'] = $ownerOrg;
            $attributes['assigned_at'] = now();
        } else {
            $attributes['pool_level'] = Lead::POOL_COMMON;
        }

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

        $newOwnerId = $this->owner_id ? (int) $this->owner_id : null;
        if ($newOwnerId !== $lead->owner_id) {
            $attributes['owner_id'] = $newOwnerId;
        }

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

    public function with(): array
    {
        // Chỉ gán lead cho user nằm trong phạm vi của người thao tác
        $visibleOrgIds = auth()->user()->visibleOrgUnitIds();

        return [
            'assignableUsers' => User::where('status', User::STATUS_ACTIVE)
                ->where(fn ($q) => $q
                    ->whereHas('assignments', fn ($qq) => $qq->effective()->when(
                        $visibleOrgIds !== [],
                        fn ($qqq) => $qqq->whereIn('org_unit_id', $visibleOrgIds)
                    ))
                    ->orWhere('id', auth()->id()))
                ->orderBy('name')
                ->get(),
            'customFields' => CustomField::applicableTo($this->targetOrgUnit()),
        ];
    }
};
?>

<div class="max-w-5xl">
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
        {{-- Thông tin cơ bản --}}
        <div class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
            <h2 class="font-bold text-gold-700 mb-5 flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg>
                Thông tin cá nhân
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
                    <textarea wire:model="insight" rows="3" placeholder="Ghi chú insight khách hàng..." class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500"></textarea>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5">Link</label>
                    <input type="text" wire:model="link" placeholder="https://facebook.com/..." class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5">NOTE</label>
                    <textarea wire:model="note" rows="2" placeholder="Ghi chú thêm..." class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500"></textarea>
                </div>
            </div>
        </div>

        <div class="space-y-6">
            {{-- Phân phối & Nguồn --}}
            <div class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
                <h2 class="font-bold text-gold-700 mb-5 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5"/></svg>
                    Phân phối & Nguồn
                </h2>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium mb-1.5">Nguồn quảng cáo</label>
                        <select wire:model="ad_source" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm bg-white focus:outline-none focus:border-gold-500">
                            <option value="">Chọn nguồn quảng cáo</option>
                            @foreach (['Facebook Ads', 'Google Ads', 'TikTok Ads', 'Zalo', 'Website', 'Giới thiệu', 'Khác'] as $source)
                                <option value="{{ $source }}">{{ $source }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if ($lead?->code)
                    <div>
                        <label class="block text-sm font-medium mb-1.5">Mã khách hàng</label>
                        <p class="text-sm mt-2"><code class="font-mono text-gold-700">{{ $lead->code }}</code></p>
                        <p class="text-xs text-ink/50 mt-1.5">Mã tự sinh theo trường phân loại của phòng.</p>
                    </div>
                    @endif
                    <div>
                        <label class="block text-sm font-medium mb-1.5">LEAD CHIA CHO</label>
                        <select wire:model.live="owner_id" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm bg-white focus:outline-none focus:border-gold-500">
                            <option value="">— Chưa chia (vào kho chung) —</option>
                            @foreach ($assignableUsers as $u)
                                <option value="{{ $u->id }}">{{ $u->name }}</option>
                            @endforeach
                        </select>
                        @error('owner_id')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1.5">KHU VỰC</label>
                        <input type="text" wire:model="region" placeholder="VD: TP. Hồ Chí Minh" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500">
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

            {{-- Trường tùy biến theo phòng ban --}}
            @if ($customFields->isNotEmpty())
                <div class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
                    <h2 class="font-bold text-gold-700 mb-1 flex items-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.28z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        Trường bổ sung
                        <span class="text-xs font-normal text-ink/50">({{ $lead?->orgUnit?->name ?? $this->targetOrgUnit()?->name ?? 'mức công ty' }})</span>
                    </h2>
                    <p class="text-xs text-ink/50 mb-5">Bộ trường do phòng ban đang giữ lead quy định.</p>
                    <div class="space-y-4">
                        @foreach ($customFields as $field)
                            @php $ck = $field->rules['code_kind'] ?? null; @endphp
                            {{-- Mã cố định: tự động, không cần nhập --}}
                            @continue($field->field_type === 'code' && $ck === 'fixed')
                            <div wire:key="cf-{{ $field->id }}">
                                <label class="block text-sm font-medium mb-1.5">
                                    {{ $field->label }}
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
                                            <option value="{{ $option }}">{{ $option }}</option>
                                        @endforeach
                                    </select>
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

    <div class="border-t border-gold-100 mt-6 pt-5 flex justify-end gap-3">
        <a href="{{ $lead ? route('leads.show', $lead) : route('leads.index') }}" class="text-sm font-semibold text-ink/60 border border-gold-200 px-5 py-2.5 rounded-md hover:bg-gold-50">Hủy</a>
        <button wire:click="save" class="bg-gold-600 hover:bg-gold-700 text-white font-semibold text-sm px-6 py-2.5 rounded-md">Lưu thông tin khách hàng</button>
    </div>
</div>
