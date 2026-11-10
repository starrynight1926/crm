<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mirror bên SCRM cho bảng dich_vu_phong bên sbooking (Đợt C.1, 2026-08-25).
// Sync 1 chiều qua command `sb:sync-dich-vu-phong`. sbooking_id không dùng vì
// pivot không có id nghiệp vụ — key là (dich_vu_id, phong_id).
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('sb_dich_vu_phong')) {
            Schema::create('sb_dich_vu_phong', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sbooking_dich_vu_id');  // = sb_services.sbooking_id
                $t->unsignedBigInteger('sbooking_phong_id');    // = sb_rooms.sbooking_id
                $t->timestamp('synced_at')->nullable();
                $t->timestamps();
                $t->unique(['sbooking_dich_vu_id', 'sbooking_phong_id'], 'sb_dvp_unique');
                $t->index('sbooking_dich_vu_id', 'sb_dvp_dv_idx');
                $t->index('sbooking_phong_id', 'sb_dvp_phong_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sb_dich_vu_phong');
    }
};
