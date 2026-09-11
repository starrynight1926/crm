<?php

namespace App\Jobs;

use App\Services\DataBackupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Chạy nền để tạo file zip sao lưu toàn bộ (data + config).
 * File được lưu tại storage/app/backups/lara-scrm-backup-*.zip.
 * Người dùng có thể tải về từ trang "Sao lưu & khôi phục".
 */
class BuildFullBackupJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public ?int $requestedByUserId = null) {}

    public function handle(DataBackupService $service): void
    {
        try {
            $path = $service->build($this->requestedByUserId);
            Log::info('BuildFullBackupJob: đã tạo ' . $path);

            if ($this->requestedByUserId) {
                // Ghi một dòng notification đơn giản để user biết file đã sẵn sàng.
                DB::table('notifications')->insert([
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'type' => 'system.backup_ready',
                    'notifiable_type' => \App\Models\User::class,
                    'notifiable_id' => $this->requestedByUserId,
                    'data' => json_encode([
                        'title' => 'Sao lưu hệ thống đã sẵn sàng',
                        'body' => 'File sao lưu đã tạo xong. Vào mục Thiết lập → Sao lưu & khôi phục để tải về.',
                        'file' => basename($path),
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('BuildFullBackupJob thất bại: ' . $e->getMessage(), ['ex' => $e]);
            throw $e;
        }
    }
}
