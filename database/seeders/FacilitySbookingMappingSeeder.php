<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 2026-08-28 — Ép mapping `facilities.sbooking_co_so_id` khớp đúng cơ sở sbooking.
 *
 * Trước đây phải map tay qua UI "Thiết lập → Kết nối Booking", dễ swap giữa HCM (2)
 * và ĐN (3) — bug đã có migration 2026_08_18 fix nhưng chỉ chạy 1 lần. Reseed hoặc
 * chỉnh tay có thể lệch lại → booking DN chạy vào cơ sở HCM sbooking → admin không thấy.
 *
 * Seeder này idempotent: chạy sau mỗi seed để lock mapping đúng.
 *
 *   Hà Nội   → sbooking co_so 1 (59ntn)
 *   HCM      → sbooking co_so 2 (207nvt)
 *   Đà Nẵng  → sbooking co_so 3 (lo23tdn)
 */
class FacilitySbookingMappingSeeder extends Seeder
{
    private const MAP = [
        'Hà Nội'  => 1,
        'HCM'     => 2,
        'Đà Nẵng' => 3,
    ];

    public function run(): void
    {
        foreach (self::MAP as $name => $sbId) {
            $affected = DB::table('facilities')
                ->where('name', $name)
                ->whereNull('parent_id')
                ->update(['sbooking_co_so_id' => $sbId]);
            if ($affected) {
                $this->command?->info("FacilitySbookingMappingSeeder: '$name' → sbooking_co_so_id=$sbId");
            }
        }
    }
}
