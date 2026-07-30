<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6.21f (2026-07-30) — 3 team chính + phân quyền up nguồn.
 *
 * 1. Rename roles:
 *    - "Team booking"    → "Team Tele"
 *    - "CM booking"      → "CM Tele"
 *    - "Team nhập lead"  → "Trực Page"
 * 2. Tạo 4 perm mới: source.up.{trucpage,sale,tele,admin}
 * 3. Gán perm theo bảng:
 *      MKT           → Trực Page (+ Admin)
 *      MKT_BR, SA    → Sale, Team sale, CM sale, TL, Manager, DM HCM, Admin
 *      BA            → Team Tele, CM Tele (+ Admin)
 *      BDM, BOD, WI  → Admin (+ DM HCM)
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Rename roles (idempotent — skip nếu tên mới đã tồn tại)
        $renames = [
            'Team booking'   => 'Team Tele',
            'CM booking'     => 'CM Tele',
            'Team nhập lead' => 'Trực Page',
        ];
        foreach ($renames as $old => $new) {
            $exists = DB::table('roles')->where('name', $new)->exists();
            if ($exists) continue;
            DB::table('roles')->where('name', $old)->update(['name' => $new]);
        }

        // 2. Tạo 4 permissions
        $perms = [
            'source.up.trucpage' => ['label' => 'Up lead nguồn MKT (Trực Page)',                 'group' => 'source_up'],
            'source.up.sale'     => ['label' => 'Up lead nguồn MKT BR / SA (Sale tự nhận)',      'group' => 'source_up'],
            'source.up.tele'     => ['label' => 'Up lead nguồn BA (Tele tự nhận)',               'group' => 'source_up'],
            'source.up.admin'    => ['label' => 'Up lead nguồn BDM / BOD / Walk-in (Admin)',     'group' => 'source_up'],
        ];
        $created = [];
        $position = (int) (Permission::max('position') ?? 0) + 1;
        foreach ($perms as $key => $meta) {
            $p = Permission::updateOrCreate(
                ['key' => $key],
                ['label' => $meta['label'], 'group' => $meta['group'], 'position' => $position++]
            );
            $created[$key] = $p->id;
        }

        // 3. Gán perm cho role
        $rolePerms = [
            'Admin'         => ['source.up.trucpage', 'source.up.sale', 'source.up.tele', 'source.up.admin'],
            'DM HCM'        => ['source.up.trucpage', 'source.up.sale', 'source.up.tele', 'source.up.admin'],
            'Manager'       => ['source.up.sale'],
            'Team Leader'   => ['source.up.sale'],
            'Sale'          => ['source.up.sale'],
            'CM sale'       => ['source.up.sale'],
            'Team sale'     => ['source.up.sale'],
            'Team sale ĐN'  => ['source.up.sale'],
            'Trực Page'     => ['source.up.trucpage'],
            'Team Tele'     => ['source.up.tele'],
            'CM Tele'       => ['source.up.tele'],
        ];
        foreach ($rolePerms as $roleName => $permKeys) {
            $role = Role::where('name', $roleName)->first();
            if (! $role) continue;
            $permIds = array_values(array_filter(array_map(fn($k) => $created[$k] ?? null, $permKeys)));
            if ($permIds) {
                $role->permissions()->syncWithoutDetaching($permIds);
            }
        }
    }

    public function down(): void
    {
        // Rollback rename
        $renames = [
            'Team Tele'  => 'Team booking',
            'CM Tele'    => 'CM booking',
            'Trực Page'  => 'Team nhập lead',
        ];
        foreach ($renames as $new => $old) {
            $exists = DB::table('roles')->where('name', $old)->exists();
            if ($exists) continue;
            DB::table('roles')->where('name', $new)->update(['name' => $old]);
        }
        // Xóa perm
        $keys = ['source.up.trucpage', 'source.up.sale', 'source.up.tele', 'source.up.admin'];
        $permIds = DB::table('permissions')->whereIn('key', $keys)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permIds)->delete();
        DB::table('permissions')->whereIn('id', $permIds)->delete();
    }
};
