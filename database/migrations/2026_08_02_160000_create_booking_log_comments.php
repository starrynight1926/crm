<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase C1.f (2026-08-02) — bảng trao đổi 2 chiều check-in ↔ sbooking.
 * Chứa comment cả từ scrm (user_id FK) và sbooking (user_name text, sbooking_user_id).
 * Sync 2 chiều: scrm add → push sbooking; sbooking add → callback event 'comment' → insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_log_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_log_id')->constrained('booking_logs')->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->string('source', 10)->default('scrm')->comment('scrm | sbooking');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete()->comment('null nếu source=sbooking');
            $table->unsignedBigInteger('sbooking_user_id')->nullable()->comment('id user bên sbooking khi source=sbooking');
            $table->string('user_name', 120)->comment('snapshot tên để hiển thị, không đổi khi user rename');
            $table->text('content');
            $table->timestamps();

            $table->index(['lead_id', 'created_at']);
            $table->index(['booking_log_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_log_comments');
    }
};
