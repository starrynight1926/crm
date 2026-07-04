<?php

use App\Jobs\ProcessRawLead;
use App\Models\ImportBatch;
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

    /** @var array<string, string> target field => cột file (index dạng string, '' = bỏ qua) */
    public array $mapping = [];

    public ?int $lastBatchId = null;

    // Field chuẩn có thể map (scope.md mục 4)
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
        'type_code' => 'Loại data (MKT/C/BDM/SI/N)',
    ];

    public function updatedFile(): void
    {
        $this->validate(['file' => 'required|file|mimes:csv,txt,xlsx,xls|max:20480']);

        $this->storedExtension = $this->file->getClientOriginalExtension();
        $this->storedName = $this->file->getClientOriginalName();
        $this->storedPath = $this->file->store('imports');

        $data = SpreadsheetReader::read(storage_path('app/private/' . $this->storedPath), $this->storedExtension);
        $this->headers = $data['headers'];
        $this->preview = array_slice($data['rows'], 0, 5);

        // Tự đoán mapping theo tên cột
        $guesses = [
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
            'type_code' => ['loại', 'loai', 'type'],
        ];

        $this->mapping = array_fill_keys(array_keys(self::TARGETS), '');
        foreach ($this->headers as $index => $header) {
            $h = mb_strtolower(trim($header));
            foreach ($guesses as $target => $keywords) {
                if ($this->mapping[$target] === '' && array_filter($keywords, fn ($k) => str_contains($h, $k))) {
                    $this->mapping[$target] = (string) $index;
                    break;
                }
            }
        }
    }

    public function import(): void
    {
        abort_unless(auth()->user()->hasPermission('lead.import'), 403);

        if (! $this->storedPath) {
            $this->addError('file', 'Chưa chọn file.');
            return;
        }
        if ($this->mapping['name'] === '' || $this->mapping['phone'] === '') {
            $this->addError('mapping', 'Bắt buộc map cột Tên và SĐT.');
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

        foreach ($data['rows'] as $row) {
            $payload = [];
            foreach ($this->mapping as $target => $columnIndex) {
                if ($columnIndex !== '') {
                    $value = $row[(int) $columnIndex] ?? null;
                    $payload[$target] = $value !== null ? trim((string) $value) : null;
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
        }

        $this->lastBatchId = $batch->id;
        $this->reset('file', 'headers', 'preview', 'storedPath', 'storedName', 'mapping');
        session()->flash('status', "Đã nhận {$batch->total} dòng — pipeline đang chuẩn hóa nền (batch #{$batch->id}).");
    }

    public function with(): array
    {
        $batches = ImportBatch::orderByDesc('id')->limit(10)->get();
        $batches->each->refreshStats();

        return ['batches' => $batches];
    }
};
?>

<div wire:poll.5s>
    <div class="mb-6">
        <h1 class="text-3xl font-bold mb-1">Import dữ liệu khách hàng</h1>
        <p class="text-sm text-ink/60">Upload Excel/CSV → map cột → pipeline tự chuẩn hóa SĐT, chống trùng và đưa lead sạch vào kho chung.</p>
    </div>

    @if (session('status'))
        <p class="mb-4 text-sm text-green-700 bg-green-50 border border-green-200 rounded-md px-4 py-2">{{ session('status') }}</p>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start mb-6">
        {{-- Upload + mapping --}}
        <div class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
            <h2 class="font-bold text-gold-700 mb-4">1. Chọn file (CSV / XLSX)</h2>
            <input type="file" wire:model="file" accept=".csv,.xlsx,.xls"
                   class="block w-full text-sm border border-gold-200 rounded-md file:mr-3 file:px-4 file:py-2.5 file:border-0 file:bg-gold-50 file:text-gold-700 file:font-semibold file:text-sm cursor-pointer">
            @error('file')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            <div wire:loading wire:target="file" class="text-sm text-gold-600 mt-2">Đang đọc file...</div>

            @if ($headers)
                <h2 class="font-bold text-gold-700 mt-6 mb-1">2. Map cột file → trường chuẩn</h2>
                <p class="text-xs text-ink/50 mb-4">Hệ thống đã tự đoán theo tên cột, kiểm tra lại trước khi import.</p>
                @error('mapping')<p class="text-xs text-red-600 mb-2">{{ $message }}</p>@enderror
                <div class="space-y-2.5">
                    @foreach (self::TARGETS as $target => $label)
                        <div class="grid grid-cols-2 gap-3 items-center">
                            <label class="text-sm font-medium">{{ $label }}</label>
                            <select wire:model="mapping.{{ $target }}" class="border border-gold-200 rounded-md px-2.5 py-1.5 text-sm bg-white focus:outline-none focus:border-gold-500">
                                <option value="">— bỏ qua —</option>
                                @foreach ($headers as $index => $header)
                                    <option value="{{ $index }}">{{ $header ?: "Cột " . ($index + 1) }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                </div>

                <button wire:click="import" wire:loading.attr="disabled"
                        class="mt-6 w-full bg-gold-600 hover:bg-gold-700 text-white font-semibold py-3 rounded-md">
                    3. Import ngay
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
        <table class="w-full text-sm">
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
