<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6.23 — Bỏ bảng list_presets sau khi đã migrate vào report_templates (mode=list).
 * Rollback không cần thiết vì data đã ở report_templates.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('list_presets');
    }

    public function down(): void
    {
        // Không auto-restore — tạo lại thủ công nếu cần.
    }
};
