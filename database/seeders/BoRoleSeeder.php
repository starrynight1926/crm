<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\User;
use App\Support\DefaultPassword;
use Illuminate\Database\Seeder;

class BoRoleSeeder extends Seeder
{
    public function run(): void
    {
        $role = Role::updateOrCreate(
            ['name' => 'BO (Lễ Tân)'],
            [
                'description' => 'Lễ Tân — quản UPS check-in đầu ngày, chốt UPS mở khóa chia số cho cơ sở',
                'is_system' => true,
            ]
        );

        // Perm được set ở RolePermissionSyncSeeder (source of truth, chạy sau cùng).

        $branches = [
            'branch-hn'  => ['email' => 'bo.hn@longevity.com.vn',  'name' => 'BO Hà Nội'],
            'branch-dn'  => ['email' => 'bo.dn@longevity.com.vn',  'name' => 'BO Đà Nẵng'],
            'branch-hcm' => ['email' => 'bo.hcm@longevity.com.vn', 'name' => 'BO Hồ Chí Minh'],
        ];

        foreach ($branches as $orgCode => $info) {
            $org = OrgUnit::firstWhere('code', $orgCode);
            if (! $org) {
                continue;
            }

            $user = User::firstWhere('email', $info['email']) ?? User::create([
                'email' => $info['email'],
                'name' => $info['name'],
                'job_title' => 'BO (Lễ Tân)',
                'password' => DefaultPassword::forEmail($info['email']),
                'status' => User::STATUS_ACTIVE,
            ]);

            $assignment = Assignment::firstOrNew([
                'user_id' => $user->id,
                'role_id' => $role->id,
            ]);
            $assignment->fill([
                'org_unit_id' => $org->id,
                'data_scope' => Assignment::SCOPE_TEAM,
                'active' => true,
            ])->save();
        }
    }
}
