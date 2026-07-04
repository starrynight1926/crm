<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Trường tùy biến theo phòng ban (org_unit_id null = mức công ty, áp mọi bộ phận)
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_unit_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('label');
            $table->string('field_type', 20)->default('text'); // text / number / date / select
            $table->json('options')->nullable(); // danh sách chọn cho select
            $table->boolean('required')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['org_unit_id', 'key']);
        });

        Schema::create('lead_custom_values', function (Blueprint $table) {
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('custom_field_id')->constrained()->cascadeOnDelete();
            $table->text('value')->nullable();
            $table->primary(['lead_id', 'custom_field_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_custom_values');
        Schema::dropIfExists('custom_fields');
    }
};
