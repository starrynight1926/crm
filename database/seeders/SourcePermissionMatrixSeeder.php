<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 2026-08-17 — Nguồn sự thật DUY NHẤT cho quyền `source.up.*` (dropdown nhóm nguồn).
 *
 * Mỗi lần chạy sẽ SYNC bảng `permission_role`: thêm những gì thiếu, XOÁ những gì thừa.
 * Không dùng thêm migration lẻ tẻ để "vá" quyền. Muốn thay đổi phân quyền → sửa mảng
 * MATRIX trong file này rồi chạy: `php artisan db:seed --class=SourcePermissionMatrixSeeder`
 *
 * Cơ sở matrix: scope.md + bảng "Ai được thêm mới lead" (Phase 1) do CM Sales duyệt.
 *   - MKT       → Trực Page (Admin/DM HCM để mgmt flex).
 *   - MKT_BR    → Tư vấn viên = mọi Sale role + CM sale + Manager + DM HCM.
 *   - SA        → như MKT_BR (bucket-gate lọc A/B/C/OFF khi runtime).
 *   - BA        → Tele (Team Tele + CM Tele) + Sale roles (Sale bucket MKT flex qua bucket-gate).
 *   - BDM/BOD   → CM cơ sở (CM sale + Admin cơ sở + DM HCM).
 *   - WI / HL   → Admin cơ sở + Admin.
 *
 * Admin (role "Admin") không cần grant riêng — có `lead.source_all` bypass toàn bộ.
 * Nhưng grant luôn cho tường minh (an toàn nếu ai đó bỏ nhầm perm bypass).
 */
class SourcePermissionMatrixSeeder extends Seeder
{
    /**
     * @var array<string, list<string>> perm_key => role_names
     */
    private const MATRIX = [
        'source.up.mkt' => [
            'Admin', 'Trực Page', 'DM HCM',
        ],
        'source.up.mkt_br' => [
            'Admin', 'DM HCM', 'Manager', 'CM sale',
            'Sale', 'Team Leader', 'Team sale', 'Team sale ĐN',
        ],
        'source.up.sa' => [
            'Admin', 'DM HCM', 'Manager', 'CM sale',
            'Sale', 'Team Leader', 'Team sale', 'Team sale ĐN',
        ],
        'source.up.ba' => [
            'Admin', 'Team Tele', 'CM Tele',
            'Sale', 'Team Leader', 'Team sale', 'Team sale ĐN',
        ],
        'source.up.bdm' => [
            'Admin', 'Admin cơ sở', 'DM HCM', 'CM sale',
        ],
        'source.up.bod' => [
            'Admin', 'Admin cơ sở', 'DM HCM', 'CM sale',
        ],
        'source.up.wi' => [
            'Admin', 'Admin cơ sở',
        ],
        'source.up.hl' => [
            'Admin', 'Admin cơ sở',
        ],
    ];

    public function run(): void
    {
        $permIds = DB::table('permissions')
            ->whereIn('key', array_keys(self::MATRIX))
            ->pluck('id', 'key');

        $roleIds = DB::table('roles')->pluck('id', 'name');

        $missingPerms = array_diff(array_keys(self::MATRIX), $permIds->keys()->all());
        if ($missingPerms) {
            $this->command?->warn('Permission chưa tồn tại (bỏ qua): ' . implode(', ', $missingPerms));
        }

        $totalAdd = 0;
        $totalDel = 0;
        foreach (self::MATRIX as $permKey => $roles) {
            if (! isset($permIds[$permKey])) {
                continue;
            }
            $permId = $permIds[$permKey];

            $wantRoleIds = collect($roles)
                ->map(fn ($n) => $roleIds[$n] ?? null)
                ->filter()
                ->values()
                ->all();

            $missingRoles = array_diff($roles, array_keys(array_intersect_key($roleIds->all(), array_flip($roles))));
            if ($missingRoles) {
                $this->command?->warn("[{$permKey}] role chưa tồn tại (bỏ qua): " . implode(', ', $missingRoles));
            }

            $currentRoleIds = DB::table('permission_role')
                ->where('permission_id', $permId)
                ->pluck('role_id')
                ->all();

            $toAdd = array_diff($wantRoleIds, $currentRoleIds);
            $toDel = array_diff($currentRoleIds, $wantRoleIds);

            foreach ($toAdd as $rid) {
                DB::table('permission_role')->insert(['permission_id' => $permId, 'role_id' => $rid]);
            }
            if ($toDel) {
                DB::table('permission_role')
                    ->where('permission_id', $permId)
                    ->whereIn('role_id', $toDel)
                    ->delete();
            }
            $totalAdd += count($toAdd);
            $totalDel += count($toDel);
        }

        $this->command?->info("SourcePermissionMatrixSeeder: sync xong. Thêm {$totalAdd}, xoá {$totalDel} bản ghi permission_role.");
    }
}
