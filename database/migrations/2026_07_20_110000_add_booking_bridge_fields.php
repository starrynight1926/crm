<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Facility ↔ cơ sở bên lara-sbooking (slug URL, VD: 59ntn, 207nvt).
        Schema::table('facilities', function (Blueprint $table) {
            $table->string('booking_co_so_slug', 60)->nullable()->after('active');
        });

        // Mã booking mới nhất của lead + thời điểm đặt (để show ở chi tiết & tra ngược).
        Schema::table('leads', function (Blueprint $table) {
            $table->string('booking_ma', 40)->nullable()->after('booking_status');
            $table->timestamp('booked_at')->nullable()->after('booking_ma');
            $table->index('booking_ma');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['booking_ma']);
            $table->dropColumn(['booking_ma', 'booked_at']);
        });
        Schema::table('facilities', function (Blueprint $table) {
            $table->dropColumn('booking_co_so_slug');
        });
    }
};
