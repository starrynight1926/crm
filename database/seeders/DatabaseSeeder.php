<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            OrgStaffSeeder::class,           // Cây org + role + user + assignment (gộp)
            OrgUnitManagerSeeder::class,     // Người quản lý mỗi đơn vị
            RealDoctorsSeeder::class,        // 32 nhân sự Khối chuyên môn (3 cơ sở HN/HCM/ĐN)
            DemoDataSeeder::class,           // Leads/dịch vụ/payment demo (chỉ dùng phần data ngoài nhân sự)
            TeamHoiCustomFieldSeeder::class, // Custom fields cho Team Hợi
        ]);
    }
}
