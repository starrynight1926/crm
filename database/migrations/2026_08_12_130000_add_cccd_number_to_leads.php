<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-08-12 — Cột số CCCD của khách. Ảnh CCCD đã có ở migration
 * 2026_08_12_120000. Bổ sung cột số để form khách hàng có đủ 2 trường.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $t) {
            $t->string('cccd_number', 32)->nullable()->after('cccd_image_path');
            $t->index('cccd_number');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $t) {
            $t->dropIndex(['cccd_number']);
            $t->dropColumn('cccd_number');
        });
    }
};
