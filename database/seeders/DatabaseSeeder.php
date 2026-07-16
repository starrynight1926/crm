<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@longevity.com.vn'],
            [
                'name' => 'Quản trị viên',
                'password' => 'admin@123',
                'status' => User::STATUS_ACTIVE,
            ]
        );

        $this->call([
            PermissionSeeder::class,
            OrgAndRoleSeeder::class,
            DemoDataSeeder::class,
            TeamHoiCustomFieldSeeder::class, // sau OrgAndRoleSeeder: cần node Marketing
            TeamHoiStaffSeeder::class,       // nhân viên sale Team Hợi (cần role Sale + Team Hợi)
            RealCmStaffSeeder::class,        // Phase 6.6: CM/DM/TL/Trợ lý thật của HN + HCM
            Phase66FlowSeeder::class,        // Phase 6.6+: 4 role luồng 6 nguồn + user demo
        ]);
    }
}
