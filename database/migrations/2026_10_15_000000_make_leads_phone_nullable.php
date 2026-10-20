<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6.27.d — Cho phép leads.phone nullable để lưu lead "thiếu info".
 * Note 6.27.a trước có nhầm: chỉ thêm cột no_phone, không nullable hoá phone.
 * Unique index trên phone vẫn giữ (MySQL cho phép nhiều NULL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('phone', 20)->nullable(false)->change();
        });
    }
};
