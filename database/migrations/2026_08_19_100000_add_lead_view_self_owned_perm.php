<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 2026-08-19 — Thêm perm `lead.view_self_owned`.
     * Gate visibility cho lead nguồn self-owned (MKT_BR / SA / BA / HL) — chỉ creator
     * (imported_by), owner/receiver, past-handler và role trong chuỗi quản lý team
     * (Team Leader / Manager / CM / DM / …) được thấy. Admin cơ sở KHÔNG có perm này
     * → không thấy lead self-owned của các team dưới cơ sở.
     *
     * Xem Lead::scopeVisibleTo — nhánh SELF_OWNED bổ sung sau khi perm này tồn tại.
     */
    public function up(): void
    {
        $pos = (int) DB::table('permissions')->max('position') + 1;
        $permId = DB::table('permissions')->where('key', 'lead.view_self_owned')->value('id');
        if (! $permId) {
            $permId = DB::table('permissions')->insertGetId([
                'key' => 'lead.view_self_owned',
                'label' => 'Xem lead nguồn tự tạo (MKT_BR / SA / BA / HL) — chỉ chuỗi quản lý team',
                'group' => 'lead',
                'position' => $pos,
            ]);
        }

        // Grant cho role thuộc chuỗi quản lý team + Sale (thấy lead của chính mình + past).
        // Admin cơ sở KHÔNG có — đây là chốt chặn chính.
        $roles = [
            'Sale', 'Team Leader', 'Manager', 'Admin',
            'CM sale', 'CM Tele', 'DM HCM',
        ];
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
        $permId = DB::table('permissions')->where('key', 'lead.view_self_owned')->value('id');
        if ($permId) {
            DB::table('permission_role')->where('permission_id', $permId)->delete();
            DB::table('permissions')->where('id', $permId)->delete();
        }
    }
};
