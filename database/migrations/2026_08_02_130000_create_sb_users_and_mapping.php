<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase C1.e (2026-08-02) — mirror sbooking.users + map users.sbooking_user_id.
 * Dùng để resolve CV#1 (scrm) → sale_id (sbooking) khi push edit booking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sb_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sbooking_id')->unique()->comment('id bên sbooking.users');
            $table->string('ten');
            $table->string('chuc_danh', 100)->nullable();
            $table->string('username', 80)->nullable();
            $table->string('email')->nullable()->index();
            $table->unsignedBigInteger('sbooking_co_so_id')->nullable()->index();
            $table->unsignedBigInteger('sbooking_phong_ban_id')->nullable();
            $table->unsignedBigInteger('sbooking_vai_tro_id')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('sbooking_user_id')->nullable()->unique()->after('api_token')
                ->comment('sbooking.users.id — map để push CV#1 làm sale_id khi update booking');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('sbooking_user_id');
        });
        Schema::dropIfExists('sb_users');
    }
};
