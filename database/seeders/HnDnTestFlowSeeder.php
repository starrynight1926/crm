<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seed test users cho E2E flow HN + DN.
 * Pattern email: test.hn.*, test.dn.*.
 *
 * HN: đi qua Team Giang subtree (Team trực page → CM booking → team booking → CM sale → sale).
 * DN: đi qua team-dn-sale với role "Team sale ĐN" (làm cả tele + book + sale).
 */
class HnDnTestFlowSeeder extends Seeder
{
    public function run(): void
    {
        // ─── HN accounts (via team-giang subtree) ─────────────────
        $hnPage    = OrgUnit::where('code', 'team-giang-page')->firstOrFail();
        $hnBooking = OrgUnit::where('code', 'team-giang-booking')->firstOrFail();
        $hnSale    = OrgUnit::where('code', 'team-giang-sale')->firstOrFail();

        $this->seedUsers([
            ['test.hn.trucpage',  'Test HN Trực Page',  'Team trực page', $hnPage,    Assignment::SCOPE_SELF],
            ['test.hn.cmbooking', 'Test HN CM Booking', 'CM booking',     $hnBooking, Assignment::SCOPE_TEAM],
            ['test.hn.booking1',  'Test HN Booking 1',  'Team booking',   $hnBooking, Assignment::SCOPE_SELF],
            ['test.hn.cmsale',    'Test HN CM Sale',    'CM sale',        $hnSale,    Assignment::SCOPE_TEAM],
            ['test.hn.sale1',     'Test HN Sale 1',     'Sale',           $hnSale,    Assignment::SCOPE_SELF],
        ]);

        // ─── DN accounts (team-dn-sale, role Team sale ĐN xuyên suốt) ──
        $dnSale    = OrgUnit::where('code', 'team-dn-sale')->firstOrFail();
        $marketingDn = OrgUnit::where('code', 'marketing-dn')->firstOrFail();

        $this->seedUsers([
            ['test.dn.cmsale',    'Test DN CM Sale',    'CM sale',        $marketingDn, Assignment::SCOPE_TEAM],
            ['test.dn.sale1',     'Test DN Sale 1',     'Team sale ĐN',   $dnSale,      Assignment::SCOPE_SELF],
            ['test.dn.sale2',     'Test DN Sale 2',     'Team sale ĐN',   $dnSale,      Assignment::SCOPE_SELF],
        ]);

        // DN đặc thù: CM Sale kiêm luôn CM Booking (giống Kim Phấn thực tế) — thêm assignment thứ 2.
        $cmsale = User::where('email', 'test.dn.cmsale@longevity.com.vn')->first();
        $cmBookingRole = Role::where('name', 'CM booking')->first();
        if ($cmsale && $cmBookingRole) {
            Assignment::updateOrCreate(
                ['user_id' => $cmsale->id, 'role_id' => $cmBookingRole->id, 'org_unit_id' => $marketingDn->id],
                ['data_scope' => Assignment::SCOPE_TEAM, 'active' => true]
            );
        }

        $this->command?->info('HnDnTestFlowSeeder: 5 test users HN + 3 test users DN đã đồng bộ.');
    }

    private function seedUsers(array $defs): void
    {
        foreach ($defs as [$prefix, $name, $roleName, $org, $scope]) {
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
                $this->command?->warn("Bỏ qua $email: role '$roleName' không tồn tại.");
                continue;
            }

            Assignment::updateOrCreate(
                ['user_id' => $user->id, 'role_id' => $role->id, 'org_unit_id' => $org->id],
                ['data_scope' => $scope, 'active' => true]
            );
        }
    }
}
