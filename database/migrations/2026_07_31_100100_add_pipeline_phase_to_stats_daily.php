<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bổ sung chiều `pipeline_phase` cho stats_daily để báo cáo Funnel/Hiệu suất
 * gộp thêm theo bước xử lý (booking/sale). Update unique index để mỗi phase
 * là 1 dòng riêng trong cùng tổ hợp (date, org, user, camp).
 *
 * Note: cột ad_source đã bị drop bởi 2026_07_20_140000_drop_ad_source_columns;
 * unique hiện tại là (date, org_unit_id, user_id, camp).
 *
 * Data cũ backfill = null; user cần chạy lại `stats:aggregate` cho khoảng
 * ngày muốn số liệu chuẩn theo phase.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('stats_daily', function (Blueprint $table) {
            $table->string('pipeline_phase', 16)->nullable()->after('camp');
        });

        Schema::table('stats_daily', function (Blueprint $table) {
            $table->dropUnique('stats_daily_dims_unique');
        });

        Schema::table('stats_daily', function (Blueprint $table) {
            $table->unique(
                ['date', 'org_unit_id', 'user_id', 'camp', 'pipeline_phase'],
                'stats_daily_dims_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('stats_daily', function (Blueprint $table) {
            $table->dropUnique('stats_daily_dims_unique');
        });

        Schema::table('stats_daily', function (Blueprint $table) {
            $table->unique(
                ['date', 'org_unit_id', 'user_id', 'camp'],
                'stats_daily_dims_unique'
            );
            $table->dropColumn('pipeline_phase');
        });
    }
};
