<?php

use App\Models\AuditLog;
use App\Models\CustomField;
use App\Models\Lead;
use Livewire\Component;
use Livewire\WithPagination;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $fClassification = '';

    public string $fCamp = '';

    public string $fAdSource = '';

    public string $fDateFrom = '';

    public string $fDateTo = '';

    public bool $showExportModal = false;

    /** Key các cột được chọn để xuất (core: tên cột; custom: cf_{id}). */
    public array $exportCols = [];

    /** Id các lead đang tick (chọn hàng loạt). */
    public array $selected = [];

    public bool $selectAll = false;

    public function updated($property): void
    {
        if (in_array($property, ['search', 'fClassification', 'fCamp', 'fAdSource', 'fDateFrom', 'fDateTo'])) {
            $this->resetPage();
            $this->reset('selected', 'selectAll'); // đổi bộ lọc → bỏ chọn
        }
    }

    /** Tick "chọn tất cả" → chọn mọi lead trên trang hiện tại. */
    public function updatedSelectAll($value): void
    {
        $this->selected = $value
            ? $this->filteredQuery()->paginate(15)->pluck('id')->map(fn ($id) => (string) $id)->all()
            : [];
    }

    /** Xóa 1 lead (mềm) trong phạm vi user — quyền lead.delete. */
    public function deleteLead(int $id): void
    {
        abort_unless(auth()->user()->hasPermission('lead.delete'), 403);

        $lead = Lead::visibleTo(auth()->user())->find($id);
        if (! $lead) {
            return;
        }
        $lead->delete();
        AuditLog::record('delete', $lead, ['name' => $lead->name]);
        $this->selected = array_values(array_diff($this->selected, [(string) $id]));
        session()->flash('status', "Đã xóa khách hàng \"{$lead->name}\".");
    }

    /** Xóa hàng loạt các lead đang tick — quyền lead.delete. */
    public function deleteSelected(): void
    {
        abort_unless(auth()->user()->hasPermission('lead.delete'), 403);

        $ids = array_map('intval', $this->selected);
        $leads = Lead::visibleTo(auth()->user())->whereIn('id', $ids)->get();
        foreach ($leads as $lead) {
            $lead->delete();
            AuditLog::record('delete', $lead, ['name' => $lead->name, 'bulk' => true]);
        }
        $count = $leads->count();
        $this->reset('selected', 'selectAll');
        session()->flash('status', "Đã xóa {$count} khách hàng.");
    }

    /** Cột lõi có thể xuất: key => nhãn. */
    private function coreColumns(): array
    {
        return [
            'code' => 'Mã KH',
            'name' => 'Họ tên khách',
            'phone' => 'SĐT',
            'ad_source' => 'Nguồn',
            'receiver' => 'Người thu thập',
            'owner' => 'Người phụ trách',
            'received_date' => 'Ngày thu thập',
            'classification' => 'Phân loại',
            'region' => 'Khu vực',
            'camp' => 'Camp',
            'page' => 'Page',
            'status_1' => 'Tình trạng 1',
            'status_2' => 'Tình trạng 2',
            'note' => 'Ghi chú',
        ];
    }

    /** Trường tùy biến trong phạm vi user, làm cột xuất tùy chọn. */
    private function exportableCustomFields()
    {
        $orgIds = auth()->user()->visibleOrgUnitIds();

        return CustomField::query()
            ->where('active', true)
            ->where('status', CustomField::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('org_unit_id')
                ->when($orgIds !== [], fn ($qq) => $qq->orWhereIn('org_unit_id', $orgIds)))
            ->orderBy('org_unit_id')
            ->orderBy('position')
            ->get();
    }

    /** Tất cả key cột (core + custom) theo thứ tự hiển thị. */
    private function allExportKeys(): array
    {
        return array_merge(
            array_keys($this->coreColumns()),
            $this->exportableCustomFields()->map(fn ($f) => 'cf_' . $f->id)->all()
        );
    }

    public function openExport(): void
    {
        abort_unless(auth()->user()->hasPermission('lead.export'), 403);
        if ($this->exportCols === []) {
            $this->exportCols = $this->allExportKeys(); // mặc định: tất cả
        }
        $this->showExportModal = true;
    }

    public function selectAllExport(): void
    {
        $this->exportCols = $this->allExportKeys();
    }

    public function clearExport(): void
    {
        $this->exportCols = [];
    }

    /** Query lead theo bộ lọc hiện tại (dùng chung cho bảng + export). */
    private function filteredQuery()
    {
        $user = auth()->user();

        return Lead::query()
            ->visibleTo($user)
            ->with(['owner', 'receiver', 'orgUnit', 'customValues'])
            ->when($this->search, function ($q) {
                $normalized = Lead::normalizePhone($this->search);
                $q->where(fn ($qq) => $qq
                    ->where('name', 'like', "%{$this->search}%")
                    ->orWhere('code', 'like', "%{$this->search}%")
                    ->when($normalized, fn ($qqq) => $qqq->orWhere('phone', $normalized)));
            })
            ->when($this->fClassification, fn ($q) => $q->where('classification', $this->fClassification))
            ->when($this->fCamp, fn ($q) => $q->where('camp', $this->fCamp))
            ->when($this->fAdSource, fn ($q) => $q->where('ad_source', $this->fAdSource))
            ->when($this->fDateFrom, fn ($q) => $q->where('received_date', '>=', $this->fDateFrom))
            ->when($this->fDateTo, fn ($q) => $q->where('received_date', '<=', $this->fDateTo))
            ->orderByDesc('received_date')
            ->orderByDesc('id');
    }

    private function cellValue(Lead $lead, string $key, $cfs): string
    {
        return match ($key) {
            'code' => (string) $lead->code,
            'name' => (string) $lead->name,
            'phone' => (string) $lead->phoneFor(auth()->user()),
            'ad_source' => (string) $lead->ad_source,
            'receiver' => (string) $lead->receiver?->name,
            'owner' => (string) $lead->owner?->name,
            'received_date' => (string) $lead->received_date?->toDateString(),
            'classification' => $lead->classificationLabel(),
            'region' => (string) $lead->region,
            'camp' => (string) $lead->camp,
            'page' => (string) $lead->page,
            'status_1' => (string) $lead->status_1,
            'status_2' => (string) $lead->status_2,
            'note' => (string) $lead->note,
            default => (function () use ($lead, $key, $cfs) {
                $id = (int) str_replace('cf_', '', $key);
                $cf = $cfs->get($id);
                $raw = (string) ($lead->customValues->firstWhere('custom_field_id', $id)?->value ?? '');
                if ($cf && $cf->field_type === 'tick') {
                    return $raw !== '' ? 'Có' : '';
                }
                return $cf && $cf->field_type === 'select' ? $cf->optionLabel($raw) : $raw;
            })(),
        };
    }

    public function export()
    {
        abort_unless(auth()->user()->hasPermission('lead.export'), 403);

        // Giữ đúng thứ tự cột chuẩn, chỉ lấy cột được tick
        $cols = array_values(array_intersect($this->allExportKeys(), $this->exportCols));
        if ($cols === []) {
            $this->addError('exportCols', 'Chọn ít nhất một trường để xuất.');
            return;
        }

        $core = $this->coreColumns();
        $cfs = $this->exportableCustomFields()->keyBy('id');
        $cfLabels = CustomField::labelMap($cfs->values());

        $header = array_map(function ($key) use ($core, $cfs, $cfLabels) {
            if (isset($core[$key])) {
                return $core[$key];
            }
            $id = (int) str_replace('cf_', '', $key);
            return $cfLabels[$id] ?? $cfs->get($id)?->label ?? $key;
        }, $cols);

        $rows = $this->filteredQuery()->limit(10000)->get()
            ->map(fn ($lead) => array_map(fn ($key) => $this->cellValue($lead, $key, $cfs), $cols))
            ->all();

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([$header, ...$rows]);

        AuditLog::record('export', null, ['report' => 'leads', 'count' => count($rows), 'cols' => $cols]);

        $this->showExportModal = false;
        $filename = 'khach-hang-' . now()->format('Ymd-His') . '.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename);
    }

    public function with(): array
    {
        $user = auth()->user();

        $leads = $this->filteredQuery()->paginate(15);

        return [
            'leads' => $leads,
            'campOptions' => Lead::visibleTo($user)->whereNotNull('camp')->distinct()->orderBy('camp')->pluck('camp'),
            'adSourceOptions' => Lead::visibleTo($user)->whereNotNull('ad_source')->distinct()->orderBy('ad_source')->pluck('ad_source'),
            'exportCore' => $this->showExportModal ? $this->coreColumns() : [],
            'exportCustomFields' => $this->showExportModal ? $this->exportableCustomFields() : collect(),
            'canExport' => $user->hasPermission('lead.export'),
            'canUpdate' => $user->hasPermission('lead.update'),
            'canDelete' => $user->hasPermission('lead.delete'),
        ];
    }
};
?>

<div>
    @if (session('status'))
        <p class="mb-4 text-sm text-green-700 bg-green-50 border border-green-200 rounded-md px-4 py-2">{{ session('status') }}</p>
    @endif

    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <h1 class="text-3xl font-bold mb-1">Danh sách khách hàng</h1>
            <p class="text-sm text-ink/60">Quản lý và theo dõi các khách hàng tiềm năng trên tất cả các kênh.</p>
        </div>
        <div class="flex items-center gap-3">
            @if (auth()->user()->hasPermission('lead.import'))
                <a href="{{ route('leads.failed') }}" class="text-sm font-semibold text-ink/60 border border-gold-200 px-4 py-2.5 rounded-md hover:bg-gold-50">Lead lỗi</a>
                <a href="{{ route('leads.import') }}" class="text-sm font-semibold text-gold-700 border border-gold-300 px-4 py-2.5 rounded-md hover:bg-gold-50">⬆ Import</a>
            @endif
            @if ($canExport)
                <button wire:click="openExport" class="text-sm font-semibold text-gold-700 border border-gold-300 px-4 py-2.5 rounded-md hover:bg-gold-50">⬇ Export</button>
            @endif
            @if (auth()->user()->hasPermission('lead.create'))
                <a href="{{ route('leads.create') }}"
                   class="bg-gold-600 hover:bg-gold-700 text-white font-semibold px-5 py-2.5 rounded-md text-sm">
                    + Tạo mới Lead
                </a>
            @endif
        </div>
    </div>

    {{-- Bộ lọc --}}
    <div class="bg-white border border-gold-200 rounded-xl shadow-card px-5 py-4 mb-5 grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
        <div>
            <label class="block text-xs font-semibold text-ink/50 mb-1">Từ ngày</label>
            <input type="date" wire:model.live="fDateFrom" class="w-full border border-gold-200 rounded-md px-2.5 py-2 text-sm focus:outline-none focus:border-gold-500">
        </div>
        <div>
            <label class="block text-xs font-semibold text-ink/50 mb-1">Đến ngày</label>
            <input type="date" wire:model.live="fDateTo" class="w-full border border-gold-200 rounded-md px-2.5 py-2 text-sm focus:outline-none focus:border-gold-500">
        </div>
        <div>
            <label class="block text-xs font-semibold text-ink/50 mb-1">Chiến dịch</label>
            <select wire:model.live="fCamp" class="w-full border border-gold-200 rounded-md px-2.5 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                <option value="">Tất cả</option>
                @foreach ($campOptions as $camp)
                    <option value="{{ $camp }}">{{ $camp }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-ink/50 mb-1">Nguồn</label>
            <select wire:model.live="fAdSource" class="w-full border border-gold-200 rounded-md px-2.5 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                <option value="">Tất cả</option>
                @foreach ($adSourceOptions as $source)
                    <option value="{{ $source }}">{{ $source }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-ink/50 mb-1">Phân loại kết quả</label>
            <select wire:model.live="fClassification" class="w-full border border-gold-200 rounded-md px-2.5 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                <option value="">Tất cả</option>
                @foreach (\App\Models\Lead::CLASSIFICATIONS as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-ink/50 mb-1">Tìm kiếm</label>
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Tên hoặc SĐT..."
                   class="w-full border border-gold-200 rounded-md px-2.5 py-2 text-sm focus:outline-none focus:border-gold-500">
        </div>
    </div>

    {{-- Thanh thao tác hàng loạt --}}
    @if ($canDelete && count($selected) > 0)
        <div class="bg-gold-50 border border-gold-300 rounded-xl px-5 py-3 mb-3 flex items-center gap-4">
            <span class="text-sm font-semibold text-gold-800">Đã chọn {{ count($selected) }} khách hàng</span>
            <button wire:click="deleteSelected" wire:confirm="Xóa {{ count($selected) }} khách hàng đã chọn?"
                    class="text-sm font-semibold text-red-600 border border-red-300 hover:bg-red-50 px-4 py-1.5 rounded-md">🗑 Xóa đã chọn</button>
            <button wire:click="$set('selected', [])" class="text-sm text-ink/50 hover:underline">Bỏ chọn</button>
        </div>
    @endif

    {{-- Bảng --}}
    <div class="bg-white border border-gold-200 rounded-xl shadow-card overflow-x-auto">
        <table class="w-full text-sm whitespace-nowrap">
            <thead>
                <tr class="text-left text-xs uppercase tracking-wider text-ink/50 bg-gold-50/60">
                    @if ($canDelete)
                        <th class="px-4 py-3 font-semibold w-10">
                            <input type="checkbox" wire:model.live="selectAll" class="rounded border-gold-300 text-gold-600 w-4 h-4">
                        </th>
                    @endif
                    <th class="px-4 py-3 font-semibold w-12">STT</th>
                    <th class="px-4 py-3 font-semibold">Mã KH</th>
                    <th class="px-4 py-3 font-semibold">Ngày</th>
                    <th class="px-4 py-3 font-semibold">Page</th>
                    <th class="px-4 py-3 font-semibold">Tên khách hàng</th>
                    <th class="px-4 py-3 font-semibold">SĐT</th>
                    <th class="px-4 py-3 font-semibold">Camp</th>
                    <th class="px-4 py-3 font-semibold">Nguồn QC</th>
                    <th class="px-4 py-3 font-semibold">Khu vực</th>
                    <th class="px-4 py-3 font-semibold">Chia cho</th>
                    <th class="px-4 py-3 font-semibold">Phân loại</th>
                    @if ($canUpdate || $canDelete)
                        <th class="px-4 py-3 font-semibold text-right">Thao tác</th>
                    @endif
                </tr>
            </thead>
            <tbody class="divide-y divide-gold-100">
                @forelse ($leads as $lead)
                    <tr class="hover:bg-gold-50/40 cursor-pointer" onclick="window.location='{{ route('leads.show', $lead) }}'">
                        @if ($canDelete)
                            <td class="px-4 py-3" onclick="event.stopPropagation()">
                                <input type="checkbox" wire:model.live="selected" value="{{ $lead->id }}" class="rounded border-gold-300 text-gold-600 w-4 h-4">
                            </td>
                        @endif
                        <td class="px-4 py-3 text-ink/50">{{ $leads->firstItem() + $loop->index }}</td>
                        <td class="px-4 py-3 font-mono text-xs text-gold-700">{{ $lead->code ?: '—' }}</td>
                        <td class="px-4 py-3">{{ $lead->received_date->format('d/m/Y') }}</td>
                        <td class="px-4 py-3 text-ink/60">{{ $lead->page ?: '—' }}</td>
                        <td class="px-4 py-3 font-semibold text-gold-700">{{ $lead->name }}</td>
                        <td class="px-4 py-3 font-mono">{{ $lead->phoneFor(auth()->user()) }}</td>
                        <td class="px-4 py-3 text-ink/60">{{ $lead->camp ?: '—' }}</td>
                        <td class="px-4 py-3">
                            @if ($lead->ad_source)
                                <span class="text-xs bg-gold-50 border border-gold-200 px-2 py-0.5 rounded">{{ $lead->ad_source }}</span>
                            @else — @endif
                        </td>
                        <td class="px-4 py-3 text-ink/60">{{ $lead->region ?: '—' }}</td>
                        <td class="px-4 py-3">{{ $lead->owner?->name ?: '—' }}</td>
                        <td class="px-4 py-3">
                            @php
                                $cls = $lead->classification;
                                $badge = match(true) {
                                    in_array($cls, ['close', 'show', 'booking']) => 'bg-green-50 border-green-200 text-green-700',
                                    in_array($cls, ['klld', 'missed']) => 'bg-red-50 border-red-200 text-red-600',
                                    $cls === 'new' => 'bg-blue-50 border-blue-200 text-blue-700',
                                    default => 'bg-gold-50 border-gold-200 text-gold-700',
                                };
                            @endphp
                            <span class="text-xs border px-2 py-0.5 rounded-full {{ $badge }}">{{ $lead->classificationLabel() }}</span>
                        </td>
                        @if ($canUpdate || $canDelete)
                            <td class="px-4 py-3 text-right" onclick="event.stopPropagation()">
                                <div class="flex items-center justify-end gap-1.5">
                                    @if ($canUpdate)
                                        <a href="{{ route('leads.edit', $lead) }}" title="Sửa"
                                           class="inline-flex items-center justify-center w-8 h-8 rounded-md border border-gold-200 text-gold-700 hover:bg-gold-50">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                                        </a>
                                    @endif
                                    @if ($canDelete)
                                        <button wire:click="deleteLead({{ $lead->id }})" wire:confirm="Xóa khách hàng &quot;{{ $lead->name }}&quot;?" title="Xóa"
                                                class="inline-flex items-center justify-center w-8 h-8 rounded-md border border-red-200 text-red-600 hover:bg-red-50">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
                                        </button>
                                    @endif
                                </div>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="14" class="px-4 py-10 text-center text-ink/40">Không có khách hàng nào trong phạm vi của bạn.</td></tr>
                @endforelse
            </tbody>
        </table>

        <div class="px-5 py-4 border-t border-gold-100 flex items-center justify-between text-sm text-ink/60">
            <span>Hiển thị {{ $leads->count() }} trong tổng số {{ number_format($leads->total()) }} khách hàng</span>
            {{ $leads->links() }}
        </div>
    </div>

    {{-- Modal chọn trường để xuất Excel --}}
    @if ($showExportModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-ink/40" wire:click="$set('showExportModal', false)"></div>
            <div class="relative bg-white rounded-xl shadow-xl border border-gold-200 w-full max-w-lg p-7 max-h-[90vh] overflow-y-auto">
                <h3 class="text-xl font-bold mb-1">Xuất Excel khách hàng</h3>
                <p class="text-sm text-ink/50 mb-4">Chọn các trường muốn xuất. Danh sách xuất theo đúng bộ lọc đang áp.</p>

                <div class="flex items-center gap-3 mb-3">
                    <button wire:click="selectAllExport" type="button" class="text-xs font-semibold text-gold-700 border border-gold-300 px-3 py-1.5 rounded-md hover:bg-gold-50">Chọn tất cả trường</button>
                    <button wire:click="clearExport" type="button" class="text-xs font-semibold text-ink/50 border border-gold-200 px-3 py-1.5 rounded-md hover:bg-gold-50">Bỏ chọn</button>
                    <span class="text-xs text-ink/40 ml-auto">Đã chọn {{ count($exportCols) }}</span>
                </div>
                @error('exportCols')<p class="text-xs text-red-600 mb-2">{{ $message }}</p>@enderror

                <div class="border border-gold-100 rounded-lg p-3 max-h-72 overflow-y-auto">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-ink/40 mb-1.5">Trường mặc định</p>
                    <div class="grid grid-cols-2 gap-1.5 mb-3">
                        @foreach ($exportCore as $key => $label)
                            <label class="flex items-center gap-2 text-sm cursor-pointer hover:bg-gold-50 rounded px-2 py-1">
                                <input type="checkbox" wire:model="exportCols" value="{{ $key }}" class="rounded border-gold-300 text-gold-600 focus:ring-gold-500 w-4 h-4">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    @if ($exportCustomFields->isNotEmpty())
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-ink/40 mb-1.5">Trường tùy biến</p>
                        <div class="grid grid-cols-2 gap-1.5">
                            @foreach ($exportCustomFields as $cf)
                                <label class="flex items-center gap-2 text-sm cursor-pointer hover:bg-gold-50 rounded px-2 py-1">
                                    <input type="checkbox" wire:model="exportCols" value="cf_{{ $cf->id }}" class="rounded border-gold-300 text-gold-600 focus:ring-gold-500 w-4 h-4">
                                    {{ $cf->label }}
                                    <span class="text-[10px] text-ink/40">{{ $cf->org_unit_id === null ? '(cty)' : $cf->orgUnit?->name }}</span>
                                </label>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="flex justify-end gap-3 mt-6">
                    <button wire:click="$set('showExportModal', false)" type="button" class="text-sm text-ink/60 px-4 py-2">Hủy</button>
                    <button wire:click="export" class="bg-gold-600 hover:bg-gold-700 text-white font-semibold text-sm px-6 py-2 rounded-md">⬇ Xuất Excel</button>
                </div>
            </div>
        </div>
    @endif
</div>
