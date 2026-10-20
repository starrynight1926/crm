<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 rework (2026-08-01):
 * Mỗi booking (record trong booking_logs) có thể gắn nhiều Chuyên viên tư vấn (n người).
 * Bỏ giới hạn cứng 2 CV ở cấp lead — CV giờ per-booking.
 * position = thứ tự (1 = CV chính, dùng để xác định Sale phụ trách lead khi booking được duyệt).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_log_consultants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_log_id')->constrained('booking_logs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('position')->default(1);
            $table->timestamps();

            $table->unique(['booking_log_id', 'user_id']);
            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_log_consultants');
    }
};
