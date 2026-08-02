<?php

namespace App\Console\Commands;

use App\Models\SbBacSi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase C1.d (2026-08-02) — Kéo bac_si từ sbooking về sb_bac_si.
 * Sync 1 chiều, upsert theo sbooking_id. Idempotent.
 *
 * Usage: php artisan sb:sync-bac-si
 */
class SyncBacSiFromSbooking extends Command
{
    protected $signature = 'sb:sync-bac-si {--dry-run}';

    protected $description = 'Kéo danh sách bác sĩ (bac_si) từ lara-sbooking về sb_bac_si';

    public function handle(): int
    {
        $url = rtrim(config('services.booking.api_url') ?: '', '/') . '/sync/bac-si';
        $token = config('services.booking.api_token');

        if (! $token) {
            $this->error('Thiếu BOOKING_API_TOKEN trong .env.');
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
        $this->info('Nhận ' . count($rows) . ' bác sĩ từ sbooking.');

        if ($this->option('dry-run')) return self::SUCCESS;

        $created = 0; $updated = 0;
        foreach ($rows as $r) {
            $attrs = [
                'sbooking_co_so_id' => $r['co_so_id'],
                'ten' => $r['ten'] ?? '',
                'chuc_danh' => $r['chuc_danh'] ?? null,
                'active' => (bool) ($r['active'] ?? true),
                'xuat_hien_moi_co_so' => (bool) ($r['xuat_hien_moi_co_so'] ?? false),
                'nhan_tu_van' => (bool) ($r['nhan_tu_van'] ?? false),
                'phut_tu_van' => (int) ($r['phut_tu_van'] ?? 30),
                'nhan_kham_ls' => (bool) ($r['nhan_kham_ls'] ?? false),
                'phut_kham_ls' => (int) ($r['phut_kham_ls'] ?? 30),
                'gio_bat_dau' => $r['gio_bat_dau'] ?? null,
                'gio_ket_thuc' => $r['gio_ket_thuc'] ?? null,
                'synced_at' => now(),
            ];

            $existing = SbBacSi::where('sbooking_id', $r['id'])->first();
            if ($existing) { $existing->update($attrs); $updated++; }
            else { SbBacSi::create(array_merge(['sbooking_id' => $r['id']], $attrs)); $created++; }
        }

        $this->info("Xong. Tạo mới: {$created}, cập nhật: {$updated}, tổng: " . count($rows));
        Log::info('sb:sync-bac-si', ['created' => $created, 'updated' => $updated, 'total' => count($rows)]);
        return self::SUCCESS;
    }
}
