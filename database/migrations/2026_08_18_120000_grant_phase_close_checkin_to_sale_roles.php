<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026-08-18 — Sale tiếp đón (Sale/Team sale/Team sale ĐN/Team Leader) phải có
 * phase.close.checkin để endpoint /api/booking-event/checkin-done auto-close Phase 4.
 *
 * Trước: chỉ Admin cơ sở/Admin/Manager/DM HCM có → sbooking push về, closePhase throw.
 */
return new class extends Migration
{
    private const TARGET_ROLES = ['Sale', 'Team Leader', 'Team sale', 'Team sale ĐN'];

    public function up(): void
    {
        $permId = DB::table('permissions')->where('key', 'phase.close.checkin')->value('id');
        if (! $permId) return;

        $roleIds = DB::table('roles')->whereIn('name', self::TARGET_ROLES)->pluck('id');
        foreach ($roleIds as $roleId) {
            $exists = DB::table('permission_role')
                ->where('role_id', $roleId)->where('permission_id', $permId)->exists();
            if (! $exists) {
                DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $permId]);
            }
        }
    }

    public function down(): void
    {
        $permId = DB::table('permissions')->where('key', 'phase.close.checkin')->value('id');
        if (! $permId) return;
        $roleIds = DB::table('roles')->whereIn('name', self::TARGET_ROLES)->pluck('id');
        DB::table('permission_role')
            ->whereIn('role_id', $roleIds)->where('permission_id', $permId)->delete();
    }
};
