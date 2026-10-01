<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6.24 — Điều kiện recall linh hoạt.
 * `recall_conditions` JSON = danh sách điều kiện phải THỎA HẾT (AND) mới recall.
 * Rỗng / null = chỉ tính deadline (backward-compat).
 * Giá trị hỗ trợ:
 *   - 'no_activity' : sale không update last_care_at trong X ngày
 *   - 'no_booking'  : booking_status chưa vào [booked, khach_da_toi, khach_toi_tre, da_xong] sau X ngày
 *   - 'no_progress' : phân loại vẫn ở [new, lead, missed, goi_lai_sau, klld] sau X ngày
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('recall_policies', function (Blueprint $table) {
            $table->json('recall_conditions')->nullable()->after('recall_after_days');
        });
    }

    public function down(): void
    {
        Schema::table('recall_policies', function (Blueprint $table) {
            $table->dropColumn('recall_conditions');
        });
    }
};
