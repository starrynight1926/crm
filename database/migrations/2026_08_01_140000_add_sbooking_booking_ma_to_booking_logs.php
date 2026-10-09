<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase C1.b rev4 (2026-08-01) — lưu mã booking bên sbooking (VD BKG-260801-000007)
 * vào booking_logs để hiển thị + search.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_logs', function (Blueprint $table) {
            $table->string('sbooking_booking_ma', 40)->nullable()->after('sbooking_booking_id')
                ->comment('mã booking bên sbooking, VD BKG-260801-000007');
            $table->index('sbooking_booking_ma');
        });
    }

    public function down(): void
    {
        Schema::table('booking_logs', function (Blueprint $table) {
            $table->dropIndex(['sbooking_booking_ma']);
            $table->dropColumn('sbooking_booking_ma');
        });
    }
};
