<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B1a (2026-08-14) — Lịch sử ownership của lead.
 *
 * Mục đích: 1 lead có thể qua tay nhiều sale (do chia, thu hồi, chuyển tay).
 * Sale cũ vẫn được phép ghi thêm cuộc gọi / tạo booking sau khi mất quyền
 * ownership hiện tại (theo yêu cầu spec 2026-08-14).
 *
 * Không backfill lead cũ — chỉ ghi từ khi migration này chạy trở đi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_ownership_history', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->timestamp('assigned_at');
            $t->timestamp('released_at')->nullable();
            $t->string('released_reason', 40)->nullable();
            $t->timestamps();

            $t->index(['lead_id', 'released_at']);
            $t->index(['user_id', 'released_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_ownership_history');
    }
};
