<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase C1.f (2026-08-02) — thêm 2 checkbox Tư vấn + Thăm khám lâm sàng vào booking_logs
 * để đồng bộ sang sbooking.booking.co_tu_van + co_kham_cls (giống ket_hop_medical).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_logs', function (Blueprint $table) {
            $table->boolean('co_tu_van')->default(false)->after('ket_hop_medical');
            $table->boolean('co_kham_cls')->default(false)->after('co_tu_van');
        });
    }

    public function down(): void
    {
        Schema::table('booking_logs', function (Blueprint $table) {
            $table->dropColumn(['co_tu_van', 'co_kham_cls']);
        });
    }
};
