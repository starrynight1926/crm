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
        $this->reset('editingId', 'label', 'optionsText');
        $this->fieldType = 'text';
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
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->guardSelectedOrg();
        $this->validate([
            'label' => 'required|string|max:100',
            'fieldType' => 'required|in:text,number,date,select',
        ], [], ['label' => 'tên trường']);

        $options = $this->fieldType === 'select'
            ? array_values(array_filter(array_map('trim', explode("\n", $this->optionsText))))
            : null;

        if ($this->fieldType === 'select' && ($options === null || count($options) < 2) ) {
            $this->addError('optionsText', 'Danh sách chọn cần ít nhất 2 lựa chọn (mỗi dòng một lựa chọn).');
            return;
        }

        $orgId = $this->selectedOrgId !== '' ? (int) $this->selectedOrgId : null;

        if ($this->editingId) {
            CustomField::findOrFail($this->editingId)->update([
                'label' => $this->label,
                'field_type' => $this->fieldType,
                'options' => $options,
                'required' => $this->required,
            ]);
        } else {
            $base = Str::slug($this->label, '_') ?: 'field';
            $key = $base;
            $i = 1;
            while (CustomField::where('org_unit_id', $orgId)->where('key', $key)->exists()) {
                $key = $base . '_' . (++$i);
            }

            CustomField::create([
                'org_unit_id' => $orgId,
                'key' => $key,
                'label' => $this->label,
                'field_type' => $this->fieldType,
                'options' => $options,
                'required' => $this->required,
                'position' => (CustomField::where('org_unit_id', $orgId)->max('position') ?? 0) + 1,
            ]);
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
            session()->flash('error', 'Trường đã có dữ liệu trên lead — chỉ nên "Ngưng dùng" để giữ lịch sử.');
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
            <h1 class="text-3xl font-bold mb-2">Trường tùy biến theo phòng ban</h1>
            <p class="text-sm text-ink/60 max-w-2xl">
                Mỗi phòng ban tự định nghĩa các trường dữ liệu riêng cho khách hàng. Trường mức <strong>công ty</strong> áp cho mọi bộ phận;
                trường bắt buộc thì bộ phận đó phải điền khi cập nhật lead.
            </p>
        </div>
        <button wire:click="openCreate" class="bg-gold-600 hover:bg-gold-700 text-white font-semibold px-5 py-2.5 rounded-md text-sm">
            + Thêm trường
        </button>
    </div>

    @if (session('error'))
        <p class="mb-4 text-sm text-red-600 bg-red-50 border border-red-200 rounded-md px-4 py-2">{{ session('error') }}</p>
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

        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs uppercase tracking-wider text-ink/50 bg-gold-50/60">
                    <th class="px-5 py-3 font-semibold">Tên trường</th>
                    <th class="px-5 py-3 font-semibold">Key</th>
                    <th class="px-5 py-3 font-semibold">Kiểu</th>
                    <th class="px-5 py-3 font-semibold">Bắt buộc</th>
                    <th class="px-5 py-3 font-semibold">Trạng thái</th>
                    <th class="px-5 py-3 font-semibold text-right">Hành động</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gold-100">
                @forelse ($fields as $field)
                    <tr class="hover:bg-gold-50/40 {{ $field->active ? '' : 'opacity-50' }}">
                        <td class="px-5 py-3.5 font-semibold">{{ $field->label }}</td>
                        <td class="px-5 py-3.5"><code class="text-xs text-ink/50">{{ $field->key }}</code></td>
                        <td class="px-5 py-3.5">
                            {{ \App\Models\CustomField::TYPES[$field->field_type] }}
                            @if ($field->field_type === 'select')
                                <span class="text-xs text-ink/40">({{ count($field->options ?? []) }} lựa chọn)</span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5">
                            @if ($field->required)
                                <span class="text-xs font-semibold text-red-700 bg-red-50 border border-red-200 px-2 py-0.5 rounded">Bắt buộc</span>
                            @else
                                <span class="text-xs text-ink/40">Tùy chọn</span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-xs font-medium {{ $field->active ? 'text-green-700' : 'text-ink/40' }}">
                            {{ $field->active ? 'Đang dùng' : 'Ngưng dùng' }}
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

    {{-- Modal thêm/sửa field --}}
    @if ($showModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-ink/40" wire:click="$set('showModal', false)"></div>
            <div class="relative bg-white rounded-xl shadow-xl border border-gold-200 w-full max-w-md p-7">
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
                    @if ($fieldType === 'select')
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-widest text-ink/60 mb-1.5">Các lựa chọn (mỗi dòng một lựa chọn)</label>
                            <textarea wire:model="optionsText" rows="4" placeholder="Dưới 100 triệu&#10;100–500 triệu&#10;Trên 500 triệu" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500"></textarea>
                            @error('optionsText')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>
                    @endif
                    <label class="flex items-center gap-2.5 text-sm cursor-pointer">
                        <input type="checkbox" wire:model="required" class="rounded border-gold-300 text-gold-600 focus:ring-gold-500 w-5 h-5">
                        Bắt buộc điền khi cập nhật lead
                    </label>
                </div>
                <div class="flex justify-end gap-3 mt-7">
                    <button wire:click="$set('showModal', false)" class="text-sm text-ink/60 px-4 py-2">Hủy</button>
                    <button wire:click="save" class="bg-gold-600 hover:bg-gold-700 text-white font-semibold text-sm px-6 py-2 rounded-md">Lưu</button>
                </div>
            </div>
        </div>
    @endif
</div>
