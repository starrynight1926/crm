<?php

use App\Jobs\ProcessRawLead;
use App\Models\CustomField;
use App\Models\ImportBatch;
use App\Models\ImportTemplate;
use App\Models\RawLead;
use App\Support\SpreadsheetReader;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public $file = null;

    public array $headers = [];

    public array $preview = [];

    public ?string $storedPath = null;

    public string $storedExtension = '';

    public string $storedName = '';

    /** @var array<string, string> target => cột file (index dạng string, '' = bỏ qua) */
    public array $mapping = [];

    /** @var array<string, string> target => giá trị mặc định nếu ô trống */
    public array $defaults = [];

    public string $selectedTemplateId = '';

    public string $templateName = '';

    /** Mẫu import theo phòng: '' = công ty, 'org:{id}' = phòng cụ thể. */
    public string $selectedOrgTemplate = '';

    public ?int $lastBatchId = null;

    // Field lead chuẩn (scope.md mục 4)
    public const TARGETS = [
        'name' => 'Tên khách hàng *',
        'phone' => 'SĐT *',
        'received_date' => 'Ngày',
        'page' => 'PAGE',
        'camp' => 'Camp',
        'insight' => 'Insight',
        'link' => 'Link',
        'ad_source' => 'Nguồn quảng cáo',
        'region' => 'Khu vực',
        'note' => 'NOTE',
    ];

    private const GUESS = [
        'name' => ['tên', 'ten', 'name', 'họ tên', 'khách hàng'],
        'phone' => ['sđt', 'sdt', 'phone', 'điện thoại', 'so dien thoai'],
        'received_date' => ['ngày', 'ngay', 'date'],
        'page' => ['page'],
        'camp' => ['camp', 'chiến dịch'],
        'insight' => ['insight'],
        'link' => ['link'],
        'ad_source' => ['nguồn', 'nguon', 'source'],
        'region' => ['khu vực', 'khu vuc', 'region'],
        'note' => ['note', 'ghi chú'],
    ];

    /** Trường tùy biến đang áp (active) → ['cf_<id>' => 'Nhãn #MÃ (Phòng)']. */
    private function customTargets(): array
    {
        $out = [];
        foreach ($this->customFields() as $f) {
            $scope = $f->org_unit_id === null ? 'Công ty' : ($f->orgUnit?->name ?? 'Phòng');
            $code = $f->import_code ? " #{$f->import_code}" : '';
            $req = $f->required ? ' *' : '';
            $out['cf_' . $f->id] = $f->label . $code . ' (' . $scope . ')' . $req;
        }
        return $out;
    }

    /** Cache custom fields active cho session. */
    private function customFields()
    {
        return CustomField::query()
            ->where('active', true)
            ->where('status', CustomField::STATUS_ACTIVE)
            ->with('orgUnit')
            ->orderBy('org_unit_id')->orderBy('position')->get();
    }

    /** Toàn bộ target: field chuẩn + trường tùy biến. */
    private function allTargets(): array
    {
        return array_merge(self::TARGETS, $this->customTargets());
    }

    private function norm(string $s): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s)));
    }

    public function updatedFile(): void
    {
        $this->validate(['file' => 'required|file|mimes:csv,txt,xlsx,xls|max:20480']);

        $this->storedExtension = $this->file->getClientOriginalExtension();
        $this->storedName = $this->file->getClientOriginalName();
        $this->storedPath = $this->file->store('imports');

        $data = SpreadsheetReader::read(storage_path('app/private/' . $this->storedPath), $this->storedExtension);
        $this->headers = $data['headers'];
        $this->preview = array_slice($data['rows'], 0, 5);

        $this->initMappingDefaults();

        if ($this->selectedTemplateId !== '') {
            $this->applyTemplate();
        } else {
            $this->autoGuess();
        }
    }

    private function initMappingDefaults(): void
    {
        $keys = array_keys($this->allTargets());
        $this->mapping = array_fill_keys($keys, '');
        $this->defaults = array_fill_keys($keys, '');
    }

    /** Tự đoán mapping theo tên cột: field chuẩn theo từ khóa, custom theo import_code hoặc nhãn. */
    private function autoGuess(): void
    {
        $fields = $this->customFields();
        $custom = $this->customTargets();

        foreach ($this->headers as $index => $header) {
            $h = $this->norm((string) $header);
            if ($h === '') {
                continue;
            }
            foreach (self::GUESS as $target => $keywords) {
                if ($this->mapping[$target] === '' && array_filter($keywords, fn ($k) => str_contains($h, $k))) {
                    $this->mapping[$target] = (string) $index;
                    break;
                }
            }
            // Custom fields: ưu tiên match theo import_code, fallback theo nhãn
            foreach ($fields as $f) {
                $target = 'cf_' . $f->id;
                if (($this->mapping[$target] ?? '') !== '') {
                    continue;
                }
                if ($f->import_code && $h === $this->norm($f->import_code)) {
                    $this->mapping[$target] = (string) $index;
                    continue;
                }
                $label = $custom[$target] ?? '';
                $base = $this->norm(preg_replace('/\s*[#(].*$/', '', $label));
                if ($base !== '' && $h === $base) {
                    $this->mapping[$target] = (string) $index;
                }
            }
        }
    }

    public function applyTemplate(): void
    {
        if ($this->selectedTemplateId === '' || ! $this->headers) {
            return;
        }
        $tpl = ImportTemplate::find($this->selectedTemplateId);
        if (! $tpl) {
            return;
        }

        $this->initMappingDefaults();
        // index theo header đã chuẩn hóa
        $byHeader = [];
        foreach ($this->headers as $i => $h) {
            $byHeader[$this->norm((string) $h)] = (string) $i;
        }
        foreach ($tpl->config ?? [] as $entry) {
            $target = $entry['target'] ?? null;
            if (! $target || ! array_key_exists($target, $this->mapping)) {
                continue;
            }
            $header = $this->norm((string) ($entry['header'] ?? ''));
            if ($header !== '' && isset($byHeader[$header])) {
                $this->mapping[$target] = $byHeader[$header];
            }
            $this->defaults[$target] = (string) ($entry['default'] ?? '');
        }
        session()->flash('status', "Đã áp template \"{$tpl->name}\".");
    }

    public function saveTemplate(): void
    {
        abort_unless(auth()->user()->hasPermission('lead.import'), 403);
        $this->validate(['templateName' => 'required|string|max:100'], [], ['templateName' => 'tên template']);

        $labels = $this->allTargets();
        $config = [];
        foreach ($this->mapping as $target => $colIndex) {
            $default = trim((string) ($this->defaults[$target] ?? ''));
            if ($colIndex === '' && $default === '') {
                continue; // target không map + không mặc định → bỏ
            }
            $config[] = [
                'target' => $target,
                'header' => $colIndex !== '' ? (string) ($this->headers[(int) $colIndex] ?? '') : '',
                'default' => $default,
            ];
        }

        ImportTemplate::create([
            'name' => $this->templateName,
            'config' => $config,
            'created_by' => auth()->id(),
        ]);
        $this->templateName = '';
        session()->flash('status', 'Đã lưu template.');
    }

    public function deleteTemplate(int $id): void
    {
        abort_unless(auth()->user()->hasPermission('lead.import'), 403);
        ImportTemplate::whereKey($id)->delete();
        if ($this->selectedTemplateId === (string) $id) {
            $this->selectedTemplateId = '';
        }
    }

    public function downloadSample(int $id)
    {
        $tpl = ImportTemplate::findOrFail($id);
        $headers = collect($tpl->config ?? [])
            ->pluck('header')
            ->filter(fn ($h) => trim((string) $h) !== '')
            ->values()
            ->all();

        if (empty($headers)) {
            session()->flash('status', 'Template chưa có cột nào để tạo file mẫu.');
            return;
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($headers as $i => $header) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue($col . '1', $header);
            $sheet->getColumnDimension($col)->setAutoSize(true);
            $sheet->getStyle($col . '1')->getFont()->setBold(true);
        }

        $filename = 'mau-import-' . \Illuminate\Support\Str::slug($tpl->name) . '.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
        }, $filename);
    }

    /** Tải file mẫu theo phòng ban đang chọn (trường chuẩn + trường tùy biến áp dụng). */
    public function downloadBlankSample()
    {
        $orgUnit = null;
        $slug = 'cong-ty';
        if (str_starts_with($this->selectedOrgTemplate, 'org:')) {
            $orgUnit = \App\Models\OrgUnit::find((int) substr($this->selectedOrgTemplate, 4));
            $slug = $orgUnit ? \Illuminate\Support\Str::slug($orgUnit->name) : 'phong';
        }

        $fields = CustomField::applicableTo($orgUnit);

        $headers = array_values(self::TARGETS);
        foreach ($fields as $f) {
            if ($f->field_type === 'code' && ($f->rules['code_kind'] ?? '') === 'fixed') {
                continue;
            }
            $code = $f->import_code ?: $f->label;
            $req = $f->required ? ' *' : '';
            $headers[] = $code . $req;
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($headers as $i => $header) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue($col . '1', $header);
            $sheet->getColumnDimension($col)->setAutoSize(true);
            $sheet->getStyle($col . '1')->getFont()->setBold(true);
        }

        return response()->streamDownload(function () use ($spreadsheet) {
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
        }, "mau-import-{$slug}.xlsx");
    }

    public function import()
    {
        abort_unless(auth()->user()->hasPermission('lead.import'), 403);

        if (! $this->storedPath) {
            $this->addError('file', 'Chưa chọn file.');
            return;
        }
        if (($this->mapping['name'] ?? '') === '' || ($this->mapping['phone'] ?? '') === '') {
            $this->addError('mapping', 'Bắt buộc map cột Tên và SĐT.');
            return;
        }

        // Kiểm tra trường tùy biến bắt buộc đã được map hoặc có giá trị mặc định
        $missing = [];
        foreach ($this->customFields() as $f) {
            if (! $f->required) continue;
            $target = 'cf_' . $f->id;
            $mapped = ($this->mapping[$target] ?? '') !== '';
            $hasDefault = trim((string) ($this->defaults[$target] ?? '')) !== '';
            if (! $mapped && ! $hasDefault) {
                $code = $f->import_code ? " (#{$f->import_code})" : '';
                $missing[] = $f->label . $code;
            }
        }
        if ($missing !== []) {
            $this->addError('mapping', 'Trường bắt buộc chưa map hoặc chưa có mặc định: ' . implode(', ', $missing));
            return;
        }

        $data = SpreadsheetReader::read(storage_path('app/private/' . $this->storedPath), $this->storedExtension);

        $batch = ImportBatch::create([
            'file_name' => $this->storedName,
            'uploaded_by' => auth()->id(),
            'column_mapping' => $this->mapping,
            'total' => count($data['rows']),
            'created_at' => now(),
        ]);

        $nameCol = (int) $this->mapping['name'];
        $phoneCol = (int) $this->mapping['phone'];

        $count = 0;
        foreach ($data['rows'] as $row) {
            // Bỏ dòng mà Tên và SĐT đều trống (rác/dòng đệm)
            $nameV = trim((string) ($row[$nameCol] ?? ''));
            $phoneV = trim((string) ($row[$phoneCol] ?? ''));
            if ($nameV === '' && $phoneV === '') {
                continue;
            }

            $payload = [];
            foreach ($this->mapping as $target => $columnIndex) {
                $val = $columnIndex !== '' ? trim((string) ($row[(int) $columnIndex] ?? '')) : '';
                if ($val === '' && ($this->defaults[$target] ?? '') !== '') {
                    $val = trim((string) $this->defaults[$target]);
                }
                if ($val !== '') {
                    $payload[$target] = $val;
                }
            }

            $raw = RawLead::create([
                'source_type' => RawLead::SOURCE_EXCEL,
                'source_ref' => $this->storedName,
                'import_batch_id' => $batch->id,
                'payload' => $payload,
                'status' => RawLead::STATUS_PENDING,
                'created_at' => now(),
            ]);
            ProcessRawLead::dispatch($raw->id);
            $count++;
        }

        $batch->update(['total' => $count]);

        session()->flash('status', "Đã import {$count} khách hàng (batch #{$batch->id}).");
        return $this->redirect(route('leads.index'), navigate: true);
    }

    public function with(): array
    {
        $batches = ImportBatch::orderByDesc('id')->limit(10)->get();
        $batches->each->refreshStats();

        return [
            'batches' => $batches,
            'targets' => $this->allTargets(),
            'templates' => ImportTemplate::orderByDesc('id')->get(),
            'orgOptions' => \App\Models\OrgUnit::orderBy('path')->get(),
        ];
    }
};
?>

<div wire:poll.5s>
    <div class="mb-6">
        <h1 class="text-3xl font-bold mb-1">Import dữ liệu khách hàng</h1>
        <p class="text-sm text-ink/60">Upload Excel/CSV → chọn/tạo template → map cột (kèm giá trị mặc định) → pipeline chuẩn hóa, chống trùng, đưa lead sạch vào kho chung.</p>
    </div>

    {{-- Hướng dẫn các bước --}}
    <div class="mb-6 bg-white border border-gold-200 rounded-xl shadow-card px-6 py-5">
        <h2 class="text-sm font-bold text-ink/60 uppercase tracking-wider mb-4">Quy trình import</h2>
        <div class="flex items-center justify-between gap-2 overflow-x-auto">
            @foreach ([
                ['icon' => '1', 'label' => 'Chọn mẫu import', 'desc' => 'Chọn phòng/team để tải file mẫu đúng bộ trường'],
                ['icon' => '2', 'label' => 'Điền thông tin', 'desc' => 'Nhập dữ liệu khách hàng vào file mẫu đã tải'],
                ['icon' => '3', 'label' => 'Upload lên hệ thống', 'desc' => 'Chọn file → map cột → bấm Import'],
                ['icon' => '4', 'label' => 'Sửa các lỗi sai nếu có', 'desc' => 'Xem mục "Lead lỗi" để sửa dữ liệu thiếu/sai'],
                ['icon' => '5', 'label' => 'Đăng tải', 'desc' => 'Lead sạch tự vào kho chung, engine chia số xử lý'],
            ] as $i => $step)
                @if ($i > 0)
                    <svg class="w-5 h-5 shrink-0 text-gold-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                @endif
                <div class="flex items-start gap-3 min-w-[150px] flex-1">
                    <span class="shrink-0 w-8 h-8 rounded-full bg-gold-600 text-white font-bold text-sm flex items-center justify-center">{{ $step['icon'] }}</span>
                    <div>
                        <p class="text-sm font-semibold text-ink/80 leading-tight">{{ $step['label'] }}</p>
                        <p class="text-[11px] text-ink/50 mt-0.5 leading-snug">{{ $step['desc'] }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    @if (session('status'))
        <p class="mb-4 text-sm text-green-700 bg-green-50 border border-green-200 rounded-md px-4 py-2">{{ session('status') }}</p>
    @endif

    {{-- Bước 1: Chọn mẫu import --}}
    <div class="bg-white border border-gold-200 rounded-xl shadow-card p-6 mb-6">
        <h2 class="font-bold text-gold-700 mb-4 flex items-center gap-2">
            <span class="shrink-0 w-7 h-7 rounded-full bg-gold-600 text-white font-bold text-xs flex items-center justify-center">1</span>
            Chọn mẫu import
        </h2>
        <p class="text-xs text-ink/50 mb-4">Mẫu được tạo tự động từ trường chuẩn + trường tùy biến theo phòng ban. Chọn phòng rồi tải file mẫu.</p>
        <div class="flex items-end gap-3 flex-wrap">
            <div class="min-w-[240px]">
                <label class="block text-xs font-semibold text-ink/60 mb-1">Phòng / Team</label>
                <select wire:model.live="selectedOrgTemplate" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm bg-white">
                    <option value="">Mức công ty (trường chung)</option>
                    @foreach ($orgOptions as $o)
                        <option value="org:{{ $o->id }}">{{ str_repeat('— ', $o->depth) }}{{ $o->name }}</option>
                    @endforeach
                </select>
            </div>
            <button wire:click="downloadBlankSample"
                    class="inline-flex items-center gap-1.5 text-sm font-semibold text-white bg-gold-600 hover:bg-gold-700 px-5 py-2.5 rounded-md">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/>
                </svg>
                Tải file mẫu
            </button>
        </div>
        @if ($templates->isNotEmpty())
            <div class="mt-4 pt-4 border-t border-gold-100 flex items-end gap-3 flex-wrap">
                <div class="min-w-[240px]">
                    <label class="block text-xs font-semibold text-ink/60 mb-1">Hoặc dùng mẫu đã lưu trước đó</label>
                    <select wire:model.live="selectedTemplateId" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm bg-white">
                        <option value="">— chọn mẫu —</option>
                        @foreach ($templates as $tpl)
                            <option value="{{ $tpl->id }}">{{ $tpl->name }} ({{ count($tpl->config ?? []) }} cột)</option>
                        @endforeach
                    </select>
                </div>
                @if ($selectedTemplateId)
                    <button wire:click="downloadSample({{ (int) $selectedTemplateId }})"
                            class="inline-flex items-center gap-1.5 text-sm font-semibold text-gold-700 border border-gold-300 hover:bg-gold-50 px-4 py-2.5 rounded-md">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/>
                        </svg>
                        Tải mẫu này
                    </button>
                @endif
            </div>
        @endif

        @if ($selectedTemplateId && ($selectedTpl = $templates->firstWhere('id', (int) $selectedTemplateId)))
            <div class="mt-4 border border-gold-100 rounded-lg p-4 bg-gold-50/30">
                <div class="text-sm font-bold text-ink/70 mb-2">Trường của mẫu "{{ $selectedTpl->name }}"</div>
                <table class="w-full text-xs">
                    <thead>
                        <tr class="text-left text-ink/50 border-b border-gold-200">
                            <th class="py-1.5 pr-4 font-semibold">Cột file</th>
                            <th class="py-1.5 pr-4 font-semibold">Trường hệ thống</th>
                            <th class="py-1.5 font-semibold">Mặc định</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($selectedTpl->config ?? [] as $entry)
                            <tr class="border-b border-gold-100">
                                <td class="py-1.5 pr-4 font-medium">{{ $entry['header'] ?: '—' }}</td>
                                <td class="py-1.5 pr-4">
                                    <span class="px-1.5 py-0.5 rounded bg-gold-50 text-gold-700 border border-gold-200">{{ $targets[$entry['target']] ?? $entry['target'] }}</span>
                                </td>
                                <td class="py-1.5 text-ink/50">{{ $entry['default'] ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start mb-6">
        {{-- Upload + mapping --}}
        <div class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
            <h2 class="font-bold text-gold-700 mb-4">2. Chọn file (CSV / XLSX)</h2>
            <input type="file" wire:model="file" accept=".csv,.xlsx,.xls"
                   class="block w-full text-sm border border-gold-200 rounded-md file:mr-3 file:px-4 file:py-2.5 file:border-0 file:bg-gold-50 file:text-gold-700 file:font-semibold file:text-sm cursor-pointer">
            @error('file')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            <div wire:loading wire:target="file" class="text-sm text-gold-600 mt-2">Đang đọc file...</div>

            @if ($headers)
                {{-- Lưu template mới --}}
                <div class="mt-5 border border-gold-100 rounded-lg p-3 bg-gold-50/40">
                    <div class="flex items-end gap-2">
                        <div class="flex-1">
                            <label class="block text-xs font-semibold text-ink/60 mb-1">Lưu mapping hiện tại thành mẫu mới</label>
                            <input type="text" wire:model="templateName" placeholder="VD: Mẫu FB Lead Form" class="w-full border border-gold-200 rounded-md px-2.5 py-1.5 text-sm">
                            @error('templateName')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>
                        <button wire:click="saveTemplate" class="text-xs font-semibold text-gold-700 border border-gold-300 hover:bg-gold-100 px-3 py-2 rounded-md">Lưu mẫu</button>
                    </div>
                </div>

                <h2 class="font-bold text-gold-700 mt-6 mb-1">3. Map cột file → trường</h2>
                <p class="text-xs text-ink/50 mb-3">Tự đoán theo tên cột; đặt "Mặc định" cho ô trống. Trường tùy biến theo phòng nằm cuối danh sách.</p>
                @error('mapping')<p class="text-xs text-red-600 mb-2">{{ $message }}</p>@enderror

                <div class="grid grid-cols-[1fr_1fr_0.9fr] gap-2 mb-1 px-0.5">
                    <span class="text-[11px] font-semibold uppercase tracking-wider text-ink/40">Trường</span>
                    <span class="text-[11px] font-semibold uppercase tracking-wider text-ink/40">Cột file</span>
                    <span class="text-[11px] font-semibold uppercase tracking-wider text-ink/40">Mặc định</span>
                </div>
                <div class="space-y-2">
                    @foreach ($targets as $target => $label)
                        <div class="grid grid-cols-[1fr_1fr_0.9fr] gap-2 items-center">
                            <label class="text-sm font-medium {{ str_starts_with($target, 'cf_') ? 'text-ink/70' : '' }}">{{ $label }}</label>
                            <select wire:model="mapping.{{ $target }}" class="border border-gold-200 rounded-md px-2 py-1.5 text-sm bg-white focus:outline-none focus:border-gold-500">
                                <option value="">— bỏ qua —</option>
                                @foreach ($headers as $index => $header)
                                    <option value="{{ $index }}">{{ $header ?: "Cột " . ($index + 1) }}</option>
                                @endforeach
                            </select>
                            <input type="text" wire:model="defaults.{{ $target }}" placeholder="—" class="border border-gold-200 rounded-md px-2 py-1.5 text-sm focus:outline-none focus:border-gold-500">
                        </div>
                    @endforeach
                </div>

                <button wire:click="import" wire:loading.attr="disabled"
                        class="mt-6 w-full bg-gold-600 hover:bg-gold-700 text-white font-semibold py-3 rounded-md">
                    4. Import ngay
                </button>
            @endif
        </div>

        {{-- Preview --}}
        <div class="bg-white border border-gold-200 rounded-xl shadow-card p-6 overflow-x-auto">
            <h2 class="font-bold text-gold-700 mb-4">Xem trước (5 dòng đầu)</h2>
            @if ($preview)
                <table class="w-full text-xs whitespace-nowrap">
                    <thead>
                        <tr class="text-left bg-gold-50/60 text-ink/50 uppercase tracking-wider">
                            @foreach ($headers as $header)
                                <th class="px-2.5 py-2 font-semibold">{{ $header }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gold-100">
                        @foreach ($preview as $row)
                            <tr>
                                @foreach ($headers as $index => $_)
                                    <td class="px-2.5 py-2">{{ $row[$index] ?? '' }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="text-sm text-ink/40">Chọn file để xem trước nội dung.</p>
            @endif
        </div>
    </div>

    {{-- Thống kê batch --}}
    <div class="bg-white border border-gold-200 rounded-xl shadow-card">
        <div class="px-6 py-4 border-b border-gold-100 flex items-center justify-between">
            <h2 class="text-lg font-bold">Lịch sử import</h2>
            <span class="text-xs text-ink/40">Tự cập nhật mỗi 5 giây</span>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full text-sm min-w-[720px]">
            <thead>
                <tr class="text-left text-xs uppercase tracking-wider text-ink/50 bg-gold-50/60">
                    <th class="px-5 py-3 font-semibold">#</th>
                    <th class="px-5 py-3 font-semibold">File</th>
                    <th class="px-5 py-3 font-semibold">Thời gian</th>
                    <th class="px-5 py-3 font-semibold text-right">Tổng</th>
                    <th class="px-5 py-3 font-semibold text-right text-green-700">Thành công</th>
                    <th class="px-5 py-3 font-semibold text-right text-amber-600">Trùng (đã gộp)</th>
                    <th class="px-5 py-3 font-semibold text-right text-red-600">Lỗi</th>
                    <th class="px-5 py-3 font-semibold text-right">Đang chờ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gold-100">
                @forelse ($batches as $batch)
                    @php $pending = $batch->total - $batch->success - $batch->failed - $batch->duplicated; @endphp
                    <tr class="{{ $batch->id === $lastBatchId ? 'bg-gold-50/60' : '' }}">
                        <td class="px-5 py-3 text-ink/50">{{ $batch->id }}</td>
                        <td class="px-5 py-3 font-medium">{{ $batch->file_name }}</td>
                        <td class="px-5 py-3 text-ink/50">{{ $batch->created_at?->format('d/m/Y H:i') }}</td>
                        <td class="px-5 py-3 text-right font-semibold">{{ $batch->total }}</td>
                        <td class="px-5 py-3 text-right text-green-700 font-semibold">{{ $batch->success }}</td>
                        <td class="px-5 py-3 text-right text-amber-600 font-semibold">{{ $batch->duplicated }}</td>
                        <td class="px-5 py-3 text-right font-semibold {{ $batch->failed > 0 ? 'text-red-600' : 'text-ink/30' }}">
                            @if ($batch->failed > 0)
                                <a href="{{ route('leads.failed') }}" class="underline">{{ $batch->failed }}</a>
                            @else 0 @endif
                        </td>
                        <td class="px-5 py-3 text-right {{ $pending > 0 ? 'text-gold-600 font-semibold' : 'text-ink/30' }}">{{ max($pending, 0) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-5 py-8 text-center text-ink/40">Chưa có lần import nào.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>
</div>
