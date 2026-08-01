<?php

namespace App\Console\Commands;

use App\Models\SbService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase C1 (2026-08-01) — Kéo dich_vu từ sbooking về sb_services.
 * Sync 1 chiều, upsert theo sbooking_id. Idempotent — chạy lại không tạo trùng.
 *
 * Usage: php artisan sb:sync-services
 */
class SyncServicesFromSbooking extends Command
{
    protected $signature = 'sb:sync-services {--dry-run : không ghi DB, chỉ log}';

    protected $description = 'Kéo danh sách dịch vụ (dich_vu) từ lara-sbooking về sb_services';

    public function handle(): int
    {
        $url = rtrim(config('services.booking.api_url') ?: '', '/') . '/sync/dich-vu';
        $token = config('services.booking.api_token');

        if (! $token) {
            $this->error('Thiếu BOOKING_API_TOKEN trong .env — không có auth để gọi sbooking.');
            return self::FAILURE;
        }

        $this->info("Gọi: {$url}");

        try {
            $response = Http::withToken($token)->timeout(30)->acceptJson()->get($url);
        } catch (Throwable $e) {
            $this->error('HTTP fail: ' . $e->getMessage());
            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error("HTTP {$response->status()}: " . $response->body());
            return self::FAILURE;
        }

        $rows = $response->json('data') ?? [];
        $this->info('Nhận ' . count($rows) . ' dịch vụ từ sbooking.');

        if ($this->option('dry-run')) {
            $this->line(json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $created = 0;
        $updated = 0;

        foreach ($rows as $r) {
            $attrs = [
                'sbooking_co_so_id' => $r['co_so_id'] ?? null,
                'ten' => $r['ten'] ?? '',
                'thoi_gian_phut' => (int) ($r['thoi_gian_phut'] ?? 30),
                'thuoc_nhom' => $r['thuoc_nhom'] ?? 'khac',
                'la_dich_vu' => (bool) ($r['la_dich_vu'] ?? false),
                'active' => (bool) ($r['active'] ?? true),
                'synced_at' => now(),
            ];

            $existing = SbService::where('sbooking_id', $r['id'])->first();
            if ($existing) {
                $existing->update($attrs);
                $updated++;
            } else {
                SbService::create(array_merge(['sbooking_id' => $r['id']], $attrs));
                $created++;
            }
        }

        $this->info("Xong. Tạo mới: {$created}, cập nhật: {$updated}, tổng: " . count($rows));
        Log::info('sb:sync-services', ['created' => $created, 'updated' => $updated, 'total' => count($rows)]);

        return self::SUCCESS;
    }
}
