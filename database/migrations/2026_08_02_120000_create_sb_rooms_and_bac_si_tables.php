<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase C1.d (2026-08-02) — mirror sbooking.phong + sbooking.bac_si sang scrm.
 * Sync 1 chiều sbooking → scrm. Dùng cho dropdown chọn Phòng + BS ở form booking.
 * Thêm 2 cột bookings_logs.sb_phong_id + sb_bac_si_id để push kèm sang sbooking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sb_rooms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sbooking_id')->unique()->comment('id bên sbooking.phong');
            $table->unsignedBigInteger('sbooking_co_so_id')->index()->comment('id co_so bên sbooking');
            $table->string('ten');
            $table->string('loai', 20)->nullable();
            $table->string('kieu_phong', 20)->nullable()->comment('phong_kham | phong_dich_vu');
            $table->unsignedSmallInteger('so_slot_toi_da')->default(1);
            $table->unsignedSmallInteger('phut_moi_khach')->default(0);
            $table->string('trang_thai', 20)->default('hoat_dong');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['sbooking_co_so_id', 'trang_thai']);
        });

        Schema::create('sb_bac_si', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sbooking_id')->unique()->comment('id bên sbooking.bac_si');
            $table->unsignedBigInteger('sbooking_co_so_id')->index()->comment('id co_so bên sbooking');
            $table->string('ten');
            $table->string('chuc_danh', 40)->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('xuat_hien_moi_co_so')->default(false);
            $table->boolean('nhan_tu_van')->default(false);
            $table->unsignedSmallInteger('phut_tu_van')->default(30);
            $table->boolean('nhan_kham_ls')->default(false);
            $table->unsignedSmallInteger('phut_kham_ls')->default(30);
            $table->string('gio_bat_dau', 8)->nullable();
            $table->string('gio_ket_thuc', 8)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['sbooking_co_so_id', 'active']);
        });

        Schema::table('booking_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('sb_phong_id')->nullable()->after('facility_id')->comment('sbooking.phong.id đã chọn ở form scrm');
            $table->unsignedBigInteger('sb_bac_si_id')->nullable()->after('doctor_id')->comment('sbooking.bac_si.id đã chọn ở form scrm (thay staff_members)');
        });
    }

    public function down(): void
    {
        Schema::table('booking_logs', function (Blueprint $table) {
            $table->dropColumn(['sb_phong_id', 'sb_bac_si_id']);
        });
        Schema::dropIfExists('sb_bac_si');
        Schema::dropIfExists('sb_rooms');
    }
};
