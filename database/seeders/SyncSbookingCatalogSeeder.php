<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * 2026-08-28 — Kéo catalog từ sbooking sau seed để SCRM có đủ metadata BS/DV/phòng.
 *
 * Trước đây thao tác này chỉ chạy tay (`php artisan sb:sync-*`). Hệ quả:
 *   - Sau `db:seed` fresh, table sb_bac_si trống → SCRM lead-form dùng default
 *     phut_kham_ls=5 → booking gửi khung 5 phút → sbooking từ chối (BS cần 30).
 *   - sb_services / sb_rooms cũng trống → dropdown DV/phòng thiếu.
 *
 * Idempotent: mỗi command updateOrCreate theo sbooking_id.
 * Yêu cầu sbooking đang chạy và BOOKING_API_URL/TOKEN đã cấu hình.
 */
class SyncSbookingCatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['sb:sync-bac-si', 'sb:sync-services', 'sb:sync-rooms'] as $cmd) {
            try {
                Artisan::call($cmd);
                $this->command?->info("SyncSbookingCatalog: $cmd — " . trim(Artisan::output()));
            } catch (\Throwable $e) {
                $this->command?->warn("SyncSbookingCatalog: $cmd fail — {$e->getMessage()}. Sbooking có đang chạy không?");
            }
        }
    }
}
