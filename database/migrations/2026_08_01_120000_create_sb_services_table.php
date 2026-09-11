<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase C1 (2026-08-01) — mirror sbooking.dich_vu về scrm.sb_services.
 * Sync 1 chiều sbooking → scrm. Dùng cho dropdown chọn dịch vụ ở phase booking.
 * Không thay bảng services (giữ nguyên cho luồng thu tiền + % đóng góp).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sb_services', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sbooking_id')->unique()->comment('id bên sbooking.dich_vu');
            $table->unsignedBigInteger('sbooking_co_so_id')->nullable()->index()->comment('id co_so bên sbooking, null = dùng chung');
            $table->string('ten');
            $table->unsignedSmallInteger('thoi_gian_phut')->default(30);
            $table->string('thuoc_nhom', 20)->default('khac')->comment('tu_van | kham_ls | khac');
            $table->boolean('la_dich_vu')->default(false)->comment('false = Thăm khám, true = Dịch vụ');
            $table->boolean('active')->default(true);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['la_dich_vu', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sb_services');
    }
};
