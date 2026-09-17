<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mẫu báo cáo theo team: chọn trường + option nào làm cột thống kê.
 * Mỗi team có nhiều mẫu đặt tên; tab "Báo cáo theo team" render theo mẫu đang chọn.
 *
 * config = [
 *   'columns' => [
 *     ['field_id' => 4, 'type' => 'select', 'options' => ['Quan tâm','Tìm hiểu',...]],
 *     ['field_id' => 10, 'type' => 'tick'],   // tick: 1 cột, đếm lead có tích
 *   ],
 *   'owner' => ['field_id' => 5, 'options' => ['Missed','Follow',...]] | null,  // breakdown theo người
 * ]
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_unit_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('config')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('org_unit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_templates');
    }
};
