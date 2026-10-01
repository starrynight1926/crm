<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['ad_source']);
            $table->dropColumn('ad_source');
        });

        Schema::table('stats_daily', function (Blueprint $table) {
            $table->dropUnique('stats_daily_dims_unique');
            $table->dropColumn('ad_source');
            $table->unique(['date', 'org_unit_id', 'user_id', 'camp'], 'stats_daily_dims_unique');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('ad_source')->nullable()->after('link');
            $table->index('ad_source');
        });

        Schema::table('stats_daily', function (Blueprint $table) {
            $table->dropUnique('stats_daily_dims_unique');
            $table->string('ad_source')->nullable();
            $table->unique(['date', 'org_unit_id', 'user_id', 'camp', 'ad_source'], 'stats_daily_dims_unique');
        });
    }
};
