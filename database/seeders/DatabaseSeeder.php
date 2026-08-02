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
            AdminCoSoSeeder::class,          // 3 tài khoản Admin cơ sở HN/HCM/DN (sau OrgStaffSeeder để có branch)
            OrgUnitManagerSeeder::class,     // Người quản lý mỗi đơn vị
            RealDoctorsSeeder::class,        // 32 nhân sự Khối chuyên môn (3 cơ sở HN/HCM/ĐN)
            RealCmStaffSeeder::class,        // CM sale + Team Leader + Sale (Team Giang / Team Hợi HN)
            TeamHoiStaffSeeder::class,       // Nhân sự Team Hợi mở rộng
            ServiceSeeder::class,            // Bảng giá dịch vụ (gói khám, lẻ...)
            DemoDataSeeder::class,           // Leads/dịch vụ/payment demo (chỉ dùng phần data ngoài nhân sự)
            TeamHoiCustomFieldSeeder::class, // Custom fields cho Team Hợi
            SyncCrmAccountsSeeder::class,    // Đồng bộ username + password với Booking (admin=59ntn, user=59@ntn)
            HnDnTestFlowSeeder::class,       // 1 CM sale cho team-giang-sale (team này chưa có CM real)
            HcmTestFlowSeeder::class,        // 1 CM booking cho team-ashley-booking (team này chưa có CM real)
            RenameUsersToPositionFormatSeeder::class, // Đổi username+email sang <cơ_sở>.<chức_vụ>NN
            RolePermissionSyncSeeder::class,          // SNAPSHOT role→perms — SOURCE OF TRUTH, CHẠY SAU CÙNG
        ]);
    }
}
