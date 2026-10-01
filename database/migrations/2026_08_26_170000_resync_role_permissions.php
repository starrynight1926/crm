<?php

use Illuminate\Database\Migrations\Migration;

/**
 * 2026-08-26 — Re-sync toàn bộ role↔permission theo MATRIX của RolePermissionSyncSeeder.
 *
 * Lý do: prod dính bug role "Trực Page" thiếu source.up.mkt / phase.close.new / recall.*
 * (seeder đúng nhưng deploy không tự chạy db:seed → matrix cũ vẫn giữ lại).
 * Từ nay mỗi lần `php artisan migrate --force` sẽ re-sync theo file seeder,
 * đảm bảo prod luôn khớp code — idempotent, chạy lại thoải mái.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Đảm bảo bảng permissions đã seed đủ key trong PermissionSeeder trước khi sync.
        (new \Database\Seeders\PermissionSeeder())->run();
        (new \Database\Seeders\RolePermissionSyncSeeder())->run();
    }

    public function down(): void
    {
        // Không rollback được — role/perm là dữ liệu quy chuẩn, không có "trạng thái trước".
    }
};
