<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_logs', function (Blueprint $table) {
            $table->json('past_consultant_user_ids')->nullable()->after('sync_error');
        });
    }

    public function down(): void
    {
        Schema::table('booking_logs', function (Blueprint $table) {
            $table->dropColumn('past_consultant_user_ids');
        });
    }
};
