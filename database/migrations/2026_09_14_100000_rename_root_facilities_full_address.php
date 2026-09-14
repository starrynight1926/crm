<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026-09-14 — Rename 3 root facility (HN/HCM/ĐN) sang tên đầy đủ có địa chỉ,
 * dùng cho PLCP + giấy tờ chính thức. Chỉ đổi khi tên hiện tại đúng bằng
 * tên ngắn ('Hà Nội' / 'HCM' / 'Đà Nẵng') để idempotent + tránh đè bản ghi user đã sửa tay.
 * Không đụng vào các root khác (VD "Cơ sở 1 — Quận 1", "Cơ sở 2 — Quận 7").
 */
return new class extends Migration
{
    private array $map = [
        'Hà Nội'  => 'Cơ sở 1: 59 Ngô Thì Nhậm, Hà Nội',
        'HCM'     => 'Cơ sở 2: 207 Nguyễn Văn Thụ, Hồ Chí Minh',
        'Đà Nẵng' => 'Cơ sở 3: Số 11 - 15 đường Trần Đăng Ninh, phường Hòa Cường, TP. Đà Nẵng',
    ];

    public function up(): void
    {
        foreach ($this->map as $old => $new) {
            DB::table('facilities')
                ->whereNull('parent_id')
                ->where('name', $old)
                ->update(['name' => $new, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach ($this->map as $old => $new) {
            DB::table('facilities')
                ->whereNull('parent_id')
                ->where('name', $new)
                ->update(['name' => $old, 'updated_at' => now()]);
        }
    }
};
