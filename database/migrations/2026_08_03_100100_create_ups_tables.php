<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ups_config', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_pool_unit_id')->constrained('pool_units')->cascadeOnDelete();
            $table->time('cutoff_time')->default('08:35:00');
            $table->timestamps();
            $table->unique('facility_pool_unit_id');
        });

        Schema::create('daily_attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_pool_unit_id')->constrained('pool_units')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('work_date');
            $table->dateTime('checkin_at')->nullable();
            // A|B|C|OFF|MKT — nullable trước khi BO gán
            $table->string('list_bucket', 8)->nullable();
            $table->boolean('is_off')->default(false);
            $table->foreignId('override_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('override_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'work_date']);
            $table->index(['facility_pool_unit_id', 'work_date']);
        });

        Schema::create('ups_daily_confirm', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_pool_unit_id')->constrained('pool_units')->cascadeOnDelete();
            $table->date('work_date');
            $table->foreignId('confirmed_by')->constrained('users');
            $table->dateTime('confirmed_at');
            $table->timestamps();
            $table->unique(['facility_pool_unit_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ups_daily_confirm');
        Schema::dropIfExists('daily_attendance');
        Schema::dropIfExists('ups_config');
    }
};
