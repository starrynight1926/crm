<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\OrgUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Phase 6.6+ — seed 4 role + org units + user demo cho luồng 6 nguồn:
 * Team trực page / CM booking / Team booking / CM sale.
 * Idempotent — chạy lại không nhân đôi.
 */
class Phase66FlowSeeder extends Seeder
{
    public function run(): void
    {
        // 2026-07-16: dời user demo vào cấu trúc thật (Marketing > Team Giang/Hợi > Booking/Sale).
        $teamGiang = OrgUnit::firstWhere('code', 'team-giang');
        $teamHoi = OrgUnit::firstWhere('code', 'team-hoi-hn');
        $bookingGiang = OrgUnit::firstWhere('code', 'team-giang-booking');
        $bookingHoi = OrgUnit::firstWhere('code', 'team-hoi-booking');
        $pageGiang = OrgUnit::firstWhere('code', 'team-giang-page');
        $saleGiang = OrgUnit::firstWhere('code', 'team-giang-sale');
        $saleHoi = OrgUnit::firstWhere('code', 'team-hoi-sale');
        $marketingHn = OrgUnit::firstWhere('code', 'marketing-hn');

        // 2) 4 role mới. Permissions gán theo SOURCE_PERMISSIONS + nhu cầu luồng.
        $roleDefs = [
            'Team trực page' => [
                'desc' => 'Team trực page marketing — up lead nguồn Marketing/Data lạnh/BDM',
                'perms' => ['lead.create','lead.distribute_team'],
            ],
            'CM booking' => [
                'desc' => 'CM Phòng Booking — up Data lạnh/BDM, chia lead trong kho booking cho team booking',
                'perms' => [
                    'lead.view', 'lead.view_phone', 'lead.create', 'lead.update', 'lead.update_booking',
                    'lead.view_pool', 'lead.distribute', 'lead.distribute_booking', 'lead.recall', 'report.view',
                ],
            ],
            'Team booking' => [
                'desc' => 'Team booking — gọi khách, cập nhật info cá nhân + đổi trạng thái đặt lịch',
                'perms' => ['lead.view', 'lead.view_phone', 'lead.update', 'lead.update_booking'],
            ],
            'CM sale' => [
                'desc' => 'CM Phòng Kinh doanh — chia lead đã đồng ý sang sale + sửa info cá nhân khi ở phase Sale',
                'perms' => [
                    'lead.view', 'lead.view_phone', 'lead.create', 'lead.update', 'lead.update_sale',
                    'lead.view_pool', 'lead.distribute', 'lead.distribute_sale', 'lead.distribute_ctv', 'lead.recall', 'report.view',
                ],
            ],
            'Team sale' => [
                'desc' => 'Sale nhân viên — chăm sóc khách, ghi chú, phân loại, gắn dịch vụ',
                'perms' => ['lead.view', 'lead.view_phone', 'lead.update', 'report.view'],
            ],
        ];

        $roles = [];
        foreach ($roleDefs as $name => $def) {
            $role = Role::updateOrCreate(['name' => $name], [
                'description' => $def['desc'],
                'is_system' => true,
            ]);
            $role->permissions()->sync(Permission::whereIn('key', $def['perms'])->pluck('id'));
            $roles[$name] = $role;
        }

        // 3) User demo (pw = 123456). Idempotent.
        $userDefs = [
            // email => [name, role, org, scope]
            'page1@longevity.com.vn' => ['Phạm Trực Page 1', 'Team trực page', $pageGiang, Assignment::SCOPE_SELF],
            'cmbktg@longevity.com.vn' => ['CM Booking Team Giang', 'CM booking', $bookingGiang, Assignment::SCOPE_TEAM],
            'book1@longevity.com.vn' => ['Nguyễn Booking 1', 'Team booking', $bookingGiang, Assignment::SCOPE_SELF],
            'book2@longevity.com.vn' => ['Trần Booking 2', 'Team booking', $bookingHoi, Assignment::SCOPE_SELF],
            // cmsale demo: CM sale cấp Marketing HN — subtree bao 2 team Giang + Hợi để chuyển kho.
            'cmsale@longevity.com.vn'=> ['CM Sale', 'CM sale', $marketingHn, Assignment::SCOPE_TEAM],
        ];

        foreach ($userDefs as $email => [$name, $roleName, $org, $scope]) {
            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => '123456',
                    'status' => User::STATUS_ACTIVE,
                ]
            );

            $role = $roles[$roleName];
            $existing = Assignment::where('user_id', $user->id)->where('role_id', $role->id)->first();
            if ($existing) {
                $existing->update(['org_unit_id' => $org->id, 'data_scope' => $scope, 'active' => true]);
            } else {
                Assignment::create([
                    'user_id' => $user->id,
                    'role_id' => $role->id,
                    'org_unit_id' => $org->id,
                    'data_scope' => $scope,
                ]);
            }
        }
    }
}
