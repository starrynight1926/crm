<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6.27 — Lead thiếu SĐT & Kho gộp.
 *
 * - org_units.allow_lead_without_phone: bật/tắt cho phép upload lead không SĐT theo cơ sở.
 * - leads.no_phone: đánh dấu lead rơi vào kho "Lead thiếu info" (upload mà không có SĐT).
 *
 * Giữ nguyên constraint UNIQUE trên leads.phone — MySQL cho phép nhiều NULL trong unique index,
 * nên không cần migration cấu trúc cột phone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('org_units', function (Blueprint $table) {
            $table->boolean('allow_lead_without_phone')->default(false)->after('active');
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->boolean('no_phone')->default(false)->after('phone');
            $table->index('no_phone');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['no_phone']);
            $table->dropColumn('no_phone');
        });

        Schema::table('org_units', function (Blueprint $table) {
            $table->dropColumn('allow_lead_without_phone');
        });
    }
};
