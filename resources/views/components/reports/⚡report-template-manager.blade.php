<?php

use App\Models\CustomField;
use App\Models\OrgUnit;
use App\Models\ReportTemplate;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Quản lý MẪU BÁO CÁO của một team: chọn trường tùy biến + option nào làm cột thống kê.
 * Mở bằng event 'open-template-manager' (kèm teamId); lưu xong bắn 'templates-updated'.
 */
new class extends Component
{
    public bool $show = false;

    public ?int $orgUnitId = null;

    public string $orgUnitName = '';

    // Form
    public bool $editing = false;

    public ?int $editingId = null;

    public string $name = '';

    /** Map field_id => [option values đã chọn] (field select). */
    public array $sel = [];

    /** Map field_id => true (field tick được chọn). */
    public array $tickOn = [];

    /** Chế độ hiển thị: bảng tổng (theo funnel) và/hoặc bảng theo người phụ trách. */
    public bool $showTotals = true;

    public bool $showByOwner = false;

    #[On('open-template-manager')]
    public function open(int $teamId): void
    {
        $org = OrgUnit::find($teamId);
        if (! $org || ! $this->canManage($org)) {
            return;
        }
        $this->orgUnitId = $org->id;
        $this->orgUnitName = $org->name;
        $this->resetForm();
        $this->editing = false;
        $this->show = true;
    }

    public function close(): void
    {
        $this->show = false;
    }

    private function canManage(OrgUnit $org): bool
    {
        $user = auth()->user();
        return $user->hasPermission('report.view_all') || $user->hasPermission('field.manage');
    }

    /** Trường tùy biến của team dùng được cho báo cáo: select (≥2 option) + tick, thuộc subtree team. */
    public function reportFields()
    {
        if (! $this->orgUnitId) {
            return collect();
        }
        $subtree = OrgUnit::find($this->orgUnitId)?->subtreeIds() ?? [];

        return CustomField::query()
            ->where('active', true)
            ->where('status', CustomField::STATUS_ACTIVE)
            ->whereIn('org_unit_id', $subtree ?: [-1])
            ->whereIn('field_type', ['select', 'tick'])
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->filter(fn ($f) => $f->field_type === 'tick' || count($f->options ?? []) >= 2)
            ->values();
    }

    public function templates()
    {
        if (! $this->orgUnitId) {
            return collect();
        }

        return ReportTemplate::where('org_unit_id', $this->orgUnitId)->orderBy('name')->get();
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'sel', 'tickOn']);
        $this->showTotals = true;
        $this->showByOwner = false;
    }

    public function newTemplate(): void
    {
        $this->resetForm();
        $this->editing = true;
    }

    public function edit(int $id): void
    {
        $tpl = ReportTemplate::where('org_unit_id', $this->orgUnitId)->findOrFail($id);
        $this->resetForm();
        $this->editingId = $tpl->id;
        $this->name = $tpl->name;

        foreach ($tpl->columns() as $col) {
            if (($col['type'] ?? '') === 'tick') {
                $this->tickOn[$col['field_id']] = true;
            } else {
                $this->sel[$col['field_id']] = $col['options'] ?? [];
            }
        }
        $this->showTotals = $tpl->showTotals();
        $this->showByOwner = $tpl->showByOwner();

        $this->editing = true;
    }

    public function delete(int $id): void
    {
        ReportTemplate::where('org_unit_id', $this->orgUnitId)->where('id', $id)->delete();
        $this->dispatch('templates-updated');
    }

    /** Toàn bộ / bỏ chọn nhanh option của 1 field select. */
    public function toggleAll(int $fieldId): void
    {
        $field = $this->reportFields()->firstWhere('id', $fieldId);
        if (! $field) {
            return;
        }
        $all = $field->options ?? [];
        $this->sel[$fieldId] = count($this->sel[$fieldId] ?? []) === count($all) ? [] : $all;
    }

    public function save(): void
    {
        $this->validate(['name' => 'required|string|max:120']);

        $fields = $this->reportFields()->keyBy('id');
        $columns = [];
        foreach ($fields as $f) {
            if ($f->field_type === 'tick') {
                if (! empty($this->tickOn[$f->id])) {
                    $columns[] = ['field_id' => $f->id, 'type' => 'tick'];
                }
                continue;
            }
            // select: giữ đúng thứ tự option đã khai, chỉ lấy option được tick
            $chosen = array_values(array_filter($f->options ?? [], fn ($o) => in_array($o, $this->sel[$f->id] ?? [], true)));
            if ($chosen !== []) {
                $columns[] = ['field_id' => $f->id, 'type' => 'select', 'options' => $chosen];
            }
        }

        if ($columns === []) {
            $this->addError('name', 'Cần chọn ít nhất một trường/option để thống kê.');
            return;
        }

        if (! $this->showTotals && ! $this->showByOwner) {
            $this->addError('name', 'Chọn ít nhất một kiểu hiển thị (bảng tổng hoặc theo người).');
            return;
        }

        ReportTemplate::updateOrCreate(
            ['id' => $this->editingId],
            [
                'org_unit_id' => $this->orgUnitId,
                'name' => $this->name,
                'config' => [
                    'columns' => $columns,
                    'views' => ['totals' => $this->showTotals, 'by_owner' => $this->showByOwner],
                ],
                'created_by' => auth()->id(),
            ]
        );

        $this->editing = false;
        $this->resetForm();
        $this->dispatch('templates-updated');
    }

    public function with(): array
    {
        return [
            'fields' => $this->show ? $this->reportFields() : collect(),
            'tplList' => $this->show ? $this->templates() : collect(),
        ];
    }
};
?>

<div>
    @if ($show)
        <div class="fixed inset-0 z-40 bg-black/40 flex items-start justify-center overflow-y-auto py-10" wire:key="tpl-modal">
            <div class="bg-white rounded-xl shadow-xl w-full max-w-3xl mx-4" @click.outside="$wire.close()">
                <div class="flex items-center justify-between px-6 py-4 border-b border-gold-100">
                    <h2 class="font-bold text-lg">Mẫu báo cáo — <span class="text-gold-700">{{ $orgUnitName }}</span></h2>
                    <button wire:click="close" class="text-ink/40 hover:text-ink text-xl leading-none">&times;</button>
                </div>

                <div class="p-6">
                    @if (! $editing)
                        {{-- Danh sách mẫu --}}
                        <div class="flex items-center justify-between mb-4">
                            <p class="text-sm text-ink/60">Các mẫu báo cáo của team này.</p>
                            <button wire:click="newTemplate" class="bg-gold-600 hover:bg-gold-700 text-white text-sm font-semibold px-4 py-2 rounded-md">+ Tạo mẫu</button>
                        </div>
                        <div class="divide-y divide-gold-100 border border-gold-100 rounded-lg">
                            @forelse ($tplList as $tpl)
                                <div class="flex items-center justify-between px-4 py-3">
                                    <div>
                                        <div class="font-semibold text-sm">{{ $tpl->name }}</div>
                                        <div class="text-xs text-ink/40">{{ count($tpl->columns()) }} trường • {{ collect([$tpl->showTotals() ? 'bảng tổng' : null, $tpl->showByOwner() ? 'theo người' : null])->filter()->join(' + ') ?: 'chưa bật hiển thị' }}</div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <button wire:click="edit({{ $tpl->id }})" class="text-xs font-semibold text-gold-700 border border-gold-200 rounded px-3 py-1.5 hover:bg-gold-50">Sửa</button>
                                        <button wire:click="delete({{ $tpl->id }})" wire:confirm="Xóa mẫu này?" class="text-xs font-semibold text-red-600 border border-red-200 rounded px-3 py-1.5 hover:bg-red-50">Xóa</button>
                                    </div>
                                </div>
                            @empty
                                <p class="px-4 py-8 text-center text-sm text-ink/40">Chưa có mẫu nào. Bấm "Tạo mẫu" để bắt đầu.</p>
                            @endforelse
                        </div>
                    @else
                        {{-- Form tạo/sửa --}}
                        <div class="space-y-4">
                            <div>
                                <label class="text-xs font-semibold text-ink/50 block mb-1">Tên mẫu</label>
                                <input type="text" wire:model="name" placeholder="VD: Funnel theo tháng" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500">
                                @error('name')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                            </div>

                            <div>
                                <label class="text-xs font-semibold text-ink/50 block mb-2">Chọn trường & option làm cột thống kê</label>
                                @forelse ($fields as $f)
                                    <div class="border border-gold-100 rounded-lg p-3 mb-2">
                                        @if ($f->field_type === 'tick')
                                            <label class="flex items-center gap-2 text-sm cursor-pointer">
                                                <input type="checkbox" wire:model="tickOn.{{ $f->id }}" class="rounded border-gold-300 text-gold-600 w-4 h-4">
                                                <span class="font-semibold">{{ $f->label }}</span>
                                                <span class="text-[10px] text-ink/40 border border-gold-200 rounded px-1.5 py-0.5">tick</span>
                                            </label>
                                        @else
                                            <div class="flex items-center justify-between mb-2">
                                                <span class="font-semibold text-sm">{{ $f->label }} <span class="text-[10px] text-ink/40 border border-gold-200 rounded px-1.5 py-0.5">select</span></span>
                                                <button type="button" wire:click="toggleAll({{ $f->id }})" class="text-xs text-gold-700 hover:underline">Chọn/bỏ tất cả</button>
                                            </div>
                                            <div class="flex flex-wrap gap-x-4 gap-y-1.5">
                                                @foreach ($f->options ?? [] as $opt)
                                                    <label class="flex items-center gap-1.5 text-sm cursor-pointer">
                                                        <input type="checkbox" wire:model="sel.{{ $f->id }}" value="{{ $opt }}" class="rounded border-gold-300 text-gold-600 w-4 h-4">
                                                        {{ $f->optionLabel($opt) }}
                                                    </label>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                @empty
                                    <p class="text-sm text-ink/40">Team này chưa có trường tùy biến kiểu select/tick để đưa vào báo cáo.</p>
                                @endforelse
                            </div>

                            {{-- Kiểu hiển thị --}}
                            <div class="border-t border-gold-100 pt-4">
                                <label class="text-xs font-semibold text-ink/50 block mb-2">Kiểu hiển thị (dùng chung các cột đã chọn ở trên)</label>
                                <div class="space-y-1.5">
                                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                                        <input type="checkbox" wire:model="showTotals" class="rounded border-gold-300 text-gold-600 w-4 h-4">
                                        <span>Bảng tổng — thống kê theo funnel (1 dòng đếm + tỉ lệ)</span>
                                    </label>
                                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                                        <input type="checkbox" wire:model="showByOwner" class="rounded border-gold-300 text-gold-600 w-4 h-4">
                                        <span>Bảng theo người phụ trách — mỗi người 1 dòng (Total Lead + các cột)</span>
                                    </label>
                                </div>
                            </div>

                            <div class="flex items-center justify-end gap-2 pt-2">
                                <button wire:click="$set('editing', false)" class="text-sm font-semibold text-ink/60 px-4 py-2 rounded-md hover:bg-gold-50">Hủy</button>
                                <button wire:click="save" class="bg-gold-600 hover:bg-gold-700 text-white text-sm font-semibold px-5 py-2 rounded-md">Lưu mẫu</button>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
