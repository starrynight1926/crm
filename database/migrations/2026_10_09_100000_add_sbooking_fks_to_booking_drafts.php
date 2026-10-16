<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-10-09 — Chuẩn hoá booking_drafts để check slot/bác sĩ với sbooking:
 *  - sb_phong_id, sb_dich_vu_id, sb_bac_si_id: ID bên sbooking (như booking_logs).
 *  - gio_ket_thuc: tự tính từ sb_services.phut_moi_khach; preflight cần đủ start+end.
 * Giữ cột text cũ để fallback hiển thị cho row cũ chưa map.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_drafts', function (Blueprint $t) {
            $t->unsignedBigInteger('sb_dich_vu_id')->nullable()->after('lieu_phap')
                ->comment('sbooking.dich_vu.id — map từ lieu_phap');
            $t->unsignedBigInteger('sb_phong_id')->nullable()->after('sb_dich_vu_id')
                ->comment('sbooking.phong.id — admin chọn / auto gợi ý');
            $t->unsignedBigInteger('sb_bac_si_id')->nullable()->after('bac_si')
                ->comment('sbooking.bac_si.id — map từ cột bac_si text');
            $t->time('gio_ket_thuc')->nullable()->after('gio')
                ->comment('Tự tính từ gio + phut_moi_khach của dịch vụ');

            $t->index('sb_phong_id');
            $t->index('sb_bac_si_id');
        });
    }

    public function down(): void
    {
        Schema::table('booking_drafts', function (Blueprint $t) {
            $t->dropIndex(['sb_phong_id']);
            $t->dropIndex(['sb_bac_si_id']);
            $t->dropColumn(['sb_dich_vu_id', 'sb_phong_id', 'sb_bac_si_id', 'gio_ket_thuc']);
        });
    }
};
