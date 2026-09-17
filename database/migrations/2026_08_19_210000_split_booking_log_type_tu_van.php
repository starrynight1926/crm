<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026-08-19 — Phase B: backfill booking_logs.type
 *   'tham_kham' (gộp cũ) → 'kham_ls' hoặc 'tu_van' dựa vào SbService.thuoc_nhom
 *   của sb_dich_vu_id. Booking không map DV → default 'kham_ls'.
 *
 * booking_logs.type là varchar(20), không cần alter schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Bước 1: nhánh có dich_vu.thuoc_nhom = 'tu_van' → 'tu_van'.
        $tuVan = DB::update("
            UPDATE booking_logs bl
            JOIN sb_services s ON s.sbooking_id = bl.sb_dich_vu_id
            SET bl.type = 'tu_van'
            WHERE bl.type = 'tham_kham' AND s.thuoc_nhom = 'tu_van'
        ");

        // Bước 2: còn lại 'tham_kham' → 'kham_ls'.
        $khamLs = DB::update("
            UPDATE booking_logs
            SET type = 'kham_ls'
            WHERE type = 'tham_kham'
        ");

        if (app()->runningInConsole()) {
            echo "  → backfill booking_logs.type: tu_van={$tuVan}, kham_ls={$khamLs}\n";
        }
    }

    public function down(): void
    {
        DB::update("UPDATE booking_logs SET type = 'tham_kham' WHERE type IN ('kham_ls', 'tu_van')");
    }
};
