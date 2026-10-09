<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Đợt C.1 (2026-08-25) — Kéo pivot dich_vu_phong từ sbooking về sb_dich_vu_phong.
 * Sync 1 chiều. FULL REPLACE: xoá hết mirror rồi insert lại theo nguồn
 * (pivot không có id nghiệp vụ ổn định, thay đổi mapping cũng cần dọn sạch).
 *
 * Usage: php artisan sb:sync-dich-vu-phong [--dry-run]
 */
class SyncDichVuPhongFromSbooking extends Command
{
    protected $signature = 'sb:sync-dich-vu-phong {--dry-run}';

    protected $description = 'Kéo pivot dich_vu_phong từ lara-sbooking về sb_dich_vu_phong (full replace)';

    public function handle(): int
    {
        $baseUrl = rtrim(config('services.booking.api_url') ?: '', '/');
        $token = config('services.booking.api_token');

        if (! $token) {
            $this->error('Thiếu BOOKING_API_TOKEN trong .env.');
            return self::FAILURE;
        }

        $url = $baseUrl . '/sync/dich-vu-phong';
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
        $this->info('Nhận ' . count($rows) . ' mappings.');

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        // MySQL TRUNCATE implicit-commits, không lồng trong transaction được → xoá bằng DELETE.
        DB::table('sb_dich_vu_phong')->delete();
        $now = now();
        $insert = [];
        foreach ($rows as $r) {
            $insert[] = [
                'sbooking_dich_vu_id' => (int) $r['dich_vu_id'],
                'sbooking_phong_id'   => (int) $r['phong_id'],
                'synced_at'           => $now,
                'created_at'          => $now,
                'updated_at'          => $now,
            ];
            if (count($insert) >= 500) {
                DB::table('sb_dich_vu_phong')->insert($insert);
                $insert = [];
            }
        }
        if ($insert) DB::table('sb_dich_vu_phong')->insert($insert);

        $count = DB::table('sb_dich_vu_phong')->count();
        $this->info("Xong. Tổng mirror: {$count}");
        Log::info('sb:sync-dich-vu-phong', ['count' => $count]);
        return self::SUCCESS;
    }
}
