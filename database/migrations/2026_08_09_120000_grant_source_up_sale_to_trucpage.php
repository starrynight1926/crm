<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026-08-09 — Trực Page up được cả Marketing (MKT) và Marketing BR (MKT_BR).
 * Attach `source.up.sale` cho role Trực Page.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permId = DB::table('permissions')->where('key', 'source.up.sale')->value('id');
        $roleId = DB::table('roles')->where('name', 'Trực Page')->value('id');
        if (! $permId || ! $roleId) {
            return;
        }
        $exists = DB::table('permission_role')
            ->where('role_id', $roleId)
            ->where('permission_id', $permId)
            ->exists();
        if (! $exists) {
            DB::table('permission_role')->insert([
                'role_id' => $roleId,
                'permission_id' => $permId,
            ]);
        }
    }

    public function down(): void
    {
        $permId = DB::table('permissions')->where('key', 'source.up.sale')->value('id');
        $roleId = DB::table('roles')->where('name', 'Trực Page')->value('id');
        if (! $permId || ! $roleId) {
            return;
        }
        DB::table('permission_role')
            ->where('role_id', $roleId)
            ->where('permission_id', $permId)
            ->delete();
    }
};
