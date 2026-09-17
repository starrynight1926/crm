<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * 2026-08-19 — Fix mapping scrm.users.sbooking_user_id cho team Đà Nẵng.
 *
 * Sự cố: admin sbooking bấm Duyệt booking → 422 "Sale không thuộc cơ sở
 *   của booking này". Root cause:
 *   - Kim Phấn (SCRM #9) sbooking_user_id = 19 (là user dupe cũ ở HN,
 *     đã rename dn.cms01_legacy bên sbooking). Đúng phải trỏ tới user DN.
 *   - Các user DN còn lại (Bông + 7 sale) — sbooking_user_id NULL, chưa map.
 *
 * Cách tiếp cận (portable, không dùng cross-DB — cross-DB fail nếu DB user
 * không có SELECT trên sbooking schema):
 *   1. Chạy `sb:sync-users` → refresh bảng mirror sb_users qua HTTP API.
 *   2. Cho các DN user cần fix: NULL-out scrm.users.sbooking_user_id để
 *      auto-map trong sb:sync-users điền lại từ sb_users (match qua
 *      email local-part == sb_users.username).
 *   3. Chạy `sb:sync-users` lần nữa để auto-map.
 */
return new class extends Migration
{
    private const DN_USER_EMAILS = [
        'dn.cms01@longevity.com.vn',   // Kim Phấn
        'dn.tl01@longevity.com.vn',    // Bông
        'dn.sale01@longevity.com.vn',  // Ánh Nhung
        'dn.sale02@longevity.com.vn',  // Hoàng Uyên
        'dn.sale03@longevity.com.vn',  // Kim Hiếu
        'dn.sale04@longevity.com.vn',  // Sử Trung Kiên
        'dn.sale05@longevity.com.vn',  // Tường Vy
        'dn.sale06@longevity.com.vn',  // An Hoà
        'dn.sale07@longevity.com.vn',  // Mỹ Hạnh
    ];

    public function up(): void
    {
        // Step 1: refresh sb_users mirror (không fatal nếu API fail).
        try {
            Artisan::call('sb:sync-users');
            if (app()->runningInConsole()) echo "  → sb:sync-users pre-refresh OK\n";
        } catch (\Throwable $e) {
            if (app()->runningInConsole()) echo "  ! sb:sync-users pre-refresh fail: {$e->getMessage()} (tiếp tục)\n";
        }

        // Step 2: clear mapping sai của DN user để auto-map fill lại đúng.
        $cleared = DB::table('users')
            ->whereIn('email', self::DN_USER_EMAILS)
            ->update(['sbooking_user_id' => null, 'updated_at' => now()]);
        if (app()->runningInConsole()) echo "  → cleared sbooking_user_id cho {$cleared} DN user\n";

        // Step 3: chạy lại để auto-map (SyncUsersFromSbooking chỉ map khi NULL).
        try {
            Artisan::call('sb:sync-users');
            $out = Artisan::output();
            if (app()->runningInConsole()) echo "  → sb:sync-users re-map: " . trim($out) . "\n";
        } catch (\Throwable $e) {
            if (app()->runningInConsole()) echo "  ! sb:sync-users re-map fail: {$e->getMessage()}\n";
        }

        // Step 4: dump kết quả.
        if (app()->runningInConsole()) {
            foreach (self::DN_USER_EMAILS as $email) {
                $u = DB::table('users')->where('email', $email)->first(['id', 'name', 'sbooking_user_id']);
                if ($u) echo "    {$u->name} (SCRM #{$u->id}): sbooking_user_id = " . ($u->sbooking_user_id ?? 'NULL') . "\n";
            }
        }
    }

    public function down(): void
    {
        // No-op — không lưu snapshot cũ.
    }
};
