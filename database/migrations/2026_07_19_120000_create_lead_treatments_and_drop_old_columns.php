<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6.11 — Liệu trình dạng thẻ 1-N.
 * Trước đây leads có 6 cột cứng cho liệu trình (treatment_1..4 + performing_doctor_id + quality_rating chung).
 * Đổi sang bảng con `lead_treatments`: mỗi lần liệu trình 1 row, có bác sĩ + đánh giá riêng.
 * Data cũ 0 row → drop cột không backfill.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('lead_treatments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence'); // Lần 1, 2, 3...
            $table->date('performed_at')->nullable();
            $table->foreignId('performing_doctor_id')->nullable()->constrained('staff_members')->nullOnDelete();
            $table->text('quality_rating')->nullable();
            $table->timestamps();

            $table->index(['lead_id', 'sequence']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('performing_doctor_id');
            $table->dropColumn(['treatment_1', 'treatment_2', 'treatment_3', 'treatment_4', 'quality_rating']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->date('treatment_1')->nullable();
            $table->date('treatment_2')->nullable();
            $table->date('treatment_3')->nullable();
            $table->date('treatment_4')->nullable();
            $table->foreignId('performing_doctor_id')->nullable()->constrained('staff_members')->nullOnDelete();
            $table->text('quality_rating')->nullable();
        });

        Schema::dropIfExists('lead_treatments');
    }
};
