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

        // 2026-07-16: bỏ block seed "Phòng Kinh doanh / Marketing" root — dùng cấu trúc thật
        // (Cơ sở HN > Marketing > Team Giang/Hợi > Team Booking + Team Sale) ở RealCmStaffSeeder.

        // Cơ sở Đà Nẵng — Marketing > (Team Booking + Team Sale), không có team CM cấp giữa.
        $branchDn = OrgUnit::firstWhere('code', 'branch-dn')
            ?? OrgUnit::createNode(['name' => 'Cơ sở Đà Nẵng', 'code' => 'branch-dn'], $root);
        $mktDn = OrgUnit::firstWhere('code', 'marketing-dn')
            ?? OrgUnit::createNode(['name' => 'Marketing', 'code' => 'marketing-dn'], $branchDn);
        OrgUnit::firstWhere('code', 'team-dn-booking')
            ?? OrgUnit::createNode(['name' => 'Team Booking', 'code' => 'team-dn-booking'], $mktDn);
        OrgUnit::firstWhere('code', 'team-dn-sale')
            ?? OrgUnit::createNode(['name' => 'Team Sale', 'code' => 'team-dn-sale'], $mktDn);

        // Role hệ thống
        $admin = Role::updateOrCreate(
            ['name' => 'Admin'],
            ['description' => 'Toàn quyền hệ thống', 'is_system' => true]
        );
        // Admin nhận mọi perm TRỪ `lead.consult` — perm này chỉ dành cho role thực sự tư vấn khách
        // (Sale/CM sale/Team sale/Manager). Muốn Admin tư vấn cụ thể lead thì gán perm riêng qua Role Manager.
        $admin->permissions()->sync(Permission::where('key', '!=', 'lead.consult')->pluck('id'));

        // Phase 6.6 — 3 role bổ sung theo cơ cấu thực tế
        $tl = Role::updateOrCreate(['name' => 'Team Leader'], [
            'description' => 'Trưởng nhóm — quyền như CM nhưng scope team, chia số cấp team',
            'is_system' => true,
        ]);
        $tl->permissions()->sync(Permission::whereIn('key', [
            'lead.view', 'lead.view_phone', 'lead.create', 'lead.update',
            'lead.view_pool', 'lead.distribute', 'lead.distribute_booking', 'lead.distribute_sale', 'lead.recall', 'lead.approve_source',
            'report.view',
        ])->pluck('id'));

        $assistant = Role::updateOrCreate(['name' => 'Trợ lý kinh doanh'], [
            'description' => 'Xem data cấp công ty, không thêm/sửa',
            'is_system' => true,
        ]);
        $assistant->permissions()->sync(Permission::whereIn('key', [
            'lead.view', 'report.view',
        ])->pluck('id'));

        $dmHcm = Role::updateOrCreate(['name' => 'DM HCM'], [
            'description' => 'Directional Manager HCM — cao nhất khu vực HCM',
            'is_system' => true,
        ]);
        $dmHcm->permissions()->sync(Permission::whereIn('key', [
            'lead.view', 'lead.view_phone', 'lead.create', 'lead.update', 'lead.update_booking', 'lead.update_sale', 'lead.delete',
            'lead.import', 'lead.export',
            'lead.view_pool', 'lead.distribute', 'lead.distribute_booking', 'lead.distribute_sale', 'lead.distribute_ctv', 'lead.recall', 'lead.approve_source',
            'user.manage', 'rule.manage',
            'service.manage', 'payment.record', 'contribution.set',
            'field.manage', 'field.approve',
            'staff.manage',
            'report.view', 'report.view_all',
        ])->pluck('id'));

        // Manager: quản lý team + phân bổ + duyệt + thu hồi + xem báo cáo trong scope
        $manager = Role::updateOrCreate(['name' => 'Manager'], ['description' => 'Quản lý team & chia số']);
        $managerPerms = Permission::whereIn('key', [
            'lead.view', 'lead.create', 'lead.update', 'lead.update_booking', 'lead.update_sale', 'lead.consult', 'lead.view_phone',
            'lead.view_pool', 'lead.distribute', 'lead.distribute_booking', 'lead.distribute_sale', 'lead.approve_source', 'lead.recall',
            'report.view',
        ])->pluck('id');
        $manager->permissions()->sync($managerPerms);

        // Sale: view + create lead trong scope của mình
        $sale = Role::updateOrCreate(['name' => 'Sale'], ['description' => 'Khai thác & chăm sóc khách hàng']);
        $salePerms = Permission::whereIn('key', ['lead.view', 'lead.create', 'lead.update', 'lead.consult', 'report.view'])->pluck('id');
        $sale->permissions()->sync($salePerms);

        // (Đã bỏ 3 role vùng CM Hà Nội/Đà Nẵng/HCM — 2026-07-16.
        //  Role chỉ nên là "CM sale" chung, khu vực do assignment quyết định.
        //  Permission `lead.distribute_ctv` được gộp vào role "CM sale" ở Phase66FlowSeeder.)

        // Phase 6.6: seed default system_settings (nếu chưa có)
        foreach ([
            'default_recall_after_days' => '7',
            'default_escalate_after_days' => '3',
            'default_allow_permanent' => '1',
        ] as $k => $v) {
            \DB::table('system_settings')->updateOrInsert(
                ['key' => $k],
                ['value' => $v, 'created_at' => now(), 'updated_at' => now()]
            );
        }

        // Gán admin: role Admin tại gốc công ty, scope custom = thấy toàn bộ cây
        $adminUser = User::firstWhere('email', 'admin@longevity.com.vn');
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
