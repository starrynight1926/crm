<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6.20 — Tách quyền booking (read/write/book_action) và quyền distribute theo cấp pool
 * (CM cơ sở → team / CM team → sale).
 *
 * - Thêm 4 permission key mới.
 * - Team booking: bỏ lead.update_booking (chuyển sang readonly), thêm lead.read_booking + lead.book_action.
 * - CM booking / CM sale / Team Leader / Manager / DM HCM / Admin: attach các perm mới tương ứng.
 * - KHÔNG detach perm cũ ở role admin-like (tránh mất quyền ngoài ý muốn).
 */
return new class extends Migration {
    public function up(): void
    {
        $newPerms = [
            ['key' => 'lead.read_booking',       'group' => 'lead',         'label' => 'Vào màn Cập nhật ở chế độ readonly khi phase Booking (Team booking xem info + bấm Đặt booking)'],
            ['key' => 'lead.book_action',        'group' => 'lead',         'label' => 'Bấm nút "Đặt booking" (chuyển sang lara-sbooking)'],
            ['key' => 'lead.distribute_to_team', 'group' => 'distribution', 'label' => 'CM cơ sở: chia lead từ kho công ty/cơ sở xuống kho team'],
            ['key' => 'lead.distribute_to_sale', 'group' => 'distribution', 'label' => 'CM team: chia lead từ kho team xuống sale (owner)'],
        ];

        $maxPos = (int) (DB::table('permissions')->max('position') ?? 0);
        foreach ($newPerms as $p) {
            Permission::updateOrCreate(
                ['key' => $p['key']],
                ['label' => $p['label'], 'group' => $p['group'], 'position' => ++$maxPos]
            );
        }

        $roleAttach = [
            'Team booking' => ['lead.read_booking', 'lead.book_action'],
            'CM booking'   => ['lead.read_booking', 'lead.book_action', 'lead.distribute_to_sale'],
            'CM sale'      => ['lead.distribute_to_sale'],
            'Team Leader'  => ['lead.read_booking', 'lead.book_action', 'lead.distribute_to_sale'],
            'Manager'      => ['lead.read_booking', 'lead.book_action', 'lead.distribute_to_team', 'lead.distribute_to_sale'],
            'DM HCM'       => ['lead.read_booking', 'lead.book_action', 'lead.distribute_to_team', 'lead.distribute_to_sale'],
        ];

        foreach ($roleAttach as $roleName => $keys) {
            $role = Role::where('name', $roleName)->first();
            if (! $role) continue;
            $ids = Permission::whereIn('key', $keys)->pluck('id');
            $role->permissions()->syncWithoutDetaching($ids);
        }

        // Team booking chuyển sang readonly → gỡ lead.update_booking khỏi role này.
        $tb = Role::where('name', 'Team booking')->first();
        if ($tb) {
            $updateBookingId = Permission::where('key', 'lead.update_booking')->value('id');
            if ($updateBookingId) {
                $tb->permissions()->detach($updateBookingId);
            }
        }

        // Admin gán mọi perm trừ lead.consult (đồng bộ với OrgAndRoleSeeder).
        $admin = Role::where('name', 'Admin')->first();
        if ($admin) {
            $allExceptConsult = Permission::where('key', '!=', 'lead.consult')->pluck('id');
            $admin->permissions()->syncWithoutDetaching($allExceptConsult);
        }
    }

    public function down(): void
    {
        $keys = ['lead.read_booking', 'lead.book_action', 'lead.distribute_to_team', 'lead.distribute_to_sale'];
        $ids = Permission::whereIn('key', $keys)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        Permission::whereIn('key', $keys)->delete();

        // Trả lead.update_booking về cho Team booking (rollback).
        $tb = Role::where('name', 'Team booking')->first();
        $updateBookingId = Permission::where('key', 'lead.update_booking')->value('id');
        if ($tb && $updateBookingId) {
            $tb->permissions()->syncWithoutDetaching([$updateBookingId]);
        }
    }
};
