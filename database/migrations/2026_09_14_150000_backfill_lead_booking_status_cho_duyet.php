<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026-09-14 — Backfill leads.booking_status = 'cho_duyet' cho các lead
 * có booking_log mới nhất ở status 'cho_xac_nhan' nhưng lead vẫn 'not_booked'
 * do bug map cũ (STATUS_CHO_XAC_NHAN → BOOKING_NOT_BOOKED).
 *
 * Idempotent: chỉ update lead đang 'not_booked'; không đụng lead đã có trạng thái khác.
 * Không rollback (down = no-op) vì down sẽ mất thông tin đúng.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('booking_logs as bl1')
            ->select('bl1.lead_id')
            ->where('bl1.status', 'cho_xac_nhan')
            ->whereRaw('bl1.id = (SELECT MAX(bl2.id) FROM booking_logs bl2 WHERE bl2.lead_id = bl1.lead_id)')
            ->pluck('lead_id');

        if ($rows->isEmpty()) return;

        DB::table('leads')
            ->whereIn('id', $rows)
            ->where('booking_status', 'not_booked')
            ->update(['booking_status' => 'cho_duyet', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // no-op: giữ trạng thái đúng.
    }
};
