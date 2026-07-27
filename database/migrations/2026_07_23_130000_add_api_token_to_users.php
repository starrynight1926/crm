<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6.21 — Thêm api_token cho user (secret dùng cho booking → CRM push).
 * Chung cả 2 hệ CRM & lara-sbooking: seed cùng giá trị theo email.
 * Đổi mật khẩu → observer xóa token, cần reset thủ công 2 hệ.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('api_token', 80)->nullable()->unique()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('api_token');
        });
    }
};
