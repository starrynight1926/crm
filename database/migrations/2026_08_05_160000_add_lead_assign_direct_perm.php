<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 2026-08-05 — Thêm perm `lead.assign_direct` (Chia lead thẳng).
     * Cho phép CM chọn thẳng 1 nhân sự trong scope để giao lead phase 2 gọi điện,
     * không qua UPS round-robin, không set busy.
     *
     * Tick sẵn cho: CM sale, CM Tele, DM HCM, Manager, Admin.
     * KHÔNG tick cho Admin cơ sở (đúng yêu cầu 2026-08-05).
     */
    public function up(): void
    {
        // Idempotent: bỏ qua nếu perm đã tồn tại.
        $pos = (int) DB::table('permissions')->max('position') + 1;
        $permId = DB::table('permissions')->where('key', 'lead.assign_direct')->value('id');
        if (! $permId) {
            $permId = DB::table('permissions')->insertGetId([
                'key' => 'lead.assign_direct',
                'label' => 'Chia lead thẳng — CM chọn thẳng 1 nhân sự trong scope để giao lead phase 2 (không qua UPS)',
                'group' => 'distribution',
                'position' => $pos,
            ]);
        }

        $roles = ['CM sale', 'CM Tele', 'DM HCM', 'Manager', 'Admin'];
        foreach ($roles as $roleName) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');
            if (! $roleId) continue;
            $exists = DB::table('permission_role')
                ->where('role_id', $roleId)->where('permission_id', $permId)->exists();
            if (! $exists) {
                DB::table('permission_role')->insert([
                    'role_id' => $roleId, 'permission_id' => $permId,
                ]);
            }
        }
    }

    public function down(): void
    {
        $permId = DB::table('permissions')->where('key', 'lead.assign_direct')->value('id');
        if ($permId) {
            DB::table('permission_role')->where('permission_id', $permId)->delete();
            DB::table('permissions')->where('id', $permId)->delete();
        }
    }
};
