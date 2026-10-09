<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 2026-10-09 — Kéo pivot dich_vu_bac_si từ sbooking về sb_dich_vu_bac_si (full replace).
 *
 * Usage: php artisan sb:sync-dich-vu-bac-si [--dry-run]
 */
class SyncDichVuBacSiFromSbooking extends Command
{
    protected $signature = 'sb:sync-dich-vu-bac-si {--dry-run}';

    protected $description = 'Kéo pivot dich_vu_bac_si từ lara-sbooking về sb_dich_vu_bac_si (full replace)';

    public function handle(): int
    {
        $baseUrl = rtrim(config('services.booking.api_url') ?: '', '/');
        $token = config('services.booking.api_token');

        if (! $token) {
            $this->error('Thiếu BOOKING_API_TOKEN trong .env.');
            return self::FAILURE;
        }

        $url = $baseUrl . '/sync/dich-vu-bac-si';
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

        DB::table('sb_dich_vu_bac_si')->delete();
        $now = now();
        $insert = [];
        foreach ($rows as $r) {
            $insert[] = [
                'sbooking_dich_vu_id' => (int) $r['dich_vu_id'],
                'sbooking_bac_si_id'  => (int) $r['bac_si_id'],
                'synced_at'           => $now,
                'created_at'          => $now,
                'updated_at'          => $now,
            ];
            if (count($insert) >= 500) {
                DB::table('sb_dich_vu_bac_si')->insert($insert);
                $insert = [];
            }
        }
        if ($insert) DB::table('sb_dich_vu_bac_si')->insert($insert);

        $count = DB::table('sb_dich_vu_bac_si')->count();
        $this->info("Xong. Tổng mirror: {$count}");
        Log::info('sb:sync-dich-vu-bac-si', ['count' => $count]);
        return self::SUCCESS;
    }
}
