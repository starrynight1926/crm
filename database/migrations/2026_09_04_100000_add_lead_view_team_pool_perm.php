<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 2026-09-04 — Thêm perm `lead.view_team_pool`.
     * Tách gate hiển thị kho team (Lead có `pool_unit_id != null`) khỏi kho công ty.
     *
     * Bối cảnh bug: Lead::scopeVisibleTo trước đây có 2 nhánh với gate không đồng nhất:
     *   - Kho công ty (org_unit_id=null + pool_level=common): gate bằng perm `lead.view_pool`.
     *   - Kho team (pool_unit_id != null): KHÔNG gate — mọi member org của cây pool đó đều thấy.
     * → Sale HC không có `view_pool` vẫn thấy lead BOD trong kho team mình được map tới.
     *
     * Fix: thêm perm riêng `lead.view_team_pool`. Grant cho ĐÚNG role đang có `lead.view_pool`
     * (giữ nguyên hành vi cho CM sale / Manager / Admin cơ sở …), Sale HC không được → cắt lộ.
     */
    public function up(): void
    {
        $pos = (int) DB::table('permissions')->max('position') + 1;
        $permId = DB::table('permissions')->where('key', 'lead.view_team_pool')->value('id');
        if (! $permId) {
            $permId = DB::table('permissions')->insertGetId([
                'key' => 'lead.view_team_pool',
                'label' => 'Xem kho team/pool (lead nằm trong pool_unit — chờ CM sale chia xuống cá nhân)',
                'group' => 'distribution',
                'position' => $pos,
            ]);
        }

        // Grant cho mọi role đang có `lead.view_pool` — giữ nguyên hành vi hiện tại.
        // Role mới thêm sau (nếu có) sẽ tuân theo RolePermissionSyncSeeder.
        $viewPoolPermId = DB::table('permissions')->where('key', 'lead.view_pool')->value('id');
        if ($viewPoolPermId) {
            $roleIds = DB::table('permission_role')->where('permission_id', $viewPoolPermId)->pluck('role_id')->all();
            foreach ($roleIds as $rid) {
                $exists = DB::table('permission_role')
                    ->where('role_id', $rid)->where('permission_id', $permId)->exists();
                if (! $exists) {
                    DB::table('permission_role')->insert([
                        'role_id' => $rid, 'permission_id' => $permId,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $permId = DB::table('permissions')->where('key', 'lead.view_team_pool')->value('id');
        if ($permId) {
            DB::table('permission_role')->where('permission_id', $permId)->delete();
            DB::table('permissions')->where('id', $permId)->delete();
        }
    }
};
