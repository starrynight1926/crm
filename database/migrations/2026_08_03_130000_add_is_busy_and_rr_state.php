<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6.25 — is_busy trên daily_attendance + bảng ups_rr_state cho round-robin theo bucket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_attendance', function (Blueprint $t) {
            $t->boolean('is_busy')->default(false)->after('is_off');
            $t->dateTime('busy_since')->nullable()->after('is_busy');
        });

        Schema::create('ups_rr_state', function (Blueprint $t) {
            $t->id();
            $t->foreignId('facility_pool_unit_id')->constrained('pool_units')->cascadeOnDelete();
            $t->date('work_date');
            $t->string('bucket', 8); // A / B / C / OFF / MKT
            $t->foreignId('last_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['facility_pool_unit_id', 'work_date', 'bucket'], 'ups_rr_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ups_rr_state');
        Schema::table('daily_attendance', function (Blueprint $t) {
            $t->dropColumn(['is_busy', 'busy_since']);
        });
    }
};
