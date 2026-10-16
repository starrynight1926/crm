<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_logs', function (Blueprint $t) {
            $t->timestamp('first_tiep_don_at')->nullable()->after('sbooking_booking_ma');
        });
    }

    public function down(): void
    {
        Schema::table('booking_logs', function (Blueprint $t) {
            $t->dropColumn('first_tiep_don_at');
        });
    }
};
