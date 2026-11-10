<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B1d (2026-08-14) — MKT recall (đặc thù riêng, chỉ áp nguồn MKT).
 *
 * Spec: lead MKT có 3 chặng deadline:
 *   - Gán sale → 1 ngày để ghi cuộc gọi.
 *   - Ghi cuộc gọi → 3 ngày để cập nhật phân loại/kết quả.
 *   - Tạo booking → 30 ngày để lịch tiến triển.
 * Deadline nào sớm nhất chưa thỏa mãn → recall.
 *
 * Dùng cột riêng để không đụng recall_at hiện có (SLA policy chung cho các nguồn khác).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $t) {
            $t->timestamp('mkt_recall_at')->nullable()->after('recall_at')
                ->comment('B1d: deadline recall cho lead nguồn MKT (single field state machine)');
            $t->index(['source_group', 'mkt_recall_at']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $t) {
            $t->dropIndex(['source_group', 'mkt_recall_at']);
            $t->dropColumn('mkt_recall_at');
        });
    }
};
