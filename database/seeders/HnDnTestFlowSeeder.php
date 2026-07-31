<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Chỉ seed tài khoản CM sale cho Team Sale Giang (HN) — team này chưa có CM real.
 * Giữ để luồng sale HN chạy trọn vẹn (test.hn.cmsale làm CM Team Sale Giang).
 * Các tài khoản test HN/DN khác đã bị lược bỏ ngày 2026-07-27.
 */
class HnDnTestFlowSeeder extends Seeder
{
    public function run(): void
    {
        $hnSale = OrgUnit::where('code', 'team-giang-sale')->first();
        $roleCmSale = Role::where('name', 'CM sale')->first();
        if (! $hnSale || ! $roleCmSale) {
            $this->command?->warn('HnDnTestFlowSeeder: thiếu org team-giang-sale hoặc role CM sale.');
            return;
        }

        // Fallback qua name nếu username đã đổi sang format vị trí (hn.cms##).
        $user = User::firstWhere('username', 'cmsale_giang')
            ?? User::firstWhere('name', 'CM Sale Team Giang');
        if ($user) {
            $user->update(['name' => 'CM Sale Team Giang', 'status' => User::STATUS_ACTIVE]);
        } else {
            $user = User::create([
                'username' => 'cmsale_giang', 'name' => 'CM Sale Team Giang',
                'email' => 'cmsale_giang@longevity.com.vn',
                'password' => \App\Support\DefaultPassword::HN, 'status' => User::STATUS_ACTIVE,
            ]);
        }

        Assignment::updateOrCreate(
            ['user_id' => $user->id, 'role_id' => $roleCmSale->id, 'org_unit_id' => $hnSale->id],
            ['data_scope' => Assignment::SCOPE_TEAM, 'active' => true],
        );

        $this->command?->info('HnDnTestFlowSeeder: giữ 1 CM sale cho Team Sale Giang (' . $user->username . ').');
    }
}
