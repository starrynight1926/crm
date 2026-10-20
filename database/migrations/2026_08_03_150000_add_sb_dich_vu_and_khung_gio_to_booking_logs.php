<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6.25.C fix (2026-08-03) — push đầy đủ dịch vụ + khung giờ sang sbooking, không map best-effort.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_logs', function (Blueprint $t) {
            $t->unsignedBigInteger('sb_dich_vu_id')->nullable()->after('sb_bac_si_id');
            $t->unsignedBigInteger('sb_khung_gio_id')->nullable()->after('sb_dich_vu_id');
            $t->time('scheduled_end_at')->nullable()->after('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::table('booking_logs', function (Blueprint $t) {
            $t->dropColumn(['sb_dich_vu_id', 'sb_khung_gio_id', 'scheduled_end_at']);
        });
    }
};
