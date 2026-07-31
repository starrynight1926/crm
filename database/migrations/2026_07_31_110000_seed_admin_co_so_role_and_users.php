<?php

use App\Models\Assignment;
use App\Models\OrgUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Tạo role "Admin cơ sở" + 3 tài khoản admin.hn / admin.dn / admin.hcm.
 *
 * Vai trò: người điều hành cơ sở — up được LEAD MỌI NGUỒN + chia số giống CM
 * (kho Tele + kho Sale). Scope = SCOPE_TEAM theo branch (thấy subtree cơ sở).
 * KHÔNG có quyền quản trị công ty (org/user/role/service/rule.manage).
 */
return new class extends Migration {
    private const PASSWORD = '59@ntn'; // giống dev pattern (DemoDataSeeder)

    private const ACCOUNTS = [
        ['username' => 'admin.hn',  'name' => 'Admin Cơ sở Hà Nội',  'branch' => 'branch-hn'],
        ['username' => 'admin.hcm', 'name' => 'Admin Cơ sở HCM',     'branch' => 'branch-hcm'],
        ['username' => 'admin.dn',  'name' => 'Admin Cơ sở Đà Nẵng', 'branch' => 'branch-dn'],
    ];

    private const PERMS = [
        // Up mọi nguồn (không bị dropdown disable)
        'source.up.trucpage', 'source.up.sale', 'source.up.tele', 'source.up.admin', 'lead.source_all',
        // Chia số giống CM (cả kho Tele + kho Sale)
        'lead.view_pool', 'lead.distribute', 'lead.distribute_tele', 'lead.distribute_sale',
        'lead.distribute_to_team', 'lead.distribute_to_sale', 'lead.recall', 'lead.pull_pool',
        // Duyệt Walk-in
        'lead.approve_source',
        // Basic lead
        'lead.view', 'lead.view_phone', 'lead.create', 'lead.update',
        'lead.update_booking', 'lead.update_sale', 'lead.read_booking', 'lead.book_action',
        'lead.import', 'lead.export',
        // Chốt phase 1..5 (không có rollback — đó là ops-monitor)
        'phase.close.new', 'phase.close.distribute', 'phase.close.call',
        'phase.close.booking', 'phase.close.checkin',
        // Payment + Report
        'payment.record', 'report.view',
    ];

    public function up(): void
    {
        $role = Role::firstOrCreate(
            ['name' => 'Admin cơ sở'],
        );
        $permIds = Permission::whereIn('key', self::PERMS)->pluck('id')->all();
        $role->permissions()->syncWithoutDetaching($permIds);

        foreach (self::ACCOUNTS as $acc) {
            $branch = OrgUnit::firstWhere('code', $acc['branch']);
            if (! $branch) {
                // Skip quiet nếu chưa có branch (env test thiếu org)
                continue;
            }

            $email = $acc['username'] . '@longevity.com.vn';
            $user = User::firstWhere('email', $email) ?? User::firstWhere('name', $acc['name']);

            if (! $user) {
                $user = User::create([
                    'email'    => $email,
                    'name'     => $acc['name'],
                    'password' => self::PASSWORD, // model cast 'hashed' sẽ tự bcrypt
                    'status'   => User::STATUS_ACTIVE,
                ]);
            } else {
                $user->update([
                    'email'  => $email,
                    'name'   => $acc['name'],
                    'status' => User::STATUS_ACTIVE,
                ]);
            }

            // Assignment idempotent: giữ nếu đã có (user, role, branch); tạo nếu chưa.
            Assignment::updateOrCreate(
                ['user_id' => $user->id, 'role_id' => $role->id, 'org_unit_id' => $branch->id],
                ['data_scope' => Assignment::SCOPE_TEAM, 'active' => true]
            );
        }
    }

    public function down(): void
    {
        $emails = array_map(fn ($a) => $a['username'] . '@longevity.com.vn', self::ACCOUNTS);
        $userIds = User::whereIn('email', $emails)->pluck('id');
        if ($userIds->isNotEmpty()) {
            Assignment::whereIn('user_id', $userIds)->delete();
            User::whereIn('id', $userIds)->delete();
        }
        $role = Role::firstWhere('name', 'Admin cơ sở');
        if ($role) {
            $role->permissions()->detach();
            $role->delete();
        }
    }
};
