<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mirror flag khong_can_phong từ sbooking (Đợt C.3.b, 2026-08-25).
// UI lead-form hide dropdown phòng + skip validation khi DV có flag này.
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('sb_services', 'khong_can_phong')) {
            Schema::table('sb_services', function (Blueprint $t) {
                $t->boolean('khong_can_phong')->default(false)->after('active');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sb_services', 'khong_can_phong')) {
            Schema::table('sb_services', function (Blueprint $t) {
                $t->dropColumn('khong_can_phong');
            });
        }
    }
};
