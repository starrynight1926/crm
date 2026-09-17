<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-04 — Mirror booking.ho_tro_id (KTV/DD hỗ trợ) từ sbooking sang scrm.
 * Push kèm khi tạo booking từ lead form (Phase C — design v3).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('booking_logs', function (Blueprint $t) {
            $t->unsignedBigInteger('sb_ho_tro_id')->nullable()->after('sb_bac_si_id')
                ->comment('sbooking.bac_si.id — nhân viên hỗ trợ ca (KTV/DD)');
        });
    }

    public function down(): void
    {
        Schema::table('booking_logs', function (Blueprint $t) {
            $t->dropColumn('sb_ho_tro_id');
        });
    }
};
