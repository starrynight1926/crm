<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_logs', function (Blueprint $table) {
            $table->foreignId('facility_id')->nullable()->after('lead_id')
                ->constrained('facilities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('booking_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('facility_id');
        });
    }
};
