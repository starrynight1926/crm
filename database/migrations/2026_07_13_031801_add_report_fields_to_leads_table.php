<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // --- INSIGHT ---
            $table->date('birthday')->nullable()->after('region');
            $table->string('address', 500)->nullable()->after('birthday');
            $table->text('medical_history')->nullable()->after('address');
            $table->string('occupation', 150)->nullable()->after('medical_history');

            // --- Dịch vụ tổng ---
            $table->string('service_name', 255)->nullable()->after('consultant_3_id');

            // --- LIỆU TRÌNH (ngày thực hiện) ---
            $table->date('treatment_1')->nullable()->after('service_name');
            $table->date('treatment_2')->nullable()->after('treatment_1');
            $table->date('treatment_3')->nullable()->after('treatment_2');
            $table->date('treatment_4')->nullable()->after('treatment_3');

            // --- Bác sĩ thực hiện (khác bác sĩ tư vấn) ---
            $table->foreignId('performing_doctor_id')->nullable()->after('treatment_4')
                ->constrained('staff_members')->nullOnDelete();

            // --- Đánh giá CLCM ---
            $table->text('quality_rating')->nullable()->after('performing_doctor_id');

            // --- Dịch vụ tiềm năng ---
            $table->text('potential_service')->nullable()->after('quality_rating');

            // --- UPSELL ---
            $table->decimal('upsell_cv1', 15, 0)->nullable()->after('potential_service');
            $table->decimal('upsell_cv2', 15, 0)->nullable()->after('upsell_cv1');
            $table->decimal('upsell_cv3', 15, 0)->nullable()->after('upsell_cv2');
            $table->decimal('upsell_service', 15, 0)->nullable()->after('upsell_cv3');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['performing_doctor_id']);
            $table->dropColumn([
                'birthday', 'address', 'medical_history', 'occupation',
                'service_name',
                'treatment_1', 'treatment_2', 'treatment_3', 'treatment_4',
                'performing_doctor_id', 'quality_rating',
                'potential_service',
                'upsell_cv1', 'upsell_cv2', 'upsell_cv3', 'upsell_service',
            ]);
        });
    }
};
