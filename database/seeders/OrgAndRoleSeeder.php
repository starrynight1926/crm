<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\OrgUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class OrgAndRoleSeeder extends Seeder
{
    public function run(): void
    {
        // Cây tổ chức mẫu: Công ty > (Kinh doanh > Team A, Team B), Marketing
        $root = OrgUnit::firstWhere('code', 'company')
            ?? OrgUnit::createNode(['name' => 'Công ty', 'code' => 'company']);

        $sales = OrgUnit::firstWhere('code', 'sales')
            ?? OrgUnit::createNode(['name' => 'Phòng Kinh doanh', 'code' => 'sales'], $root);

        OrgUnit::firstWhere('code', 'sales-a')
            ?? OrgUnit::createNode(['name' => 'Team Sale A', 'code' => 'sales-a'], $sales);
        OrgUnit::firstWhere('code', 'sales-b')
            ?? OrgUnit::createNode(['name' => 'Team Sale B', 'code' => 'sales-b'], $sales);
        OrgUnit::firstWhere('code', 'marketing')
            ?? OrgUnit::createNode(['name' => 'Phòng Marketing', 'code' => 'marketing'], $root);

        // Role hệ thống
        $admin = Role::updateOrCreate(
            ['name' => 'Admin'],
            ['description' => 'Toàn quyền hệ thống', 'is_system' => true]
        );
        $admin->permissions()->sync(Permission::pluck('id'));

        Role::updateOrCreate(['name' => 'Manager'], ['description' => 'Quản lý team & chia số']);
        Role::updateOrCreate(['name' => 'Sale'], ['description' => 'Khai thác & chăm sóc khách hàng']);

        // Gán admin: role Admin tại gốc công ty, scope custom = thấy toàn bộ cây
        $adminUser = User::firstWhere('email', 'admin@sweetsica.com');
        if ($adminUser && ! Assignment::where('user_id', $adminUser->id)->exists()) {
            $assignment = Assignment::create([
                'user_id' => $adminUser->id,
                'role_id' => $admin->id,
                'org_unit_id' => $root->id,
                'data_scope' => Assignment::SCOPE_CUSTOM,
            ]);
            $assignment->scopeNodes()->sync([$root->id]);
        }
    }
}
