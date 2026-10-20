<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026-08-09 — Rule bucket-gated SA/BA (xem Lead::todayBucketSourceOverride).
 *
 * Sale trong bucket MKT hôm nay được up BA. Base perm `source.up.tele` phải có
 * ở các role Sale, nếu không dropdown nguồn không mở BA khi bucket = MKT.
 *
 * Attach `source.up.tele` cho: Sale, Team sale, Team sale ĐN, Team Leader.
 */
return new class extends Migration
{
    private const TARGET_ROLES = ['Sale', 'Team sale', 'Team sale ĐN', 'Team Leader'];

    public function up(): void
    {
        $permId = DB::table('permissions')->where('key', 'source.up.tele')->value('id');
        if (! $permId) {
            return;
        }

        $roleIds = DB::table('roles')->whereIn('name', self::TARGET_ROLES)->pluck('id');
        foreach ($roleIds as $roleId) {
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
    }

    public function down(): void
    {
        $permId = DB::table('permissions')->where('key', 'source.up.tele')->value('id');
        if (! $permId) {
            return;
        }
        $roleIds = DB::table('roles')->whereIn('name', self::TARGET_ROLES)->pluck('id');
        DB::table('permission_role')
            ->whereIn('role_id', $roleIds)
            ->where('permission_id', $permId)
            ->delete();
    }
};
