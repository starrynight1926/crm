<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Tạo 6 permission mới (idempotent)
        $perms = [
            'phase.close.new'         => ['label' => 'Chốt phase 1 — Thêm mới khách hàng', 'group' => 'customer_flow'],
            'phase.close.distribute'  => ['label' => 'Chốt phase 2 — Chia số',              'group' => 'customer_flow'],
            'phase.close.call'        => ['label' => 'Chốt phase 3 — Gọi điện',             'group' => 'customer_flow'],
            'phase.close.booking'     => ['label' => 'Chốt phase 4 — Booking thăm khám',    'group' => 'customer_flow'],
            'phase.close.checkin'     => ['label' => 'Chốt phase 5 — Check-in',             'group' => 'customer_flow'],
            'phase.rollback'          => ['label' => 'Lùi phase (Admin vận hành only)',     'group' => 'customer_flow'],
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

        // 2. Gán perm theo role (idempotent, syncWithoutDetaching để không mất perm khác)
        //
        // Mapping (design §5):
        //   Admin (holder ops.manage) → cả 6 perm
        //   Manager / Team Leader / DM HCM → 5 perm close (không rollback)
        //   Sale → close.new, close.call, close.booking
        //   CM booking, CM sale (nếu tồn tại) → close.distribute, close.booking

        $allKeys = array_keys($created);
        $rolePerms = [
            'Admin'              => $allKeys, // full 6
            'DM HCM'             => ['phase.close.new', 'phase.close.distribute', 'phase.close.call', 'phase.close.booking', 'phase.close.checkin'],
            'Manager'            => ['phase.close.new', 'phase.close.distribute', 'phase.close.call', 'phase.close.booking', 'phase.close.checkin'],
            'Team Leader'        => ['phase.close.new', 'phase.close.distribute', 'phase.close.call', 'phase.close.booking'],
            'Sale'               => ['phase.close.new', 'phase.close.call', 'phase.close.booking'],
            'CM sale'            => ['phase.close.distribute', 'phase.close.booking'],
            'CM booking'         => ['phase.close.distribute', 'phase.close.call'],
            'Team sale'          => ['phase.close.new', 'phase.close.call', 'phase.close.booking'],
            'Team booking'       => ['phase.close.call'],
        ];

        foreach ($rolePerms as $roleName => $permKeys) {
            $role = Role::where('name', $roleName)->first();
            if (! $role) continue;
            $permIds = array_values(array_filter(array_map(
                fn($k) => $created[$k] ?? null,
                $permKeys
            )));
            if ($permIds) {
                $role->permissions()->syncWithoutDetaching($permIds);
            }
        }
    }

    public function down(): void
    {
        $keys = [
            'phase.close.new', 'phase.close.distribute', 'phase.close.call',
            'phase.close.booking', 'phase.close.checkin', 'phase.rollback',
        ];
        $permIds = DB::table('permissions')->whereIn('key', $keys)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permIds)->delete();
        DB::table('permissions')->whereIn('id', $permIds)->delete();
    }
};
