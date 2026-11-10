<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase C1.f (2026-08-02) — thêm vai_tro_ma vào sb_users để filter theo role
 * (ktv / le_tan / admin / …) mà không phụ thuộc vai_tro_id int.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sb_users', function (Blueprint $table) {
            $table->string('sbooking_vai_tro_ma', 40)->nullable()->index()->after('sbooking_vai_tro_id');
            $table->string('sbooking_vai_tro_ten', 100)->nullable()->after('sbooking_vai_tro_ma');
        });
    }

    public function down(): void
    {
        Schema::table('sb_users', function (Blueprint $table) {
            $table->dropColumn(['sbooking_vai_tro_ma', 'sbooking_vai_tro_ten']);
        });
    }
};
