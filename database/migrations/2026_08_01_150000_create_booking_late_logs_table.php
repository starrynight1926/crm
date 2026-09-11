<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase C1.b rev5 (2026-08-01) — bảng log khách tới trễ.
 * Ghi khi sbooking push trạng thái khách = 'toi_tre' về scrm.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_late_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_log_id')->constrained('booking_logs')->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->unsignedBigInteger('sbooking_booking_id')->nullable()->index();
            $table->string('sbooking_booking_ma', 40)->nullable();
            $table->timestamp('expected_at')->nullable()->comment('booking_logs.scheduled_at');
            $table->timestamp('arrived_at')->nullable()->comment('lúc sbooking mark toi_tre');
            $table->unsignedSmallInteger('late_minutes')->nullable();
            $table->string('marked_by', 100)->nullable()->comment('tên user sbooking đã mark');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['lead_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_late_logs');
    }
};
