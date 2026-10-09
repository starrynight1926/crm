<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026-08-04 (Task 2): thu hẹp `lead.book_action` chỉ còn 2 role có quyền tạo booking:
 *   - Admin (Toàn quyền hệ thống — coi như Admin vận hành)
 *   - Admin cơ sở (Admin.HN/HCM/DN, phụ trách phòng BO)
 *
 * 9 role còn lại (DM HCM, Manager, CM sale, CM Tele, Team Leader, Sale, Team sale,
 * Team sale ĐN, Team Tele) mất perm → nút "+ Tạo booking" tự ẩn ở ⚡lead-form. Booking
 * do Admin cơ sở tạo → Sale chỉ ghi chú + xem lịch sử booking mình phụ trách (CV pivot).
 *
 * Source of truth ở `RolePermissionSyncSeeder::MATRIX` — migration này đè state DB
 * hiện tại cho môi trường không seed lại.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permId = DB::table('permissions')->where('key', 'lead.book_action')->value('id');
        if (! $permId) {
            return;
        }
        $keepRoleIds = DB::table('roles')
            ->whereIn('name', ['Admin', 'Admin cơ sở'])
            ->pluck('id')
            ->all();

        DB::table('permission_role')
            ->where('permission_id', $permId)
            ->when(! empty($keepRoleIds), fn ($q) => $q->whereNotIn('role_id', $keepRoleIds))
            ->delete();
    }

    public function down(): void
    {
        // Không revert — không đảo được chính xác list cũ mà không copy full seeder logic.
        // Muốn khôi phục: chạy `php artisan db:seed --class=RolePermissionSyncSeeder`
        // sau khi revert MATRIX ở seeder.
    }
};
