<?php

use App\Jobs\BuildFullBackupJob;
use App\Services\ConfigBackupService;
use App\Services\DataBackupService;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public $uploadedConfig;

    /** ['summary' => [table => [file,db,add,update,delete]], 'errors' => [...]] */
    public ?array $diffReport = null;

    public string $mode = 'merge'; // merge | replace

    public string $replaceConfirm = '';

    public ?string $flash = null;

    public ?string $flashKind = null; // ok | err

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermission('system.backup'), 403);
    }

    /** Xuất cấu hình → trả file JSON tải về ngay. */
    public function exportConfig(ConfigBackupService $svc)
    {
        $payload = $svc->export();
        $filename = 'config-' . now()->format('Ymd-His') . '.json';
        // Lưu bản chính thức vào lịch sử luôn.
        $svc->exportToFile($filename);

        return response()->streamDownload(
            fn () => print(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)),
            $filename,
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    /** Phân tích file JSON đã upload, hiện diff nhưng chưa ghi. */
    public function analyzeConfig(ConfigBackupService $svc): void
    {
        $this->validate(['uploadedConfig' => 'required|file|mimes:json,txt|max:20480']);

        $raw = file_get_contents($this->uploadedConfig->getRealPath());
        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            $this->addError('uploadedConfig', 'File JSON không hợp lệ.');
            return;
        }

        try {
            $this->diffReport = $svc->diff($payload);
            $this->flashKind = 'ok';
            $this->flash = 'Đã phân tích. Kiểm tra bảng bên dưới trước khi nhấn "Nhập cấu hình".';
        } catch (\Throwable $e) {
            $this->addError('uploadedConfig', $e->getMessage());
            $this->diffReport = null;
        }
    }

    /** Thực thi nhập cấu hình theo mode đã chọn. */
    public function importConfig(ConfigBackupService $svc): void
    {
        $this->validate(['uploadedConfig' => 'required|file|mimes:json,txt|max:20480']);
        if (! in_array($this->mode, ['merge', 'replace'], true)) {
            $this->flashKind = 'err';
            $this->flash = 'Chế độ nhập không hợp lệ.';
            return;
        }
        if ($this->mode === 'replace' && $this->replaceConfirm !== 'REPLACE') {
            $this->flashKind = 'err';
            $this->flash = 'Chế độ REPLACE yêu cầu gõ đúng "REPLACE" để xác nhận.';
            return;
        }

        $raw = file_get_contents($this->uploadedConfig->getRealPath());
        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            $this->addError('uploadedConfig', 'File JSON không hợp lệ.');
            return;
        }

        try {
            $result = $svc->import($payload, $this->mode);
            $this->flashKind = 'ok';
            $this->flash = 'Nhập cấu hình thành công. Bản sao lưu trước đó được lưu tại: '
                . $result['backup_before'];
            $this->reset(['uploadedConfig', 'diffReport', 'replaceConfirm']);
        } catch (\Throwable $e) {
            $this->flashKind = 'err';
            $this->flash = 'Nhập cấu hình thất bại: ' . $e->getMessage();
        }
    }

    /** Đưa yêu cầu xuất toàn bộ dữ liệu vào hàng đợi. */
    public function requestFullBackup(): void
    {
        BuildFullBackupJob::dispatch(auth()->id());
        $this->flashKind = 'ok';
        $this->flash = 'Đã tiếp nhận yêu cầu sao lưu. File sẽ xuất hiện trong danh sách khi hoàn tất '
            . '(có thể mất vài phút với dữ liệu lớn).';
    }

    public function downloadBackup(string $file, DataBackupService $svc)
    {
        abort_unless(preg_match('/^lara-scrm-backup-[\w\-]+\.zip$/', $file), 404);
        $path = 'backups/' . $file;
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response()->download(Storage::disk('local')->path($path), $file);
    }

    public function downloadConfigBackup(string $file, ConfigBackupService $svc)
    {
        abort_unless(preg_match('/^[\w\-\.]+\.json$/', $file), 404);
        $path = 'config-backups/' . $file;
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response()->download(Storage::disk('local')->path($path), $file);
    }

    public function with(): array
    {
        return [
            'configBackups' => app(ConfigBackupService::class)->listBackupFiles(),
            'fullBackups' => app(DataBackupService::class)->listBackupFiles(),
        ];
    }
};
?>

<div class="space-y-8">
    <div>
        <h1 class="text-2xl font-bold text-gold-700">Sao lưu &amp; khôi phục hệ thống</h1>
        <p class="text-sm text-ink/60 mt-1">
            Ba chức năng: <strong>Xuất cấu hình</strong> ra file JSON để sao lưu, <strong>Nhập cấu hình</strong>
            từ file JSON để khôi phục, và <strong>Xuất toàn bộ dữ liệu</strong> ra file ZIP bao gồm bảng tính Excel
            (khách hàng / công ty / nhân sự) cùng file cấu hình.
        </p>
    </div>

    @if ($flash)
        <div class="text-sm px-4 py-3 rounded-md border {{ $flashKind === 'err' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-emerald-50 border-emerald-200 text-emerald-700' }}">
            {{ $flash }}
        </div>
    @endif

    {{-- 1. XUẤT CẤU HÌNH --}}
    <section class="bg-white border border-gold-200 rounded-xl p-6 shadow-card">
        <h2 class="text-lg font-bold mb-1">1. Xuất cấu hình hiện tại</h2>
        <p class="text-sm text-ink/60 mb-4">
            Tải về file JSON chứa toàn bộ cấu hình (người dùng, vai trò, phân quyền, rule chia số, dịch vụ, nguồn, trường tùy biến...).
            Không kèm dữ liệu khách hàng.
        </p>
        <button wire:click="exportConfig" type="button"
            class="bg-gold-600 hover:bg-gold-700 text-white text-sm font-semibold px-5 py-2.5 rounded-md">
            Tải file cấu hình (.json)
        </button>
    </section>

    {{-- 2. NHẬP CẤU HÌNH --}}
    <section class="bg-white border border-gold-200 rounded-xl p-6 shadow-card">
        <h2 class="text-lg font-bold mb-1">2. Nhập cấu hình từ file</h2>
        <p class="text-sm text-ink/60 mb-4">
            Tải lên file JSON đã xuất trước đó. Nhấn <em>Phân tích</em> để xem trước thay đổi, sau đó chọn chế độ nhập.
            Hệ thống sẽ tự động sao lưu cấu hình hiện tại trước khi ghi đè.
        </p>

        <div class="flex flex-wrap items-end gap-3">
            <label class="text-sm">
                <span class="block text-ink/70 mb-1 font-semibold">File cấu hình (.json)</span>
                <input type="file" wire:model="uploadedConfig" accept=".json,application/json"
                    class="text-sm block">
            </label>
            <button wire:click="analyzeConfig" type="button"
                class="border border-gold-300 text-gold-700 hover:bg-gold-50 text-sm font-semibold px-4 py-2 rounded-md">
                Phân tích
            </button>
        </div>
        @error('uploadedConfig') <p class="text-sm text-red-600 mt-2">{{ $message }}</p> @enderror

        @if ($diffReport)
            <div class="mt-5 overflow-x-auto">
                <table class="min-w-full text-sm border border-gold-100">
                    <thead class="bg-gold-50 text-ink/70">
                        <tr>
                            <th class="text-left px-3 py-2 font-semibold">Bảng</th>
                            <th class="text-right px-3 py-2 font-semibold">Trong file</th>
                            <th class="text-right px-3 py-2 font-semibold">Trong hệ thống</th>
                            <th class="text-right px-3 py-2 font-semibold text-emerald-700">Thêm mới</th>
                            <th class="text-right px-3 py-2 font-semibold text-blue-700">Cập nhật</th>
                            <th class="text-right px-3 py-2 font-semibold text-red-700">Xóa (chỉ REPLACE)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($diffReport['summary'] as $table => $s)
                            <tr class="border-t border-gold-100">
                                <td class="px-3 py-1.5 font-mono">{{ $table }}</td>
                                <td class="px-3 py-1.5 text-right">{{ $s['file'] }}</td>
                                <td class="px-3 py-1.5 text-right">{{ $s['db'] }}</td>
                                <td class="px-3 py-1.5 text-right">{{ $s['add'] }}</td>
                                <td class="px-3 py-1.5 text-right">{{ $s['update'] }}</td>
                                <td class="px-3 py-1.5 text-right">{{ $s['delete'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-5 flex flex-wrap items-end gap-4">
                <label class="text-sm">
                    <span class="block text-ink/70 mb-1 font-semibold">Chế độ nhập</span>
                    <select wire:model.live="mode" class="border border-gold-300 rounded px-3 py-2 text-sm">
                        <option value="merge">Hợp nhất — chỉ thêm mới &amp; cập nhật theo id</option>
                        <option value="replace">Ghi đè hoàn toàn — xóa dữ liệu cũ, nạp lại từ file (nguy hiểm)</option>
                    </select>
                </label>
                @if ($mode === 'replace')
                    <label class="text-sm">
                        <span class="block text-red-700 mb-1 font-semibold">Gõ "REPLACE" để xác nhận</span>
                        <input type="text" wire:model="replaceConfirm"
                            class="border border-red-300 rounded px-3 py-2 text-sm font-mono" placeholder="REPLACE">
                    </label>
                @endif
                <button wire:click="importConfig" type="button"
                    class="bg-red-600 hover:bg-red-700 text-white text-sm font-semibold px-5 py-2.5 rounded-md">
                    Nhập cấu hình
                </button>
            </div>
        @endif

        @if (! empty($configBackups))
            <div class="mt-6">
                <h3 class="text-sm font-bold text-ink/70 mb-2">Lịch sử file cấu hình đã lưu</h3>
                <ul class="text-sm divide-y divide-gold-100 border border-gold-100 rounded">
                    @foreach (array_slice($configBackups, 0, 10) as $b)
                        <li class="px-3 py-2 flex items-center justify-between gap-4">
                            <span class="font-mono truncate">{{ $b['name'] }}</span>
                            <span class="text-ink/50 text-xs shrink-0">{{ $b['modified_at'] }} — {{ number_format($b['size'] / 1024, 1) }} KB</span>
                            <button wire:click="downloadConfigBackup('{{ $b['name'] }}')" type="button"
                                class="text-gold-700 hover:underline text-xs font-semibold">Tải về</button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </section>

    {{-- 3. XUẤT TOÀN BỘ DỮ LIỆU --}}
    <section class="bg-white border border-gold-200 rounded-xl p-6 shadow-card">
        <h2 class="text-lg font-bold mb-1">3. Xuất toàn bộ dữ liệu</h2>
        <p class="text-sm text-ink/60 mb-4">
            Yêu cầu này chạy nền và có thể mất vài phút. File hoàn tất sẽ hiện trong danh sách bên dưới.
            Nội dung: <span class="font-mono">data_khach.xlsx</span>,
            <span class="font-mono">data_congty.xlsx</span>,
            <span class="font-mono">data_nhansu.xlsx</span>,
            <span class="font-mono">config.json</span>.
        </p>
        <button wire:click="requestFullBackup" type="button"
            class="bg-gold-600 hover:bg-gold-700 text-white text-sm font-semibold px-5 py-2.5 rounded-md">
            Tạo bản sao lưu đầy đủ
        </button>

        @if (! empty($fullBackups))
            <div class="mt-6">
                <h3 class="text-sm font-bold text-ink/70 mb-2">Các bản sao lưu đầy đủ đã tạo</h3>
                <ul class="text-sm divide-y divide-gold-100 border border-gold-100 rounded">
                    @foreach (array_slice($fullBackups, 0, 20) as $b)
                        <li class="px-3 py-2 flex items-center justify-between gap-4">
                            <span class="font-mono truncate">{{ $b['name'] }}</span>
                            <span class="text-ink/50 text-xs shrink-0">{{ $b['modified_at'] }} — {{ number_format($b['size'] / 1024 / 1024, 2) }} MB</span>
                            <button wire:click="downloadBackup('{{ $b['name'] }}')" type="button"
                                class="text-gold-700 hover:underline text-xs font-semibold">Tải về</button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </section>
</div>
