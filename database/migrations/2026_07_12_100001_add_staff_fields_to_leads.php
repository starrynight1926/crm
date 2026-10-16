<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('facility_id')->nullable()->constrained()->nullOnDelete()->after('org_unit_id');
            $table->foreignId('doctor_id')->nullable()->constrained('staff_members')->nullOnDelete()->after('facility_id');
            $table->foreignId('consultant_1_id')->nullable()->constrained('staff_members')->nullOnDelete()->after('doctor_id');
            $table->foreignId('consultant_2_id')->nullable()->constrained('staff_members')->nullOnDelete()->after('consultant_1_id');
            $table->foreignId('consultant_3_id')->nullable()->constrained('staff_members')->nullOnDelete()->after('consultant_2_id');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('consultant_3_id');
            $table->dropConstrainedForeignId('consultant_2_id');
            $table->dropConstrainedForeignId('consultant_1_id');
            $table->dropConstrainedForeignId('doctor_id');
            $table->dropConstrainedForeignId('facility_id');
        });
    }
};
