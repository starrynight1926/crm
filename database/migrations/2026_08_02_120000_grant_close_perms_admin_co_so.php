<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase C1.b rev11 (2026-08-02) — cấp thêm `phase.close.distribute` + `phase.close.call`
 * cho role Admin cơ sở (đã cập nhật 140000 KEEP, đây là migration bổ sung cho DB đã chạy trước rev11).
 */
return new class extends Migration
{
    public function up(): void
    {
        $roleId = DB::table('roles')->where('name', 'Admin cơ sở')->value('id');
        if (! $roleId) return;
        foreach (['phase.close.distribute', 'phase.close.call'] as $key) {
            $permId = DB::table('permissions')->where('key', $key)->value('id');
            if ($permId) {
                DB::table('permission_role')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permId],
                    ['role_id' => $roleId, 'permission_id' => $permId]
                );
            }
        }
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('name', 'Admin cơ sở')->value('id');
        if (! $roleId) return;
        foreach (['phase.close.distribute', 'phase.close.call'] as $key) {
            $permId = DB::table('permissions')->where('key', $key)->value('id');
            if ($permId) {
                DB::table('permission_role')->where('role_id', $roleId)->where('permission_id', $permId)->delete();
            }
        }
    }
};
