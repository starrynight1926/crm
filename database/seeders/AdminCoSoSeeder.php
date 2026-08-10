<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\OrgUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\DefaultPassword;
use Illuminate\Database\Seeder;

/**
 * 3 tài khoản Admin cơ sở (admin.hn / admin.hcm / admin.dn) + role "Admin cơ sở".
 * Idempotent — chạy lại lần 2 không tạo trùng, chỉ cập nhật.
 *
 * Được extract từ migration 2026_07_31_110000_seed_admin_co_so_role_and_users.php để
 * DatabaseSeeder chủ động gọi (không phụ thuộc migration path lệch giữa các env).
 */
class AdminCoSoSeeder extends Seeder
{
    /** Perm khớp với migration cùng logic. Cập nhật ở 1 chỗ. */
    public const PERMS = [
        'lead.view', 'lead.view_phone',
        // Nhập / import / xóa mềm / up 4 nguồn QL Sale
        'lead.create', 'lead.import', 'lead.delete', 'source.up.bdm', 'source.up.bod', 'source.up.wi',
        // Chia số phase 2
        'lead.view_pool', 'lead.distribute', 'lead.distribute_tele', 'lead.distribute_sale',
        'lead.distribute_to_team', 'lead.distribute_to_sale', 'lead.recall',
        // Phase 4 booking
        'lead.book_action', 'lead.update_booking', 'lead.read_booking', 'phase.close.booking',
        // Báo cáo
        'report.view',
        // 2026-08-10 — Admin cơ sở được xem + edit UPS list (đồng bộ với tài khoản duyệt bên booking).
        'ups.view', 'ups.checkin', 'ups.override', 'ups.confirm_daily',
    ];

    /** Tra branch bằng nhiều alias code khả dĩ giữa các env seed. */
    public const ACCOUNTS = [
        ['username' => 'admin.hn',  'sb_username' => 'admin59ntn',  'name' => 'Admin Cơ sở Hà Nội',  'branch_codes' => ['branch-hn', 'hn', 'ha-noi']],
        ['username' => 'admin.hcm', 'sb_username' => 'admin207nvt', 'name' => 'Admin Cơ sở HCM',     'branch_codes' => ['branch-hcm', 'hcm', 'ho-chi-minh']],
        ['username' => 'admin.dn',  'sb_username' => 'adminl23tdn', 'name' => 'Admin Cơ sở Đà Nẵng', 'branch_codes' => ['branch-dn', 'dn', 'da-nang']],
    ];

    public function run(): void
    {
        $role = Role::firstOrCreate(['name' => 'Admin cơ sở']);
        $permIds = Permission::whereIn('key', self::PERMS)->pluck('id')->all();
        $role->permissions()->syncWithoutDetaching($permIds);

        foreach (self::ACCOUNTS as $acc) {
            $branch = OrgUnit::whereIn('code', $acc['branch_codes'])->first();
            if (! $branch) {
                $this->command?->warn("Bỏ qua {$acc['username']}: không tìm thấy OrgUnit code in " . implode('/', $acc['branch_codes']));
                continue;
            }

            $email = $acc['username'] . '@longevity.com.vn';
            $user = User::firstWhere('email', $email) ?? User::firstWhere('name', $acc['name']);
            if (! $user) {
                $user = User::create([
                    'username' => $acc['sb_username'],
                    'email'    => $email,
                    'name'     => $acc['name'],
                    'password' => DefaultPassword::forEmail($email),
                    'status'   => User::STATUS_ACTIVE,
                ]);
            } else {
                $user->update([
                    'username' => $acc['sb_username'],
                    'email'  => $email,
                    'name'   => $acc['name'],
                    'status' => User::STATUS_ACTIVE,
                ]);
            }

            Assignment::updateOrCreate(
                ['user_id' => $user->id, 'role_id' => $role->id, 'org_unit_id' => $branch->id],
                ['data_scope' => Assignment::SCOPE_TEAM, 'active' => true]
            );
        }
    }
}
