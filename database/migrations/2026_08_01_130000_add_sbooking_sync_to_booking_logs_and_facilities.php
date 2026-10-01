<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase C1.b (2026-08-01) — gộp form booking CRM + push sang sbooking.
 *
 * 1. booking_logs: thêm sbooking_booking_id + sync_status + sync_error.
 * 2. facilities: thêm sbooking_co_so_id (map tay 3 cơ sở HN/HCM/DN, chờ Phase C2 auto sync).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('sbooking_booking_id')->nullable()->after('note')
                ->comment('id booking bên sbooking sau khi push thành công');
            $table->string('sync_status', 20)->nullable()->after('sbooking_booking_id')
                ->comment('pending | synced | failed | null (không push)');
            $table->text('sync_error')->nullable()->after('sync_status');
            $table->timestamp('synced_at')->nullable()->after('sync_error');

            $table->index('sbooking_booking_id');
            $table->index('sync_status');
        });

        Schema::table('facilities', function (Blueprint $table) {
            $table->unsignedBigInteger('sbooking_co_so_id')->nullable()->after('booking_co_so_slug')
                ->comment('id cơ sở bên sbooking (map tay tạm, C2 sẽ auto sync)');
            $table->index('sbooking_co_so_id');
        });
    }

    public function down(): void
    {
        Schema::table('booking_logs', function (Blueprint $table) {
            $table->dropIndex(['sbooking_booking_id']);
            $table->dropIndex(['sync_status']);
            $table->dropColumn(['sbooking_booking_id', 'sync_status', 'sync_error', 'synced_at']);
        });
        Schema::table('facilities', function (Blueprint $table) {
            $table->dropIndex(['sbooking_co_so_id']);
            $table->dropColumn('sbooking_co_so_id');
        });
    }
};
