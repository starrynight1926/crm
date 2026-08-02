<?php

use App\Models\Assignment;
use App\Models\OrgUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\DefaultPassword;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tạo role "Admin cơ sở" + 3 tài khoản admin.hn / admin.dn / admin.hcm.
 *
 * Vai trò: người điều hành cơ sở — up được LEAD MỌI NGUỒN + chia số giống CM
 * (kho Tele + kho Sale). Scope = SCOPE_TEAM theo branch (thấy subtree cơ sở).
 * KHÔNG có quyền quản trị công ty (org/user/role/service/rule.manage).
 */
return new class extends Migration {
    private const ACCOUNTS = [
        ['username' => 'admin.hn',  'name' => 'Admin Cơ sở Hà Nội',  'branch' => 'branch-hn'],
        ['username' => 'admin.hcm', 'name' => 'Admin Cơ sở HCM',     'branch' => 'branch-hcm'],
        ['username' => 'admin.dn',  'name' => 'Admin Cơ sở Đà Nẵng', 'branch' => 'branch-dn'],
    ];

    // Trim 2026-07-31 (bug bash): Admin cơ sở chỉ có quyền xem + chia P2 + xử lý P4 + báo cáo.
    // KHÔNG có create/update/import/export/source.up + phase.close.new/call/checkin/payment.
    private const PERMS = [
        // Xem
        'lead.view', 'lead.view_phone',
        // Nhập + import + xóa mềm + up nguồn QL Sale (2026-08-02: Admin cơ sở = QL Sale — up SA/BDM/BOD/WI qua source.up.admin; delete = ẩn; KHÔNG có quyền vào Thùng rác).
        'lead.create', 'lead.import', 'lead.delete', 'source.up.admin',
        // Chia số phase 2 (kho Tele + kho Sale)
        'lead.view_pool', 'lead.distribute', 'lead.distribute_tele', 'lead.distribute_sale',
        'lead.distribute_to_team', 'lead.distribute_to_sale', 'lead.recall',
        // Phase 4 booking
        'lead.book_action', 'lead.update_booking', 'lead.read_booking', 'phase.close.booking',
        // Báo cáo
        'report.view',
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
                    'password' => DefaultPassword::forEmail($email), // model cast 'hashed' sẽ tự bcrypt
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
