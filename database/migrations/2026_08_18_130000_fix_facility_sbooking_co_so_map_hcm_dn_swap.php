<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026-08-18 — Fix mapping cơ sở bên datasource ↔ sbooking bị swap giữa HCM và ĐN.
 *
 * Trước:
 *   Facility "HCM"       → sbooking_co_so_id = 3  (nhưng CS3 sbooking = Lô 2+3 TĐN = ĐN)
 *   Facility "Đà Nẵng"   → sbooking_co_so_id = 2  (nhưng CS2 sbooking = 207NVT = HCM)
 *
 * Hậu quả: lead ĐN tạo booking → SbookingClient::pushBooking dùng sb_co_so=2 →
 * booking rơi vào CS2 sbooking (HCM), không phải CS3 (ĐN). Admin ĐN không thấy.
 *
 * Fix: swap đúng label.
 *   HCM        → sb_co_so = 2 (207NVT HCM)
 *   Đà Nẵng    → sb_co_so = 3 (Lô 2+3 TĐN ĐN)
 *
 * Idempotent: chỉ update khi facility đang giữ mapping sai. Nếu đã đúng, no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        $hcm = DB::table('facilities')->where('name', 'HCM')->whereNull('parent_id')->first();
        $dn  = DB::table('facilities')->where('name', 'Đà Nẵng')->whereNull('parent_id')->first();

        if ($hcm && (int) $hcm->sbooking_co_so_id === 3) {
            DB::table('facilities')->where('id', $hcm->id)->update(['sbooking_co_so_id' => 2]);
        }
        if ($dn && (int) $dn->sbooking_co_so_id === 2) {
            DB::table('facilities')->where('id', $dn->id)->update(['sbooking_co_so_id' => 3]);
        }
    }

    public function down(): void
    {
        // Không revert — bản swap này là fix bug, revert sẽ tái tạo bug.
    }
};
