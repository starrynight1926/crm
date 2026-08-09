<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sb_rooms', function (Blueprint $t) {
            // 2026-08-09: mirror phong.duoc_dat_tu_van từ sbooking. Filter phòng khi user chọn bucket "Tư vấn".
            //   Default true để cột hợp lệ trước khi sync đầu tiên chạy.
            $t->boolean('duoc_dat_tu_van')->default(true)->after('kieu_phong');
        });
    }

    public function down(): void
    {
        Schema::table('sb_rooms', function (Blueprint $t) {
            $t->dropColumn('duoc_dat_tu_van');
        });
    }
};
