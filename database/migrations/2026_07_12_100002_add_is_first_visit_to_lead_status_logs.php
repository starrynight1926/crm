<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_status_logs', function (Blueprint $table) {
            $table->boolean('is_first_visit')->default(false)->after('is_return');
        });
    }

    public function down(): void
    {
        Schema::table('lead_status_logs', function (Blueprint $table) {
            $table->dropColumn('is_first_visit');
        });
    }
};
