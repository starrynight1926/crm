<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mã tiếp đón — bắt buộc khi tick "Khách trở lại". Mỗi lần khách quay lại có 1 mã
 * tiếp đón riêng để đối soát và đưa vào báo cáo tần suất quay lại.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_status_logs', function (Blueprint $table) {
            $table->string('reception_code', 60)->nullable()->after('is_return');
        });
    }

    public function down(): void
    {
        Schema::table('lead_status_logs', function (Blueprint $table) {
            $table->dropColumn('reception_code');
        });
    }
};
