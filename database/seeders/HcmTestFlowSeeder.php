<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seed test users cho luồng E2E HCM (5 nguồn: MKT/BDM/Data lạnh/REF/Walk-in).
 * Email pattern: test.hcm.<role>@longevity.com.vn, password: 123456.
 *
 * Roles cần cho flow đầy đủ:
 *   - test.hcm.trucpage   → Team trực page  (up nguồn MKT/BDM/Data lạnh)
 *   - test.hcm.cmbooking  → CM booking      (chia lead kho Booking cho team booking)
 *   - test.hcm.booking1/2 → Team booking    (nhận call, đặt lịch)
 *   - test.hcm.cmsale     → CM sale         (chia lead cho sale)
 *   - test.hcm.sale1/2    → Team sale (role "Sale")
 *
 * Cũng tạo org 'team-ashley-page' cho Team trực page HCM.
 */
class HcmTestFlowSeeder extends Seeder
{
    public function run(): void
    {
        // 1) Tạo org team-ashley-page nếu chưa có.
        $teamAshley = OrgUnit::where('code', 'team-ashley')->firstOrFail();
        $pageOrg = OrgUnit::firstWhere('code', 'team-ashley-page')
            ?? OrgUnit::createNode(['code' => 'team-ashley-page', 'name' => 'Team Trực Page'], $teamAshley);

        $bookingOrg = OrgUnit::where('code', 'team-ashley-booking')->firstOrFail();
        $saleOrg    = OrgUnit::where('code', 'team-ashley-sale')->firstOrFail();

        // 2) Users test HCM.
        $userDefs = [
            ['test.hcm.trucpage',   'Test HCM Trực Page',   'Team trực page', $pageOrg,    Assignment::SCOPE_SELF],
            ['test.hcm.cmbooking',  'Test HCM CM Booking',  'CM booking',     $bookingOrg, Assignment::SCOPE_TEAM],
            ['test.hcm.booking1',   'Test HCM Booking 1',   'Team booking',   $bookingOrg, Assignment::SCOPE_SELF],
            ['test.hcm.booking2',   'Test HCM Booking 2',   'Team booking',   $bookingOrg, Assignment::SCOPE_SELF],
            ['test.hcm.cmsale',     'Test HCM CM Sale',     'CM sale',        $saleOrg,    Assignment::SCOPE_TEAM],
            ['test.hcm.sale1',      'Test HCM Sale 1',      'Sale',           $saleOrg,    Assignment::SCOPE_SELF],
            ['test.hcm.sale2',      'Test HCM Sale 2',      'Sale',           $saleOrg,    Assignment::SCOPE_SELF],
        ];

        foreach ($userDefs as [$prefix, $name, $roleName, $org, $scope]) {
            $email = $prefix . '@longevity.com.vn';
            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'username' => $prefix,
                    'password' => '123456',
                    'status' => User::STATUS_ACTIVE,
                    'api_token' => bin2hex(random_bytes(24)),
                ]
            );

            $role = Role::where('name', $roleName)->first();
            if (! $role) {
                $this->command?->warn("Bỏ qua: role $roleName không tồn tại.");
                continue;
            }

            Assignment::updateOrCreate(
                ['user_id' => $user->id, 'role_id' => $role->id, 'org_unit_id' => $org->id],
                ['data_scope' => $scope, 'active' => true]
            );
        }

        $this->command?->info('HcmTestFlowSeeder: 7 test users HCM đã đồng bộ (password 123456).');
    }
}
