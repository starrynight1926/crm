<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-08-04 — Quy tắc PKD Update.docx:
 *   - Sau 1 ngày không update col 1,2,3 (page, camp, phan_loai) → thu hồi
 *   - Sau 3 ngày không update đủ col 4,5 (ket_qua, sic) → thu hồi
 * Flag recall_by_columns=true bật khi CM/Admin tick "Áp dụng luật thu hồi tự động" ở block chia số.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $t) {
            $t->boolean('recall_by_columns')->default(false)->after('is_permanent_assignment');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $t) {
            $t->dropColumn('recall_by_columns');
        });
    }
};
