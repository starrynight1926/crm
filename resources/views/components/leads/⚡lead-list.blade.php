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

    public string $fNguon = '';

    public string $fDateFrom = '';

    public string $fDateTo = '';

    public bool $showExportModal = false;

    /** Key các cột được chọn để xuất (core: tên cột; custom: cf_{id}). */
    public array $exportCols = [];

    /** Id các lead đang tick (chọn hàng loạt). */
    public array $selected = [];

    public bool $selectAll = false;

    public bool $showColumnPicker = false;

    /** Các cột đang hiển thị trong bảng. */
    public array $visibleCols = [];

    /** Danh sách cột có thể ẩn/hiện (key => label). */
    public const TABLE_COLUMNS = [
        'code' => 'Mã KH',
        'received_date' => 'Ngày',
        'page' => 'Page',
        'name' => 'Tên khách hàng',
        'phone' => 'SĐT',
        'camp' => 'Camp',
        'nguon' => 'Nguồn',
        'ad_source' => 'Nguồn QC',
        'region' => 'Khu vực',
        'owner' => 'Chia cho',
        'classification' => 'Danh mục',
    ];

    public function mount(): void
    {
        $prefs = auth()->user()->report_prefs ?? [];
        $saved = $prefs['lead_list_columns'] ?? null;
        $valid = array_keys(self::TABLE_COLUMNS);
        $this->visibleCols = $saved
            ? array_values(array_intersect($saved, $valid))
            : $valid;
    }

    public function updated($property): void
    {
        if (in_array($property, ['search', 'fClassification', 'fCamp', 'fAdSource', 'fNguon', 'fDateFrom', 'fDateTo'])) {
            $this->resetPage();
            $this->reset('selected', 'selectAll');
        }
    }

    public function updatedVisibleCols(): void
    {
        $this->saveColumnPrefs();
    }

    public function toggleColumn(string $key): void
    {
        if (in_array($key, $this->visibleCols)) {
            $this->visibleCols = array_values(array_diff($this->visibleCols, [$key]));
            // Reset filter khi ẩn cột (không kẹt filter cũ) — Phase 6.19
            $filterMap = [
                'camp' => 'fCamp',
                'ad_source' => 'fAdSource',
                'nguon' => 'fNguon',
                'classification' => 'fClassification',
                'received_date' => ['fDateFrom', 'fDateTo'],
            ];
            foreach ((array) ($filterMap[$key] ?? []) as $prop) {
                $this->{$prop} = '';
            }
        } else {
            $this->visibleCols[] = $key;
        }
        $this->saveColumnPrefs();
        $this->resetPage();
    }

    public function showAllColumns(): void
    {
        $this->visibleCols = array_keys(self::TABLE_COLUMNS);
        $this->saveColumnPrefs();
    }

    private function saveColumnPrefs(): void
    {
        $user = auth()->user();
        $prefs = $user->report_prefs ?? [];
        $prefs['lead_list_columns'] = array_values($this->visibleCols);
        $user->update(['report_prefs' => $prefs]);
    }

    public function colVisible(string $key): bool
    {
        return in_array($key, $this->visibleCols);
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
            'classification' => 'Danh mục',
            'job_title' => 'Chức danh',
            'region' => 'Khu vực',
            'camp' => 'Camp',
            'page' => 'Page',
            'status_1' => 'Tình trạng 1',
            'status_2' => 'Tình trạng 2',
            'note' => 'Ghi chú (hiện tại)',
            'note_history' => 'Lịch sử ghi chú',
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
            $this->exportCols = $this->allExportKeys();
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
    /** Phase 6.21 — Options distinct cho filter camp/page (union tất cả field key khớp, đa cấp). */
    private function coreCustomOptions(string $key, \App\Models\User $user)
    {
        $fieldIds = CustomField::where('key', $key)->pluck('id');
        if ($fieldIds->isEmpty()) return collect();

        $visibleIds = Lead::visibleTo($user)->pluck('leads.id');
        return \App\Models\LeadCustomValue::whereIn('lead_id', $visibleIds)
            ->whereIn('custom_field_id', $fieldIds)
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->distinct()
            ->orderBy('value')
            ->pluck('value');
    }

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
            ->when($this->fCamp, function ($q) {
                $campIds = CustomField::where('key', 'camp')->pluck('id');
                if ($campIds->isNotEmpty()) {
                    $q->whereHas('customValues', fn ($cv) => $cv->whereIn('custom_field_id', $campIds)->where('value', $this->fCamp));
                }
            })
            ->when($this->fAdSource, fn ($q) => $q->where('ad_source', $this->fAdSource))
            ->when($this->fNguon, fn ($q) => $q->whereHas('customValues', fn ($cv) =>
                $cv->where('custom_field_id', 1)->where('value', $this->fNguon)))
            ->when($this->fDateFrom, fn ($q) => $q->where('received_date', '>=', $this->fDateFrom))
            ->when($this->fDateTo, fn ($q) => $q->where('received_date', '<=', $this->fDateTo))
            ->orderByDesc('received_date')
            ->orderByDesc('id');
    }

    /**
     * Phase 6.24 — Gộp lịch sử ghi chú của lead thành 1 chuỗi multi-line cho export.
     * Format mỗi log:
     *   [dd/mm/YYYY HH:MM] Tên user: [prefix] nội dung [+N ảnh]
     *     📎 dd/mm/YYYY HH:MM · <url>
     *     ...
     * Chỉ lấy logs field='note'. Ảnh xuất dưới dạng URL absolute (Phase 6.25).
     */
    private function noteHistoryCell(Lead $lead): string
    {
        $logs = \App\Models\LeadStatusLog::with('user')
            ->where('lead_id', $lead->id)
            ->where('field', 'note')
            ->orderBy('created_at')
            ->get();

        if ($logs->isEmpty()) return '';

        return $logs->map(function ($log) {
            $when = $log->created_at?->format('d/m/Y H:i') ?? '';
            $who = $log->user?->name ?: 'Hệ thống';
            $text = trim((string) ($log->new_value ?? ''));
            $images = is_array($log->images) ? $log->images : [];
            $suffix = $images ? ' [+' . count($images) . ' ảnh]' : '';
            $prefix = $log->is_first_visit ? '🆕 ' : ($log->is_return ? '🔁 ' : '');

            $head = "[{$when}] {$who}: {$prefix}{$text}{$suffix}";
            $imgLines = array_map(function ($path) use ($when) {
                $url = url(\Illuminate\Support\Facades\Storage::disk('public')->url($path));
                return "  📎 {$when} · {$url}";
            }, $images);

            return $imgLines ? $head . "\n" . implode("\n", $imgLines) : $head;
        })->implode("\n");
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
            'job_title' => (string) $lead->owner?->job_title,
            'region' => (string) $lead->region,
            'camp' => (string) $lead->camp,
            'page' => (string) $lead->page,
            'status_1' => (string) $lead->status_1,
            'status_2' => (string) $lead->status_2,
            'note' => (string) $lead->note,
            'note_history' => $this->noteHistoryCell($lead),
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
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([$header, ...$rows]);

        // Phase 6.24 — cột note_history có multi-line → bật wrap text + rộng cột
        $noteHistoryIdx = array_search('note_history', $cols, true);
        if ($noteHistoryIdx !== false) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($noteHistoryIdx + 1);
            $sheet->getStyle($colLetter . ':' . $colLetter)->getAlignment()
                ->setWrapText(true)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);
            $sheet->getColumnDimension($colLetter)->setWidth(60);
        }

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
            'campOptions' => $this->coreCustomOptions('camp', $user),
            'adSourceOptions' => Lead::visibleTo($user)->whereNotNull('ad_source')->distinct()->orderBy('ad_source')->pluck('ad_source'),
            'nguonOptions' => \App\Models\CustomField::find(1)?->options ?? [],
            'exportCore' => $this->showExportModal ? $this->coreColumns() : [],
            'exportCustomFields' => $this->showExportModal ? $this->exportableCustomFields() : collect(),
            'canExport' => $user->hasPermission('lead.export'),
            'canUpdate' => $user->hasPermission('lead.update'),
            'canDelete' => $user->hasPermission('lead.delete'),
            'tableColumns' => self::TABLE_COLUMNS,
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

    {{-- Bộ lọc — chỉ hiện khi cột tương ứng đang bật (Phase 6.19) --}}
    <div class="bg-white border border-gold-200 rounded-xl shadow-card px-5 py-4 mb-5 flex flex-wrap items-end gap-3">
        @if ($this->colVisible('received_date'))
            <div class="min-w-[130px] flex-1">
                <label class="block text-xs font-semibold text-ink/50 mb-1">Từ ngày</label>
                <x-date-input field="fDateFrom" class="px-2.5 py-2" />
            </div>
            <div class="min-w-[130px] flex-1">
                <label class="block text-xs font-semibold text-ink/50 mb-1">Đến ngày</label>
                <x-date-input field="fDateTo" class="px-2.5 py-2" />
            </div>
        @endif
        @if ($this->colVisible('camp'))
            <div class="min-w-[140px] flex-1">
                <label class="block text-xs font-semibold text-ink/50 mb-1">Chiến dịch</label>
                <select wire:model.live="fCamp" class="w-full border border-gold-200 rounded-md px-2.5 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                    <option value="">Tất cả</option>
                    @foreach ($campOptions as $camp)
                        <option value="{{ $camp }}">{{ $camp }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        @if ($this->colVisible('ad_source'))
            <div class="min-w-[140px] flex-1">
                <label class="block text-xs font-semibold text-ink/50 mb-1">Nguồn QC</label>
                <select wire:model.live="fAdSource" class="w-full border border-gold-200 rounded-md px-2.5 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                    <option value="">Tất cả</option>
                    @foreach ($adSourceOptions as $source)
                        <option value="{{ $source }}">{{ $source }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        @if ($this->colVisible('nguon'))
            <div class="min-w-[140px] flex-1">
                <label class="block text-xs font-semibold text-ink/50 mb-1">Nguồn data</label>
                <select wire:model.live="fNguon" class="w-full border border-gold-200 rounded-md px-2.5 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                    <option value="">Tất cả</option>
                    @foreach ($nguonOptions as $opt)
                        <option value="{{ $opt }}">{{ $opt }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        @if ($this->colVisible('classification'))
            <div class="min-w-[160px] flex-1">
                <label class="block text-xs font-semibold text-ink/50 mb-1">Danh mục</label>
                <select wire:model.live="fClassification" class="w-full border border-gold-200 rounded-md px-2.5 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                    <option value="">Tất cả</option>
                    @foreach (\App\Models\Lead::CLASSIFICATIONS as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div class="min-w-[180px] flex-[2]">
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
        {{-- Thanh chọn cột --}}
        <div class="px-5 py-2.5 border-b border-gold-100 flex items-center justify-between">
            <span class="text-xs text-ink/40">Hiển thị {{ count($visibleCols) }}/{{ count($tableColumns) }} cột</span>
            <div class="relative" x-data="{ open: false }">
                <button @click="open = !open" class="inline-flex items-center gap-1.5 text-sm font-semibold text-gold-700 border border-gold-300 px-3 py-1.5 rounded-md hover:bg-gold-50">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75"/></svg>
                    Chọn cột
                </button>
                <div x-show="open" @click.outside="open = false" x-cloak x-transition
                     class="absolute right-0 top-full mt-1 w-56 bg-white border border-gold-200 rounded-xl shadow-lg z-30 py-2">
                    <div class="px-3 pb-2 mb-1 border-b border-gold-100 flex items-center justify-between">
                        <span class="text-[11px] font-semibold uppercase tracking-wider text-ink/40">Cột hiển thị</span>
                        <button wire:click="showAllColumns" class="text-[11px] font-semibold text-gold-600 hover:underline">Hiện tất cả</button>
                    </div>
                    @foreach ($tableColumns as $key => $label)
                        <label class="flex items-center gap-2.5 px-3 py-1.5 hover:bg-gold-50 cursor-pointer text-sm" wire:key="col-{{ $key }}">
                            <input type="checkbox"
                                   @checked(in_array($key, $visibleCols))
                                   wire:click="toggleColumn('{{ $key }}')"
                                   class="rounded border-gold-300 text-gold-600 focus:ring-gold-500 w-4 h-4">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
            </div>
        </div>

        <table class="w-full text-sm whitespace-nowrap">
            <thead>
                <tr class="text-left text-xs uppercase tracking-wider text-ink/50 bg-gold-50/60">
                    @if ($canDelete)
                        <th class="px-4 py-3 font-semibold w-10">
                            <input type="checkbox" wire:model.live="selectAll" class="rounded border-gold-300 text-gold-600 w-4 h-4">
                        </th>
                    @endif
                    <th class="px-4 py-3 font-semibold w-12">STT</th>
                    @if ($this->colVisible('code'))        <th class="px-4 py-3 font-semibold">Mã KH</th> @endif
                    @if ($this->colVisible('received_date'))<th class="px-4 py-3 font-semibold">Ngày</th> @endif
                    @if ($this->colVisible('page'))        <th class="px-4 py-3 font-semibold">Page</th> @endif
                    @if ($this->colVisible('name'))        <th class="px-4 py-3 font-semibold">Tên khách hàng</th> @endif
                    @if ($this->colVisible('phone'))       <th class="px-4 py-3 font-semibold">SĐT</th> @endif
                    @if ($this->colVisible('camp'))        <th class="px-4 py-3 font-semibold">Camp</th> @endif
                    @if ($this->colVisible('nguon'))       <th class="px-4 py-3 font-semibold">Nguồn</th> @endif
                    @if ($this->colVisible('ad_source'))   <th class="px-4 py-3 font-semibold">Nguồn QC</th> @endif
                    @if ($this->colVisible('region'))      <th class="px-4 py-3 font-semibold">Khu vực</th> @endif
                    @if ($this->colVisible('owner'))       <th class="px-4 py-3 font-semibold">Chia cho</th> @endif
                    @if ($this->colVisible('classification'))<th class="px-4 py-3 font-semibold">Danh mục</th> @endif
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
                        @if ($this->colVisible('code'))
                            <td class="px-4 py-3 font-mono text-xs text-gold-700">{{ $lead->code ?: '—' }}</td>
                        @endif
                        @if ($this->colVisible('received_date'))
                            <td class="px-4 py-3">{{ $lead->received_date->format('d/m/Y') }}</td>
                        @endif
                        @if ($this->colVisible('page'))
                            <td class="px-4 py-3 text-ink/60">{{ $lead->page ?: '—' }}</td>
                        @endif
                        @if ($this->colVisible('name'))
                            <td class="px-4 py-3 font-semibold text-gold-700">{{ $lead->name }}</td>
                        @endif
                        @if ($this->colVisible('phone'))
                            <td class="px-4 py-3 font-mono">{{ $lead->phoneFor(auth()->user()) }}</td>
                        @endif
                        @if ($this->colVisible('camp'))
                            <td class="px-4 py-3 text-ink/60">{{ $lead->camp ?: '—' }}</td>
                        @endif
                        @if ($this->colVisible('nguon'))
                            @php $nguonVal = $lead->customValues->firstWhere('custom_field_id', 1)?->value; @endphp
                            <td class="px-4 py-3">
                                @if ($nguonVal)
                                    <span class="text-xs bg-blue-50 border border-blue-200 px-2 py-0.5 rounded">{{ $nguonVal }}</span>
                                @else — @endif
                            </td>
                        @endif
                        @if ($this->colVisible('ad_source'))
                            <td class="px-4 py-3">
                                @if ($lead->ad_source)
                                    <span class="text-xs bg-gold-50 border border-gold-200 px-2 py-0.5 rounded">{{ $lead->ad_source }}</span>
                                @else — @endif
                            </td>
                        @endif
                        @if ($this->colVisible('region'))
                            <td class="px-4 py-3 text-ink/60">{{ $lead->region ?: '—' }}</td>
                        @endif
                        @if ($this->colVisible('owner'))
                            <td class="px-4 py-3">{{ $lead->owner?->name ?: '—' }}</td>
                        @endif
                        @if ($this->colVisible('classification'))
                            <td class="px-4 py-3">
                                @php
                                    $badge = match($lead->classification) {
                                        'new'           => 'bg-gray-100 text-gray-700',
                                        'lead'          => 'bg-blue-100 text-blue-800',
                                        'follow'        => 'bg-cyan-100 text-cyan-800',
                                        'net'           => 'bg-indigo-100 text-indigo-800',
                                        'quan_tam'      => 'bg-yellow-100 text-yellow-800',
                                        'tham_khao'     => 'bg-amber-100 text-amber-800',
                                        'tim_hieu'      => 'bg-sky-100 text-sky-800',
                                        'tai_chinh_yeu' => 'bg-rose-100 text-rose-700',
                                        'goi_lai_sau'   => 'bg-slate-100 text-slate-700',
                                        'booking'       => 'bg-orange-100 text-orange-800',
                                        'show'          => 'bg-purple-100 text-purple-800',
                                        'close'         => 'bg-green-100 text-green-800',
                                        'klld'          => 'bg-red-100 text-red-700',
                                        'missed'        => 'bg-red-100 text-red-700',
                                        default         => 'bg-gray-100 text-gray-600',
                                    };
                                @endphp
                                <span class="text-xs font-semibold px-2.5 py-1 rounded-full {{ $badge }}">{{ $lead->classificationLabel() }}</span>
                            </td>
                        @endif
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
                    <tr><td colspan="{{ 2 + count($visibleCols) + ($canUpdate || $canDelete ? 1 : 0) + ($canDelete ? 1 : 0) }}" class="px-4 py-10 text-center text-ink/40">Không có khách hàng nào trong phạm vi của bạn.</td></tr>
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
