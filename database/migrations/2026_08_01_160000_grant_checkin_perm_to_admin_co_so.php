<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase C1.b rev8 (2026-08-01) — Admin cơ sở cần phase.close.checkin để:
 *   - Check-in tay khi sbooking offline / khách trực tiếp.
 *   - Bấm "Khởi động lần thăm khám mới" (markReturning) — yêu cầu perm này hoặc phase.rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        $roleId = DB::table('roles')->where('name', 'Admin cơ sở')->value('id');
        $permId = DB::table('permissions')->where('key', 'phase.close.checkin')->value('id');
        if ($roleId && $permId) {
            DB::table('permission_role')->updateOrInsert(
                ['role_id' => $roleId, 'permission_id' => $permId],
                ['role_id' => $roleId, 'permission_id' => $permId]
            );
        }
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('name', 'Admin cơ sở')->value('id');
        $permId = DB::table('permissions')->where('key', 'phase.close.checkin')->value('id');
        if ($roleId && $permId) {
            DB::table('permission_role')->where('role_id', $roleId)->where('permission_id', $permId)->delete();
        }
    }
};
