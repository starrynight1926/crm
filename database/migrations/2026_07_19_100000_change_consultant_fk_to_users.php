<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6.9 — Đổi FK consultant_1/2/3_id từ staff_members → users.
 * Chuyên viên tư vấn = user thuộc team sale (có tài khoản đăng nhập, có perm lead.update),
 * không phải bảng staff_members riêng (bảng đó giữ cho doctor / performing_doctor).
 * Bảng staff_members đang rỗng → không phải migrate data.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('consultant_3_id');
            $table->dropConstrainedForeignId('consultant_2_id');
            $table->dropConstrainedForeignId('consultant_1_id');
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('consultant_1_id')->nullable()->constrained('users')->nullOnDelete()->after('doctor_id');
            $table->foreignId('consultant_2_id')->nullable()->constrained('users')->nullOnDelete()->after('consultant_1_id');
            $table->foreignId('consultant_3_id')->nullable()->constrained('users')->nullOnDelete()->after('consultant_2_id');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('consultant_3_id');
            $table->dropConstrainedForeignId('consultant_2_id');
            $table->dropConstrainedForeignId('consultant_1_id');
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('consultant_1_id')->nullable()->constrained('staff_members')->nullOnDelete()->after('doctor_id');
            $table->foreignId('consultant_2_id')->nullable()->constrained('staff_members')->nullOnDelete()->after('consultant_1_id');
            $table->foreignId('consultant_3_id')->nullable()->constrained('staff_members')->nullOnDelete()->after('consultant_2_id');
        });
    }
};
