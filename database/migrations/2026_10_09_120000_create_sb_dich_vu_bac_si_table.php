<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-10-09 — Mirror pivot dich_vu_bac_si từ sbooking về sb_dich_vu_bac_si.
 * Dùng để liệt kê "bác sĩ thực hiện mỗi dịch vụ" ở quick-sheets.
 * Full replace mỗi lần sync (giống sb_dich_vu_phong). Key: (dich_vu_id, bac_si_id).
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('sb_dich_vu_bac_si')) {
            Schema::create('sb_dich_vu_bac_si', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sbooking_dich_vu_id'); // = sb_services.sbooking_id
                $t->unsignedBigInteger('sbooking_bac_si_id');  // = sb_bac_si.sbooking_id
                $t->timestamp('synced_at')->nullable();
                $t->timestamps();
                $t->unique(['sbooking_dich_vu_id', 'sbooking_bac_si_id'], 'sb_dvbs_unique');
                $t->index('sbooking_dich_vu_id', 'sb_dvbs_dv_idx');
                $t->index('sbooking_bac_si_id', 'sb_dvbs_bs_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sb_dich_vu_bac_si');
    }
};
