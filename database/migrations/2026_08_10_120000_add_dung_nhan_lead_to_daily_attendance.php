<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-08-10 — Cột `dung_nhan_lead` cho phép sale tự loại khỏi UPS list.
 * Khác `is_busy` (đang tiếp đón — vẫn bị chia khi wrap-around) ở chỗ:
 * `dung_nhan_lead=true` → dispatcher SKIP tuyệt đối, kể cả fallback wrap-around.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('daily_attendance', function (Blueprint $t) {
            $t->boolean('dung_nhan_lead')->default(false)->after('is_busy');
            $t->timestamp('dung_nhan_lead_since')->nullable()->after('dung_nhan_lead');
            $t->index(['facility_pool_unit_id', 'work_date', 'dung_nhan_lead'], 'idx_daily_att_pause');
        });
    }

    public function down(): void
    {
        Schema::table('daily_attendance', function (Blueprint $t) {
            $t->dropIndex('idx_daily_att_pause');
            $t->dropColumn(['dung_nhan_lead', 'dung_nhan_lead_since']);
        });
    }
};
