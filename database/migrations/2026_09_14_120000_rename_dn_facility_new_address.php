<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026-09-14 — Cập nhật tên root facility Đà Nẵng sang địa chỉ mới đầy đủ.
 * Chỉ đổi khi tên đang bằng đúng bản cũ do migration trước set,
 * hoặc bằng 'Đà Nẵng' cũ hơn nữa → idempotent, không đè record đã sửa tay.
 */
return new class extends Migration
{
    private string $new = 'Cơ sở 3: Số 11 - 15 đường Trần Đăng Ninh, phường Hòa Cường, TP. Đà Nẵng';

    private array $olds = [
        'Cơ sở 3: Lô 23 Trần Đăng Ninh, Đà Nẵng',
        'Đà Nẵng',
    ];

    public function up(): void
    {
        DB::table('facilities')
            ->whereNull('parent_id')
            ->whereIn('name', $this->olds)
            ->update(['name' => $this->new, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('facilities')
            ->whereNull('parent_id')
            ->where('name', $this->new)
            ->update(['name' => $this->olds[0], 'updated_at' => now()]);
    }
};
