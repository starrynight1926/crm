<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B2 (2026-08-14) — Liên hệ gần nhất (multi-media timeline).
 *
 * Mỗi lượt upload = 1 snapshot gồm ảnh (nhiều file) + note của sale.
 * Cần trace được lead qua tay nhiều sale (thu hồi rồi giao lại) — mỗi snapshot
 * gắn user_id + created_at để hiển thị timeline theo sale + thời gian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_contact_snapshots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users');
            $t->text('note')->nullable();
            $t->timestamps();
            $t->index(['lead_id', 'created_at']);
        });

        Schema::create('lead_contact_snapshot_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('snapshot_id')->constrained('lead_contact_snapshots')->cascadeOnDelete();
            $t->string('path', 500);
            $t->string('mime', 100)->nullable();
            $t->unsignedInteger('size_bytes')->nullable();
            $t->timestamps();
            $t->index('snapshot_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_contact_snapshot_files');
        Schema::dropIfExists('lead_contact_snapshots');
    }
};
