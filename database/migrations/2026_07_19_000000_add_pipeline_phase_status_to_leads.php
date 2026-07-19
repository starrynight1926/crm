<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6.8 — Trục lifecycle mới:
 *  - pipeline_phase  : booking / sale (giai đoạn nghiệp vụ)
 *  - pipeline_status : waiting_distribute / in_care (trạng thái trong giai đoạn)
 *
 * Trục này độc lập với booking_status (đã đặt lịch chưa) và với org_unit_id/owner_id (dữ liệu).
 * Dùng để quyết định ai được sửa info cá nhân (cột trái) theo perm update_booking/update_sale.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('pipeline_phase', 10)->default('sale')->after('booking_status');
            $table->string('pipeline_status', 20)->default('in_care')->after('pipeline_phase');
            $table->index(['pipeline_phase', 'pipeline_status'], 'leads_pipeline_idx');
        });

        // Backfill data cũ: user chọn (b) — toàn bộ lead hiện tại là data test → set về sale/in_care.
        DB::table('leads')->update([
            'pipeline_phase'  => 'sale',
            'pipeline_status' => 'in_care',
        ]);
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex('leads_pipeline_idx');
            $table->dropColumn(['pipeline_phase', 'pipeline_status']);
        });
    }
};
