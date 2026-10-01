<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-08-11 — Kho "Số re-call".
 * Trực Page import xlsx (tên + sdt), match phone với leads có sẵn → lưu id lead + ngày.
 * CM/Team lead/Trực Page bấm "Chia hàng loạt" → pick sale UPS bucket MKT hôm nay → update Lead.owner.
 * Không tạo lead mới, không copy dữ liệu — chỉ lưu reference id + trạng thái chia.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('recall_entries', function (Blueprint $t) {
            $t->id();
            $t->date('batch_date')->index();
            $t->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $t->foreignId('imported_by')->constrained('users')->cascadeOnDelete();
            $t->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('assigned_at')->nullable();
            $t->foreignId('facility_pool_unit_id')->nullable()->constrained('pool_units')->nullOnDelete();
            $t->string('imported_name')->nullable();  // Tên trong file xlsx (có thể khác lead.name)
            $t->string('imported_phone')->nullable(); // Sdt raw trước khi chuẩn hoá
            $t->timestamps();

            $t->index(['batch_date', 'assigned_to_user_id']);
            $t->unique(['batch_date', 'lead_id'], 'uniq_recall_batch_lead'); // 1 lead / 1 ngày.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recall_entries');
    }
};
