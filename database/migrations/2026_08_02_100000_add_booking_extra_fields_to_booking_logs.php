<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase C1.b rev9 (2026-08-02) — thêm 4 field booking để đồng bộ với sbooking:
 *   - so_lieu_trinh (VD "1/10")
 *   - so_luong_lo
 *   - dung_tich_lo
 *   - ket_hop_medical (checkbox)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_logs', function (Blueprint $table) {
            $table->string('so_lieu_trinh', 40)->nullable()->after('note');
            $table->string('so_luong_lo', 40)->nullable()->after('so_lieu_trinh');
            $table->string('dung_tich_lo', 40)->nullable()->after('so_luong_lo');
            $table->boolean('ket_hop_medical')->default(false)->after('dung_tich_lo');
        });
    }

    public function down(): void
    {
        Schema::table('booking_logs', function (Blueprint $table) {
            $table->dropColumn(['so_lieu_trinh', 'so_luong_lo', 'dung_tich_lo', 'ket_hop_medical']);
        });
    }
};
