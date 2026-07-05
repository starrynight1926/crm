<?php

use App\Models\CustomField;
use App\Models\LeadCustomValue;
use App\Models\OrgUnit;
use Illuminate\Support\Str;
use Livewire\Component;

new class extends Component
{
    public string $selectedOrgId = ''; // '' = mức công ty

    public bool $showModal = false;

    public ?int $editingId = null;

    public string $label = '';

    public string $fieldType = 'text';

    public string $optionsText = '';

    public bool $required = false;

    // Ràng buộc theo kiểu
    public ?string $numberMin = null;

    public ?string $numberMax = null;

    public ?string $textMax = null;

    // Mã phân loại (field_type = code)
    public string $codeKind = 'input'; // fixed | select | input

    public string $codeFixedValue = '';

    /** Org user được quản lý field: subtree các assignment có quyền field.manage. */
    private function manageableOrgIds(): array
    {
        $user = auth()->user();
        $ids = [];
        foreach ($user->effectiveAssignments() as $assignment) {
            if ($assignment->role->permissions->contains('key', 'field.manage')) {
                $ids = array_merge($ids, $assignment->orgUnit->subtreeIds());
            }
        }

        return array_values(array_unique($ids));
    }

    /** Mức công ty: chỉ user giữ field.manage tại node gốc. */
    private function canManageCompanyLevel(): bool
    {
        $user = auth()->user();
        foreach ($user->effectiveAssignments() as $assignment) {
            if ($assignment->orgUnit->depth === 0
                && $assignment->role->permissions->contains('key', 'field.manage')) {
                return true;
            }
        }

        return false;
    }

    private function guardSelectedOrg(): void
    {
        if ($this->selectedOrgId === '') {
            abort_unless($this->canManageCompanyLevel(), 403);
        } else {
            abort_unless(in_array((int) $this->selectedOrgId, $this->manageableOrgIds(), true), 403);
        }
    }

    public function updatedSelectedOrgId(): void
    {
        $this->showModal = false;
    }

    public function openCreate(): void
    {
        $this->guardSelectedOrg();
        $this->reset('editingId', 'label', 'optionsText', 'numberMin', 'numberMax', 'textMax', 'codeFixedValue');
        $this->fieldType = 'text';
        $this->codeKind = 'input';
        $this->required = false;
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $field = CustomField::findOrFail($id);
        $this->selectedOrgId = (string) ($field->org_unit_id ?? '');
        $this->guardSelectedOrg();

        $this->editingId = $field->id;
        $this->label = $field->label;
        $this->fieldType = $field->field_type;
        $this->optionsText = implode("\n", $field->options ?? []);
        $this->required = $field->required;
        $rules = $field->rules ?? [];
        $this->numberMin = isset($rules['min']) ? (string) $rules['min'] : null;
        $this->numberMax = isset($rules['max']) ? (string) $rules['max'] : null;
        $this->textMax = isset($rules['maxlength']) ? (string) $rules['maxlength'] : null;
        $this->codeKind = $rules['code_kind'] ?? 'input';
        $this->codeFixedValue = $rules['fixed_value'] ?? '';
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->guardSelectedOrg();
        $this->validate([
            'label' => 'required|string|max:100',
            'fieldType' => 'required|in:text,number,date,email,select,code',
        ], [], ['label' => 'tên trường']);

        $orgId = $this->selectedOrgId !== '' ? (int) $this->selectedOrgId : null;

        // Options cho select / code(select)
        $options = null;
        if ($this->fieldType === 'select' || ($this->fieldType === 'code' && $this->codeKind === 'select')) {
            $options = array_values(array_filter(array_map('trim', explode("\n", $this->optionsText))));
            if (count($options) < 2) {
                $this->addError('optionsText', 'Cần ít nhất 2 lựa chọn (mỗi dòng một lựa chọn).');
                return;
            }
        }

        // Ràng buộc theo kiểu
        $rules = [];
        $affectsCode = false;
        if ($this->fieldType === 'number') {
            if ($this->numberMin !== null && $this->numberMin !== '') $rules['min'] = (float) $this->numberMin;
            if ($this->numberMax !== null && $this->numberMax !== '') $rules['max'] = (float) $this->numberMax;
            if (isset($rules['min'], $rules['max']) && $rules['min'] > $rules['max']) {
                $this->addError('numberMax', 'Giá trị tối đa phải ≥ tối thiểu.');
                return;
            }
        } elseif ($this->fieldType === 'text') {
            if ($this->textMax !== null && $this->textMax !== '') $rules['maxlength'] = (int) $this->textMax;
        } elseif ($this->fieldType === 'code') {
            $affectsCode = true;
            $rules['code_kind'] = $this->codeKind;
            if ($this->codeKind === 'fixed') {
                $fixed = CustomField::normalizeCode($this->codeFixedValue);
                if ($fixed === '') {
                    $this->addError('codeFixedValue', 'Mã cố định cần chữ/số (VD: 2026, MKT).');
                    return;
                }
                $rules['fixed_value'] = $fixed;
            }
        }

        if ($this->editingId) {
            $field = CustomField::findOrFail($this->editingId);
            $field->update([
                'label' => $this->label,
                'field_type' => $this->fieldType,
                'options' => $options,
                'rules' => $rules ?: null,
                'affects_code' => $affectsCode,
                'required' => $this->required,
            ]);
            session()->flash('field_status', 'Đã cập nhật trường.');
        } else {
            $base = Str::slug($this->label, '_') ?: 'field';
            $key = $base;
            $i = 1;
            while (CustomField::where('org_unit_id', $orgId)->where('key', $key)->exists()) {
                $key = $base . '_' . (++$i);
            }

            // Duyệt: trường BẮT BUỘC ở cấp phòng/nhóm (org != null) phải chờ cấp trên duyệt.
            // Mức công ty hoặc trường tùy chọn → áp ngay.
            $needsApproval = $orgId !== null && $this->required;

            CustomField::create([
                'org_unit_id' => $orgId,
                'key' => $key,
                'label' => $this->label,
                'field_type' => $this->fieldType,
                'options' => $options,
                'rules' => $rules ?: null,
                'affects_code' => $affectsCode,
                'required' => $this->required,
                'position' => (CustomField::where('org_unit_id', $orgId)->max('position') ?? 0) + 1,
                'status' => $needsApproval ? CustomField::STATUS_PENDING : CustomField::STATUS_ACTIVE,
                'requested_by' => $needsApproval ? auth()->id() : null,
            ]);

            session()->flash('field_status', $needsApproval
                ? 'Đã gửi trường bắt buộc chờ cấp trên duyệt (chưa áp lên lead).'
                : 'Đã thêm trường.');
        }

        $this->showModal = false;
    }

    public function toggleActive(int $id): void
    {
        $field = CustomField::findOrFail($id);
        $this->selectedOrgId = (string) ($field->org_unit_id ?? '');
        $this->guardSelectedOrg();
        $field->update(['active' => ! $field->active]);
    }

    public function deleteField(int $id): void
    {
        $field = CustomField::findOrFail($id);
        $this->selectedOrgId = (string) ($field->org_unit_id ?? '');
        $this->guardSelectedOrg();

        if (LeadCustomValue::where('custom_field_id', $id)->exists()) {
            session()->flash('field_error', 'Trường đã có dữ liệu trên lead — chỉ nên "Ngưng dùng" để giữ lịch sử.');
            return;
        }

        $field->delete();
    }

    public function with(): array
    {
        $manageable = $this->manageableOrgIds();

        return [
            'orgOptions' => OrgUnit::whereIn('id', $manageable)->orderBy('path')->get(),
            'canCompanyLevel' => $this->canManageCompanyLevel(),
            'fields' => CustomField::query()
                ->when(
                    $this->selectedOrgId === '',
                    fn ($q) => $q->whereNull('org_unit_id'),
                    fn ($q) => $q->where('org_unit_id', (int) $this->selectedOrgId)
                )
                ->orderBy('position')
                ->get(),
        ];
    }
};
?>

<div>
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <h2 class="text-2xl font-bold mb-2">Trường tùy biến theo phòng ban</h2>
            <p class="text-sm text-ink/60 max-w-2xl">
                Mỗi cấp (công ty → phòng → nhóm) tự định nghĩa trường riêng, kế thừa xuống dưới.
                Trường <strong>bắt buộc</strong> ở cấp phòng/nhóm phải được <strong>cấp trên duyệt</strong> mới áp lên lead.
                Trường kiểu <strong>Mã phân loại</strong> nối giá trị vào mã KH.
            </p>
        </div>
        <button wire:click="openCreate" class="bg-gold-600 hover:bg-gold-700 text-white font-semibold px-5 py-2.5 rounded-md text-sm">
            + Thêm trường
        </button>
    </div>

    @if (session('field_error'))
        <p class="mb-4 text-sm text-red-600 bg-red-50 border border-red-200 rounded-md px-4 py-2">{{ session('field_error') }}</p>
    @endif
    @if (session('field_status'))
        <p class="mb-4 text-sm text-green-700 bg-green-50 border border-green-200 rounded-md px-4 py-2">{{ session('field_status') }}</p>
    @endif

    <div class="bg-white border border-gold-200 rounded-xl shadow-card">
        <div class="px-5 py-4 border-b border-gold-100 flex items-center gap-3">
            <label class="text-sm font-semibold text-ink/60">Phạm vi:</label>
            <select wire:model.live="selectedOrgId" class="border border-gold-200 rounded-md px-3 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                @if ($canCompanyLevel)
                    <option value="">🏢 Mức công ty (áp mọi bộ phận)</option>
                @endif
                @foreach ($orgOptions as $unit)
                    <option value="{{ $unit->id }}">{{ str_repeat('— ', $unit->depth) }}{{ $unit->name }}</option>
                @endforeach
            </select>
        </div>

        <div class="overflow-x-auto">
        <table class="w-full text-sm min-w-[720px]">
            <thead>
                <tr class="text-left text-xs uppercase tracking-wider text-ink/50 bg-gold-50/60">
                    <th class="px-5 py-3 font-semibold">Tên trường</th>
                    <th class="px-5 py-3 font-semibold">Kiểu</th>
                    <th class="px-5 py-3 font-semibold">Ràng buộc</th>
                    <th class="px-5 py-3 font-semibold">Bắt buộc</th>
                    <th class="px-5 py-3 font-semibold">Trạng thái</th>
                    <th class="px-5 py-3 font-semibold text-right">Hành động</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gold-100">
                @forelse ($fields as $field)
                    <tr class="hover:bg-gold-50/40 {{ $field->active ? '' : 'opacity-50' }}">
                        <td class="px-5 py-3.5 font-semibold">
                            {{ $field->label }}
                            @if ($field->affects_code)<span class="ml-1 text-xs text-gold-700" title="Nối vào mã KH">#mã</span>@endif
                        </td>
                        <td class="px-5 py-3.5">
                            {{ \App\Models\CustomField::TYPES[$field->field_type] ?? $field->field_type }}
                        </td>
                        <td class="px-5 py-3.5 text-xs text-ink/50">
                            @php $r = $field->rules ?? []; @endphp
                            @if ($field->field_type === 'number' && (isset($r['min']) || isset($r['max'])))
                                {{ $r['min'] ?? '−∞' }} … {{ $r['max'] ?? '+∞' }}
                            @elseif ($field->field_type === 'text' && isset($r['maxlength']))
                                ≤ {{ $r['maxlength'] }} ký tự
                            @elseif ($field->field_type === 'code')
                                {{ ['fixed' => 'Cố định: ' . ($r['fixed_value'] ?? ''), 'select' => 'Chọn ' . count($field->options ?? []), 'input' => 'Nhập tay'][$r['code_kind'] ?? 'input'] ?? '' }}
                            @elseif ($field->field_type === 'select')
                                {{ count($field->options ?? []) }} lựa chọn
                            @else — @endif
                        </td>
                        <td class="px-5 py-3.5">
                            @if ($field->required)
                                <span class="text-xs font-semibold text-red-700 bg-red-50 border border-red-200 px-2 py-0.5 rounded">Bắt buộc</span>
                            @else
                                <span class="text-xs text-ink/40">Tùy chọn</span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-xs font-medium">
                            @if ($field->status === \App\Models\CustomField::STATUS_PENDING)
                                <span class="text-amber-700 bg-amber-50 border border-amber-200 px-2 py-0.5 rounded">Chờ duyệt</span>
                            @elseif ($field->status === \App\Models\CustomField::STATUS_REJECTED)
                                <span class="text-red-700 bg-red-50 border border-red-200 px-2 py-0.5 rounded" title="{{ $field->reject_reason }}">Bị từ chối</span>
                            @else
                                <span class="{{ $field->active ? 'text-green-700' : 'text-ink/40' }}">{{ $field->active ? 'Đang dùng' : 'Ngưng dùng' }}</span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-right whitespace-nowrap">
                            <button wire:click="openEdit({{ $field->id }})" class="text-xs font-semibold text-gold-700 border border-gold-300 hover:bg-gold-50 px-3 py-1.5 rounded-md">Sửa</button>
                            <button wire:click="toggleActive({{ $field->id }})" class="text-xs font-semibold text-ink/60 border border-gold-200 hover:bg-gold-50 px-3 py-1.5 rounded-md">
                                {{ $field->active ? 'Ngưng dùng' : 'Dùng lại' }}
                            </button>
                            <button wire:click="deleteField({{ $field->id }})" wire:confirm="Xóa trường '{{ $field->label }}'?" class="text-xs font-semibold text-red-700 border border-red-200 hover:bg-red-50 px-3 py-1.5 rounded-md">Xóa</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-5 py-10 text-center text-ink/40">Chưa có trường nào ở phạm vi này.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    {{-- Modal thêm/sửa field --}}
    @if ($showModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-ink/40" wire:click="$set('showModal', false)"></div>
            <div class="relative bg-white rounded-xl shadow-xl border border-gold-200 w-full max-w-md p-7 max-h-[90vh] overflow-y-auto">
                <h3 class="text-xl font-bold mb-5">{{ $editingId ? 'Sửa trường' : 'Thêm trường mới' }}</h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-widest text-ink/60 mb-1.5">Tên trường</label>
                        <input type="text" wire:model="label" placeholder="VD: Ngân sách dự kiến" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500">
                        @error('label')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-widest text-ink/60 mb-1.5">Kiểu dữ liệu</label>
                        <select wire:model.live="fieldType" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                            @foreach (\App\Models\CustomField::TYPES as $key => $typeLabel)
                                <option value="{{ $key }}">{{ $typeLabel }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Ràng buộc: số --}}
                    @if ($fieldType === 'number')
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs text-ink/50 mb-1">Tối thiểu</label>
                                <input type="number" wire:model="numberMin" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500">
                            </div>
                            <div>
                                <label class="block text-xs text-ink/50 mb-1">Tối đa</label>
                                <input type="number" wire:model="numberMax" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500">
                                @error('numberMax')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    @endif

                    {{-- Ràng buộc: text --}}
                    @if ($fieldType === 'text')
                        <div>
                            <label class="block text-xs text-ink/50 mb-1">Tối đa số ký tự (bỏ trống = không giới hạn)</label>
                            <input type="number" wire:model="textMax" min="1" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500">
                        </div>
                    @endif

                    {{-- select --}}
                    @if ($fieldType === 'select')
                        <div>
                            <label class="block text-xs text-ink/50 mb-1">Các lựa chọn (mỗi dòng một lựa chọn)</label>
                            <textarea wire:model="optionsText" rows="4" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500"></textarea>
                            @error('optionsText')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>
                    @endif

                    {{-- code (mã phân loại) --}}
                    @if ($fieldType === 'code')
                        <div class="border border-gold-100 rounded-lg p-3 space-y-3 bg-gold-50/40">
                            <p class="text-xs text-gold-700">Giá trị sẽ nối vào mã KH (VD: <code>KH-001-2026-MKT</code>).</p>
                            <div>
                                <label class="block text-xs text-ink/50 mb-1">Cách lấy mã</label>
                                <select wire:model.live="codeKind" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                                    <option value="fixed">Cố định (mọi lead cùng mã)</option>
                                    <option value="select">Chọn từ danh sách mã</option>
                                    <option value="input">Người nhập tự gõ mã</option>
                                </select>
                            </div>
                            @if ($codeKind === 'fixed')
                                <div>
                                    <label class="block text-xs text-ink/50 mb-1">Mã cố định</label>
                                    <input type="text" wire:model="codeFixedValue" placeholder="VD: 2026 hoặc MKT" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm uppercase focus:outline-none focus:border-gold-500">
                                    @error('codeFixedValue')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                                </div>
                            @elseif ($codeKind === 'select')
                                <div>
                                    <label class="block text-xs text-ink/50 mb-1">Danh sách mã (mỗi dòng một mã)</label>
                                    <textarea wire:model="optionsText" rows="3" placeholder="FB&#10;GG&#10;TT" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500"></textarea>
                                    @error('optionsText')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                                </div>
                            @endif
                        </div>
                    @endif

                    <label class="flex items-center gap-2.5 text-sm cursor-pointer">
                        <input type="checkbox" wire:model="required" class="rounded border-gold-300 text-gold-600 focus:ring-gold-500 w-5 h-5">
                        Bắt buộc điền khi cập nhật lead
                    </label>
                    @if (!$editingId && $selectedOrgId !== '' && $required)
                        <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded px-3 py-2">
                            Trường bắt buộc ở cấp phòng/nhóm sẽ <strong>chờ cấp trên duyệt</strong> trước khi áp lên lead.
                        </p>
                    @endif
                </div>
                <div class="flex justify-end gap-3 mt-7">
                    <button wire:click="$set('showModal', false)" class="text-sm text-ink/60 px-4 py-2">Hủy</button>
                    <button wire:click="save" class="bg-gold-600 hover:bg-gold-700 text-white font-semibold text-sm px-6 py-2 rounded-md">Lưu</button>
                </div>
            </div>
        </div>
    @endif
</div>
