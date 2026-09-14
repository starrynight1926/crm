<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026-09-14 — Đổi booking_co_so_slug bên sbooking cho root facility ĐN
 * từ 'l23tdn' → '11-15tdn' theo địa chỉ mới (Số 11-15 Trần Đăng Ninh).
 * Idempotent: chỉ đổi khi slug đang bằng đúng 'l23tdn'.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('facilities')
            ->whereNull('parent_id')
            ->where('booking_co_so_slug', 'l23tdn')
            ->update(['booking_co_so_slug' => '11-15tdn', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('facilities')
            ->whereNull('parent_id')
            ->where('booking_co_so_slug', '11-15tdn')
            ->update(['booking_co_so_slug' => 'l23tdn', 'updated_at' => now()]);
    }
};
