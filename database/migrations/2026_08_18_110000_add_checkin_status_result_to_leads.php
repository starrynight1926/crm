<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-08-18 — Nhận state Phase 5 (Checkin) từ sbooking callback.
 *   - checkin_status  ∈ {checkin, doi_lich, huy_lich}  (Booking::TINH_TRANG_CHECKIN bên sbooking)
 *   - checkin_result  ∈ {tham_kham, tu_van, mua_hang, khong_mua, hoan_thanh, huy_lich_tao_moi} nullable
 *
 * classification hiện có (Lead::CLASSIFICATIONS) đã nhận 'follow/booking/close' từ sbooking payload —
 * không thêm cột mới cho phân loại.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $t) {
            $t->string('checkin_status', 20)->nullable()->after('classification');
            $t->string('checkin_result', 30)->nullable()->after('checkin_status');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $t) {
            $t->dropColumn(['checkin_status', 'checkin_result']);
        });
    }
};
