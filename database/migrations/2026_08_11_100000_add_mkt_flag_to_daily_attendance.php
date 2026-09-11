<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_attendance', function (Blueprint $table) {
            $table->boolean('is_mkt')->default(false)->after('list_bucket');
        });

        // Migrate existing MKT rows: set is_mkt=true, clear list_bucket
        DB::table('daily_attendance')
            ->where('list_bucket', 'MKT')
            ->update(['is_mkt' => true, 'list_bucket' => null]);
    }

    public function down(): void
    {
        // Restore MKT bucket for rows with is_mkt=true and no bucket
        DB::table('daily_attendance')
            ->where('is_mkt', true)
            ->whereNull('list_bucket')
            ->update(['list_bucket' => 'MKT']);

        Schema::table('daily_attendance', function (Blueprint $table) {
            $table->dropColumn('is_mkt');
        });
    }
};
