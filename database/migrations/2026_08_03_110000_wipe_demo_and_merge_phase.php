<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6.23 (2026-08-03) — Wipe demo data + gộp Phase 1 (Tạo mới) + Phase 2 (Chia số) thành 1 phase.
 *
 * User đã confirm: data hiện có chỉ là demo, cho phép clear. Nếu chạy nhầm ở môi trường có data thật
 * sẽ mất TOÀN BỘ leads/booking/log/payment/... Cân nhắc kỹ trước khi run trên staging/prod.
 *
 * KHÔNG reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        $wipeTables = [
            'lead_status_logs', 'lead_phase_closures', 'lead_distribution_logs',
            'lead_treatments', 'lead_upsells', 'lead_custom_values', 'lead_caps',
            'call_logs', 'booking_log_consultants', 'booking_log_comments',
            'booking_late_logs', 'booking_logs', 'contributions', 'payments',
            'customer_service_phases', 'customer_services',
            'ingest_logs', 'import_batches',
            'daily_attendance', 'ups_daily_confirm',
            'leads',
        ];

        // Sqlite (test env) không hỗ trợ SET FOREIGN_KEY_CHECKS — bọc theo driver.
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        }

        foreach ($wipeTables as $t) {
            if (Schema::hasTable($t)) {
                DB::table($t)->truncate();
            }
        }

        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        // Xóa permission `phase.close.distribute` (đã gộp vào phase.close.new).
        $perm = DB::table('permissions')->where('key', 'phase.close.distribute')->first();
        if ($perm) {
            DB::table('permission_role')->where('permission_id', $perm->id)->delete();
            DB::table('permissions')->where('id', $perm->id)->delete();
        }
    }

    public function down(): void
    {
        // Data wipe không đảo ngược được.
    }
};
