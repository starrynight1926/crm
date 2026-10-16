<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026-10-09 — Attach permission `source.up.hl` vào role `CM booking`.
 *
 * Role 'CM booking' KHÔNG được quản lý trong RolePermissionSyncSeeder (seeder không
 * biết full perm set). Dùng syncWithoutDetaching → chỉ ADD perm này, giữ nguyên
 * mọi perm khác CM booking đang có. Idempotent — chạy lại không gãy.
 */
return new class extends Migration
{
    public function up(): void
    {
        $roleId = DB::table('roles')->where('name', 'CM booking')->value('id');
        if (! $roleId) return; // role chưa có → skip, không throw.

        $permId = DB::table('permissions')->where('key', 'source.up.hl')->value('id');
        if (! $permId) return;

        $exists = DB::table('permission_role')
            ->where('role_id', $roleId)
            ->where('permission_id', $permId)
            ->exists();
        if ($exists) return;

        DB::table('permission_role')->insert([
            'role_id' => $roleId,
            'permission_id' => $permId,
        ]);
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('name', 'CM booking')->value('id');
        $permId = DB::table('permissions')->where('key', 'source.up.hl')->value('id');
        if (! $roleId || ! $permId) return;
        DB::table('permission_role')
            ->where('role_id', $roleId)
            ->where('permission_id', $permId)
            ->delete();
    }
};
