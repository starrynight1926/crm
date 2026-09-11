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

    /** Kiểu báo cáo: 'aggregate' (đếm option) hoặc 'list' (bảng từng khách). */
    public string $mode = 'aggregate';

    // ─── List mode form ──
    public string $listDateField = 'received_date';
    public string $listDateRange = 'this_month';
    public array $listClassifications = [];
    public array $listSourceGroups = [];
    public array $listBookingStatuses = [];
    /** Cột lead hiển thị. */
    public array $listColumns = ['stt', 'received_date', 'facility', 'name', 'birthday', 'source_group', 'note'];

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

    /**
     * Danh sách "field ảo" hệ thống — id âm để phân biệt với CustomField.
     * group: 'booking' (5 chỉ số booking) | 'customer' (breakdown theo classification/booking_status).
     */
    public const SYSTEM_FIELDS = [
        -1  => ['label' => 'Lần đầu',        'field_type' => 'tick',   'source' => 'first_visit',          'group' => 'booking'],
        -2  => ['label' => 'Trở lại',        'field_type' => 'tick',   'source' => 'return',               'group' => 'booking'],
        -3  => ['label' => 'Đã đặt booking', 'field_type' => 'tick',   'source' => 'booked',               'group' => 'booking'],
        -4  => ['label' => 'Show',           'field_type' => 'tick',   'source' => 'classification_show',  'group' => 'booking'],
        -5  => ['label' => 'Close',          'field_type' => 'tick',   'source' => 'classification_close', 'group' => 'booking'],
        -10 => ['label' => 'Phân loại kết quả', 'field_type' => 'select', 'source' => 'classification',    'group' => 'customer'],
        -11 => ['label' => 'Trạng thái đặt lịch', 'field_type' => 'select', 'source' => 'booking_status', 'group' => 'customer'],
    ];

    /**
     * Trường tùy biến của team dùng được cho báo cáo: select (≥2 option) + tick.
     * Ưu tiên subtree của team; nếu subtree không có field → walk up ancestor gần nhất có field.
     * Cuối danh sách luôn append các field hệ thống (Lần đầu / Trở lại / Booking / Show / Close).
     */
    public function reportFields()
    {
        if (! $this->orgUnitId) {
            return collect();
        }

        $fields = $this->reportFieldsForSubtree($this->orgUnitId);
        if ($fields->isEmpty()) {
            // Fallback: bò ngược lên ancestor, dừng ở node đầu tiên mà subtree có field.
            $current = OrgUnit::find($this->orgUnitId);
            while ($current?->parent_id) {
                $parent = OrgUnit::find($current->parent_id);
                if (! $parent) break;
                $fields = $this->reportFieldsForSubtree($parent->id);
                if ($fields->isNotEmpty()) break;
                $current = $parent;
            }
        }

        // Gắn thuộc tính group cho CustomField (grouping theo org_unit khi render).
        $fields = $fields->map(function ($f) {
            $f->group = 'custom';
            return $f;
        });

        // Append system pseudo fields (id âm) — group 'booking' và 'customer'.
        foreach (self::SYSTEM_FIELDS as $id => $def) {
            $options = match ($def['source']) {
                'classification' => array_keys(\App\Models\Lead::CLASSIFICATIONS),
                'booking_status' => array_keys(\App\Models\Lead::BOOKING_STATUSES),
                default => [],
            };
            $labels = match ($def['source']) {
                'classification' => \App\Models\Lead::CLASSIFICATIONS,
                'booking_status' => \App\Models\Lead::BOOKING_STATUSES,
                default => [],
            };
            $fields->push((object) [
                'id' => $id,
                'label' => $def['label'],
                'field_type' => $def['field_type'],
                'options' => $options,
                'option_labels' => $labels,
                'is_system' => true,
                'group' => $def['group'],
            ]);
        }
        return $fields;
    }

    private function reportFieldsForSubtree(int $orgId)
    {
        $subtree = OrgUnit::find($orgId)?->subtreeIds() ?? [];
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
        $this->reset(['editingId', 'name', 'sel', 'tickOn', 'listClassifications', 'listSourceGroups', 'listBookingStatuses']);
        $this->showTotals = true;
        $this->showByOwner = false;
        $this->mode = 'aggregate';
        $this->listDateField = 'received_date';
        $this->listDateRange = 'this_month';
        $this->listColumns = ['stt', 'received_date', 'facility', 'name', 'birthday', 'source_group', 'note'];
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
        $this->mode = $tpl->mode();

        if ($this->mode === 'list') {
            $f = $tpl->filters();
            $this->listDateField = $f['date_field'] ?? 'received_date';
            $this->listDateRange = $f['date_range'] ?? 'this_month';
            $this->listClassifications = $f['classification'] ?? [];
            $this->listSourceGroups = $f['source_group'] ?? [];
            $this->listBookingStatuses = $f['booking_status'] ?? [];
            $this->listColumns = $tpl->columns();
        } else {
            foreach ($tpl->columns() as $col) {
                if (($col['type'] ?? '') === 'tick') {
                    $this->tickOn[$col['field_id']] = true;
                } else {
                    $this->sel[$col['field_id']] = $col['options'] ?? [];
                }
            }
            $this->showTotals = $tpl->showTotals();
            $this->showByOwner = $tpl->showByOwner();
        }

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

        if ($this->mode === 'list') {
            $config = $this->buildListConfig();
        } else {
            $config = $this->buildAggregateConfig();
        }
        if ($config === null) return; // đã addError

        ReportTemplate::updateOrCreate(
            ['id' => $this->editingId],
            [
                'org_unit_id' => $this->orgUnitId,
                'name' => $this->name,
                'config' => $config,
                'created_by' => auth()->id(),
            ]
        );

        $this->editing = false;
        $this->resetForm();
        $this->dispatch('templates-updated');
    }

    private function buildAggregateConfig(): ?array
    {
        $fields = $this->reportFields()->keyBy('id');
        $columns = [];
        foreach ($fields as $f) {
            if ($f->field_type === 'tick') {
                if (! empty($this->tickOn[$f->id])) {
                    $columns[] = ['field_id' => $f->id, 'type' => 'tick'];
                }
                continue;
            }
            $chosen = array_values(array_filter($f->options ?? [], fn ($o) => in_array($o, $this->sel[$f->id] ?? [], true)));
            if ($chosen !== []) {
                $columns[] = ['field_id' => $f->id, 'type' => 'select', 'options' => $chosen];
            }
        }
        if ($columns === []) {
            $this->addError('name', 'Cần chọn ít nhất một trường/option để thống kê.');
            return null;
        }
        if (! $this->showTotals && ! $this->showByOwner) {
            $this->addError('name', 'Chọn ít nhất một kiểu hiển thị (bảng tổng hoặc theo người).');
            return null;
        }
        return [
            'mode' => 'aggregate',
            'columns' => $columns,
            'views' => ['totals' => $this->showTotals, 'by_owner' => $this->showByOwner],
        ];
    }

    private function buildListConfig(): ?array
    {
        if (empty($this->listColumns)) {
            $this->addError('name', 'Chọn ít nhất một cột hiển thị.');
            return null;
        }
        $allowedColumns = array_intersect($this->listColumns, array_keys(ReportTemplate::LIST_COLUMNS));
        if (empty($allowedColumns)) {
            $this->addError('name', 'Cột hiển thị không hợp lệ.');
            return null;
        }
        $filters = ['date_field' => $this->listDateField, 'date_range' => $this->listDateRange];
        if (! empty($this->listClassifications)) $filters['classification'] = array_values($this->listClassifications);
        if (! empty($this->listSourceGroups))    $filters['source_group']   = array_values($this->listSourceGroups);
        if (! empty($this->listBookingStatuses)) $filters['booking_status'] = array_values($this->listBookingStatuses);
        return [
            'mode' => 'list',
            'filters' => $filters,
            'columns' => array_values($allowedColumns),
        ];
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

                            {{-- Toggle Kiểu báo cáo --}}
                            <div>
                                <label class="text-xs font-semibold text-ink/50 block mb-2">Kiểu báo cáo</label>
                                <div class="flex gap-2">
                                    <button type="button" wire:click="$set('mode', 'aggregate')"
                                            class="flex-1 px-4 py-2.5 rounded-md text-sm font-semibold border {{ $mode === 'aggregate' ? 'bg-gold-600 text-white border-gold-600' : 'bg-white text-ink/70 border-gold-200 hover:bg-gold-50' }}">
                                        📊 Đếm option
                                        <span class="block text-[10px] font-normal opacity-70">Bảng thống kê theo trường select/tick</span>
                                    </button>
                                    <button type="button" wire:click="$set('mode', 'list')"
                                            class="flex-1 px-4 py-2.5 rounded-md text-sm font-semibold border {{ $mode === 'list' ? 'bg-gold-600 text-white border-gold-600' : 'bg-white text-ink/70 border-gold-200 hover:bg-gold-50' }}">
                                        📋 Danh sách chi tiết
                                        <span class="block text-[10px] font-normal opacity-70">Bảng từng khách + export Excel</span>
                                    </button>
                                </div>
                            </div>

                            {{-- ═══════════════ LIST MODE ═══════════════ --}}
                            @if ($mode === 'list')
                                <div class="border border-gold-100 rounded-lg p-4 space-y-4">
                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="text-xs font-semibold text-ink/50 block mb-1">Lọc theo ngày (field)</label>
                                            <select wire:model="listDateField" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm">
                                                @foreach (\App\Models\ReportTemplate::DATE_FIELDS as $key => $label)
                                                    <option value="{{ $key }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div>
                                            <label class="text-xs font-semibold text-ink/50 block mb-1">Khoảng thời gian</label>
                                            <select wire:model="listDateRange" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm">
                                                @foreach (\App\Models\ReportTemplate::DATE_RANGES as $key => $label)
                                                    <option value="{{ $key }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="text-xs font-semibold text-ink/50 block mb-1.5">Lọc phân loại kết quả <span class="text-ink/30">(để trống = tất cả)</span></label>
                                        <div class="flex flex-wrap gap-x-4 gap-y-1.5">
                                            @foreach (\App\Models\Lead::CLASSIFICATIONS as $key => $label)
                                                <label class="flex items-center gap-1.5 text-sm cursor-pointer">
                                                    <input type="checkbox" wire:model="listClassifications" value="{{ $key }}" class="rounded border-gold-300 w-4 h-4">
                                                    {{ $label }}
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>

                                    <div>
                                        <label class="text-xs font-semibold text-ink/50 block mb-1.5">Lọc nguồn <span class="text-ink/30">(để trống = tất cả)</span></label>
                                        <div class="flex flex-wrap gap-x-4 gap-y-1.5">
                                            @foreach (\App\Models\Lead::SOURCE_GROUPS as $key => $label)
                                                <label class="flex items-center gap-1.5 text-sm cursor-pointer">
                                                    <input type="checkbox" wire:model="listSourceGroups" value="{{ $key }}" class="rounded border-gold-300 w-4 h-4">
                                                    {{ $label }}
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>

                                    <div>
                                        <label class="text-xs font-semibold text-ink/50 block mb-1.5">Lọc trạng thái đặt lịch <span class="text-ink/30">(để trống = tất cả)</span></label>
                                        <div class="flex flex-wrap gap-x-4 gap-y-1.5">
                                            @foreach (\App\Models\Lead::BOOKING_STATUSES as $key => $label)
                                                <label class="flex items-center gap-1.5 text-sm cursor-pointer">
                                                    <input type="checkbox" wire:model="listBookingStatuses" value="{{ $key }}" class="rounded border-gold-300 w-4 h-4">
                                                    {{ $label }}
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>

                                    <div>
                                        <label class="text-xs font-semibold text-ink/50 block mb-1.5">Cột hiển thị <span class="text-ink/30">(kéo để sắp lại thứ tự)</span></label>
                                        @php
                                            $_allCols = \App\Models\ReportTemplate::LIST_COLUMNS;
                                            // Ordered: đã chọn theo listColumns; unordered: chưa chọn.
                                            $_selectedOrdered = array_values(array_filter($listColumns, fn ($k) => isset($_allCols[$k])));
                                            $_unselected = array_diff(array_keys($_allCols), $_selectedOrdered);
                                        @endphp
                                        <div x-data="{
                                            selected: @js($_selectedOrdered),
                                            unselected: @js(array_values($_unselected)),
                                            labels: @js($_allCols),
                                            sync() { $wire.set('listColumns', this.selected); },
                                            add(key) {
                                                this.selected.push(key);
                                                this.unselected = this.unselected.filter(k => k !== key);
                                                this.sync();
                                            },
                                            remove(key) {
                                                this.selected = this.selected.filter(k => k !== key);
                                                if (! this.unselected.includes(key)) this.unselected.push(key);
                                                this.sync();
                                            },
                                            initSortable() {
                                                if (typeof Sortable === 'undefined') return;
                                                const self = this;
                                                Sortable.create(this.$refs.selectedList, {
                                                    animation: 150,
                                                    handle: '.drag-handle',
                                                    ghostClass: 'opacity-40',
                                                    chosenClass: 'ring-2',
                                                    dragClass: 'shadow-lg',
                                                    onEnd(evt) {
                                                        if (evt.oldIndex === evt.newIndex) return;
                                                        // Đọc thứ tự thực tế từ DOM sau khi Sortable đã move
                                                        // (tránh xung đột index giữa Sortable + Alpine x-for re-render).
                                                        const newOrder = Array.from(self.$refs.selectedList.querySelectorAll('[data-key]'))
                                                            .map(el => el.getAttribute('data-key'));
                                                        self.selected = newOrder;
                                                        self.sync();
                                                    },
                                                });
                                            },
                                        }" x-init="initSortable()" class="space-y-3">
                                            {{-- Đã chọn — kéo thả để sắp thứ tự --}}
                                            <div>
                                                <p class="text-[10px] font-bold uppercase text-gold-700 mb-1">Đã chọn (kéo icon ≡ để đổi vị trí)</p>
                                                <div x-ref="selectedList" class="border border-gold-200 rounded-md p-2 min-h-[3rem] space-y-1 bg-gold-50/30">
                                                    <template x-for="(key, idx) in selected" :key="key">
                                                        <div :data-key="key" class="flex items-center gap-2 bg-white border border-gold-200 rounded px-2.5 py-1.5 text-sm hover:border-gold-400 select-none">
                                                            <span class="drag-handle cursor-grab active:cursor-grabbing text-ink/30 hover:text-gold-600 px-0.5">
                                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 8h16M4 16h16"/></svg>
                                                            </span>
                                                            <span class="text-[10px] font-bold text-ink/40 w-4 text-right" x-text="idx + 1"></span>
                                                            <span class="flex-1" x-text="labels[key]"></span>
                                                            <button type="button" @click="remove(key)" class="text-red-400 hover:text-red-600 text-xs">✕</button>
                                                        </div>
                                                    </template>
                                                    <p x-show="selected.length === 0" class="text-xs text-ink/40 italic py-2">Chưa chọn cột nào — thêm từ danh sách bên dưới.</p>
                                                </div>
                                            </div>
                                            {{-- Chưa chọn — bấm để add --}}
                                            <div>
                                                <p class="text-[10px] font-bold uppercase text-ink/50 mb-1">Chưa chọn (bấm để thêm)</p>
                                                <div class="flex flex-wrap gap-1.5">
                                                    <template x-for="key in unselected" :key="key">
                                                        <button type="button" @click="add(key)"
                                                                class="text-xs px-2.5 py-1 rounded-full border border-gold-200 hover:bg-gold-50 hover:border-gold-400">
                                                            + <span x-text="labels[key]"></span>
                                                        </button>
                                                    </template>
                                                    <p x-show="unselected.length === 0" class="text-xs text-ink/40 italic">Đã thêm hết cột có sẵn.</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @else
                            {{-- ═══════════════ AGGREGATE MODE ═══════════════ --}}
                            <div>
                                <label class="text-xs font-semibold text-ink/50 block mb-2">Chọn trường & option làm cột thống kê</label>
                                @php
                                    $groups = $fields->groupBy('group');
                                    $customByOrg = ($groups['custom'] ?? collect())->groupBy('org_unit_id');
                                    $orgNames = \App\Models\OrgUnit::whereIn('id', $customByOrg->keys()->all() ?: [-1])->pluck('name', 'id');
                                @endphp

                                {{-- CARD: Booking (chỉ số hệ thống) --}}
                                @if (($groups['booking'] ?? collect())->isNotEmpty())
                                    <div x-data="{ open: true }" class="border border-gold-100 rounded-lg mb-2">
                                        <button type="button" @click="open = !open" class="w-full flex items-center justify-between px-4 py-3 hover:bg-gold-50">
                                            <span class="font-semibold text-sm text-gold-700">📅 Booking <span class="text-xs font-normal text-ink/40">({{ $groups['booking']->count() }} chỉ số)</span></span>
                                            <svg class="w-4 h-4 transition-transform" :class="open && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
                                        </button>
                                        <div x-show="open" x-cloak class="px-4 pb-3 space-y-2 border-t border-gold-100 pt-3">
                                            @foreach ($groups['booking'] as $f)
                                                <label class="flex items-center gap-2 text-sm cursor-pointer">
                                                    <input type="checkbox" wire:model="tickOn.{{ $f->id }}" class="rounded border-gold-300 text-gold-600 w-4 h-4">
                                                    <span>{{ $f->label }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                {{-- CARD: Trường khách hàng (hệ thống) — classification + booking_status breakdown --}}
                                @if (($groups['customer'] ?? collect())->isNotEmpty())
                                    <div x-data="{ open: false }" class="border border-gold-100 rounded-lg mb-2">
                                        <button type="button" @click="open = !open" class="w-full flex items-center justify-between px-4 py-3 hover:bg-gold-50">
                                            <span class="font-semibold text-sm text-gold-700">👤 Trường khách hàng <span class="text-xs font-normal text-ink/40">({{ $groups['customer']->count() }} field, mỗi field breakdown theo option)</span></span>
                                            <svg class="w-4 h-4 transition-transform" :class="open && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
                                        </button>
                                        <div x-show="open" x-cloak class="px-4 pb-3 border-t border-gold-100 pt-3 space-y-3">
                                            @foreach ($groups['customer'] as $f)
                                                <div>
                                                    <div class="flex items-center justify-between mb-1.5">
                                                        <span class="font-semibold text-sm">{{ $f->label }}</span>
                                                        <button type="button" wire:click="toggleAll({{ $f->id }})" class="text-xs text-gold-700 hover:underline">Chọn/bỏ tất cả</button>
                                                    </div>
                                                    <div class="flex flex-wrap gap-x-4 gap-y-1.5">
                                                        @foreach ($f->options as $opt)
                                                            <label class="flex items-center gap-1.5 text-sm cursor-pointer">
                                                                <input type="checkbox" wire:model="sel.{{ $f->id }}" value="{{ $opt }}" class="rounded border-gold-300 text-gold-600 w-4 h-4">
                                                                {{ $f->option_labels[$opt] ?? $opt }}
                                                            </label>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                {{-- CARD: Trường tùy biến (CustomField) — nested collapse theo org --}}
                                <div x-data="{ open: false }" class="border border-gold-100 rounded-lg mb-2">
                                    <button type="button" @click="open = !open" class="w-full flex items-center justify-between px-4 py-3 hover:bg-gold-50">
                                        <span class="font-semibold text-sm text-gold-700">⚙️ Trường tùy biến <span class="text-xs font-normal text-ink/40">({{ ($groups['custom'] ?? collect())->count() }} field)</span></span>
                                        <svg class="w-4 h-4 transition-transform" :class="open && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
                                    </button>
                                    <div x-show="open" x-cloak class="px-4 pb-3 border-t border-gold-100 pt-3 space-y-2">
                                        @forelse ($customByOrg as $orgId => $orgFields)
                                            <div x-data="{ sub: true }" class="border border-gold-100 rounded">
                                                <button type="button" @click="sub = !sub" class="w-full flex items-center justify-between px-3 py-2 text-xs font-semibold text-ink/70 hover:bg-gold-50">
                                                    <span>{{ $orgNames[$orgId] ?? 'Org #' . $orgId }} — {{ $orgFields->count() }} field</span>
                                                    <svg class="w-3 h-3 transition-transform" :class="sub && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
                                                </button>
                                                <div x-show="sub" x-cloak class="px-3 pb-2 border-t border-gold-100 pt-2 space-y-2">
                                                    @foreach ($orgFields as $f)
                                                        <div>
                                                            @if ($f->field_type === 'tick')
                                                                <label class="flex items-center gap-2 text-sm cursor-pointer">
                                                                    <input type="checkbox" wire:model="tickOn.{{ $f->id }}" class="rounded border-gold-300 text-gold-600 w-4 h-4">
                                                                    <span>{{ $f->label }}</span>
                                                                    <span class="text-[10px] text-ink/40 border border-gold-200 rounded px-1.5 py-0.5">tick</span>
                                                                </label>
                                                            @else
                                                                <div class="flex items-center justify-between mb-1.5">
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
                                                    @endforeach
                                                </div>
                                            </div>
                                        @empty
                                            <p class="text-sm text-ink/40 px-2 py-3">Team này chưa có trường tùy biến để đưa vào báo cáo.</p>
                                        @endforelse
                                    </div>
                                </div>
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
                            @endif  {{-- end aggregate mode --}}

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
