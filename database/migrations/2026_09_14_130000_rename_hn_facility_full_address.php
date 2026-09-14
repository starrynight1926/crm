<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026-09-14 — Cập nhật tên root facility Hà Nội sang địa chỉ đầy đủ có phường.
 * Idempotent: chỉ đổi khi tên đang bằng đúng bản cũ, không đè record đã sửa tay.
 */
return new class extends Migration
{
    private string $new = 'Cơ sở 1: số 59 Ngô Thì Nhậm, P. Hai Bà Trưng, TP. Hà Nội';

    private array $olds = [
        'Cơ sở 1: 59 Ngô Thì Nhậm, Hà Nội',
        'Hà Nội',
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
