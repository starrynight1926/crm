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
            // DemoDataSeeder::class,        // BỎ (2026-08-03) — không seed khách hàng ảo nữa. Muốn demo → chạy riêng.
            TeamHoiCustomFieldSeeder::class, // Custom fields cho Team Hợi
            SyncCrmAccountsSeeder::class,    // Đồng bộ username + password với Booking (admin=59ntn, user=59@ntn)
            // 2026-08-05: BỎ HnDnTestFlowSeeder + HcmTestFlowSeeder — tài khoản test kiểu "CM Sale Team Giang" bị dẹp theo yêu cầu.
            RenameUsersToPositionFormatSeeder::class, // Đổi username+email sang <cơ_sở>.<chức_vụ>NN
            PoolUnitSeeder::class,                    // Cây Kho số Longevity Medical (Phase 6.22)
            BoRoleSeeder::class,                      // Role BO (Lễ Tân) + 3 tk BO HN/DN/HCM (Phase 6.22)
            RolePermissionSyncSeeder::class,          // SNAPSHOT role→perms
            SourcePermissionMatrixSeeder::class,      // Overlay source.up.* — SOURCE OF TRUTH cho quyền up nguồn (chạy sau RolePermissionSync).
            StaffAndOrgSeeder::class,                 // Observer tài khoản + Admin hệ thống + chức danh + Kim Phấn CM DN.
            ReportCustomFieldSeeder::class,           // Custom fields báo cáo (ngay_sinh, dia_chi...) + facilities/staff demo.
            CompanyReportPresetSeeder::class,         // Preset báo cáo list cấp Công ty.
            SyncSbookingCatalogSeeder::class,         // Kéo BS/DV/phòng từ sbooking (phut_kham_ls…) — chạy cuối, cần sbooking đang up.
            // NOTE: Phase66FlowSeeder + OrgAndRoleSeeder ĐÃ DEAD (Phase66 fail null-id, OrgAndRole silent).
        ]);
    }
}
