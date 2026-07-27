<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Chỉ seed tài khoản CM booking cho Team Booking Ashley (HCM) — team này chưa có CM real.
 * Giữ để luồng booking HCM chạy trọn vẹn.
 * Các tài khoản test HCM khác đã bị lược bỏ ngày 2026-07-27.
 */
class HcmTestFlowSeeder extends Seeder
{
    public function run(): void
    {
        $bookingOrg = OrgUnit::where('code', 'team-ashley-booking')->first();
        $roleCmBooking = Role::where('name', 'CM booking')->first();
        if (! $bookingOrg || ! $roleCmBooking) {
            $this->command?->warn('HcmTestFlowSeeder: thiếu org team-ashley-booking hoặc role CM booking.');
            return;
        }

        $user = User::updateOrCreate(
            ['username' => 'cmbooking_ashley'],
            [
                'name' => 'CM Booking Team Ashley',
                'email' => 'cmbooking_ashley@longevity.com.vn',
                'password' => '59@ntn',
                'status' => User::STATUS_ACTIVE,
            ],
        );

        Assignment::updateOrCreate(
            ['user_id' => $user->id, 'role_id' => $roleCmBooking->id, 'org_unit_id' => $bookingOrg->id],
            ['data_scope' => Assignment::SCOPE_TEAM, 'active' => true],
        );

        $this->command?->info('HcmTestFlowSeeder: giữ 1 CM booking cho Team Booking Ashley (' . $user->username . ').');
    }
}
