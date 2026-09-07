<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Danh mục quyền chức năng (RBAC). Key dùng cố định trong code, không đổi sau khi phát hành.
     */
    public const PERMISSIONS = [
        'lead' => [
            // [XEM LEAD THEO PHÒNG BAN / CÁ NHÂN] — scope theo assignment/org của user
            'lead.view' => '[Xem – phòng ban/cá nhân] Xem lead trong phòng ban / cá nhân được gán',
            'lead.create' => '[Thao tác] Tạo lead',
            'lead.update' => '[Thao tác] Sửa lead (ghi chú, phân loại, booking_status, dịch vụ)',
            'lead.consult' => '[Thao tác] Là chuyên viên tư vấn (được chọn ở khối CV tư vấn của lead)',
            'lead.read_booking' => '[Thao tác] Vào màn Cập nhật readonly khi phase Booking (Team booking xem info + bấm Đặt booking)',
            'lead.update_booking' => '[Thao tác] Sửa info cá nhân khi lead ở phase Booking',
            'lead.book_action' => '[Thao tác] Bấm nút "Đặt booking" (chuyển sang lara-sbooking)',
            'lead.update_sale' => '[Thao tác] Sửa info cá nhân khi lead ở phase Sale',
            'lead.delete' => '[Thao tác] Xóa lead',
            'lead.import' => '[Thao tác] Import lead (Excel/CSV)',
            'lead.export' => '[Thao tác] Export lead (mặc định tắt, ghi audit)',
            // [XEM LEAD TOÀN CÔNG TY] — bypass scope
            'lead.view_phone' => '[Xem – toàn công ty] Xem SĐT đầy đủ ngoài scope',
            'lead.source_all' => '[Thao tác] Up data mọi nguồn — bypass gate SOURCE_PERMISSIONS',
        ],
        'distribution' => [
            // [XEM LEAD TOÀN CÔNG TY]
            'lead.view_pool' => '[Xem – toàn công ty] Xem kho số công ty (kho chung, chưa chia)',
            // [XEM LEAD THEO PHÒNG BAN / CÁ NHÂN]
            'lead.view_team_pool' => '[Xem – phòng ban/cá nhân] Xem kho team/pool (lead trong pool_unit chờ chia xuống cá nhân)',

            // [CHIA LEAD TOÀN CÔNG TY] — chia được cross cơ sở/chi nhánh, không giới hạn scope
            'lead.distribute' => '[Chia – toàn công ty] Chia số thủ công (bypass rule)',
            'lead.distribute_branch' => '[Chia – toàn công ty] Chia toàn Chi nhánh — Trực Page up MKT chọn cơ sở bất kỳ trong chi nhánh của mình',
            'lead.distribute_company' => '[Chia – toàn công ty] Chia toàn Công ty — Trực Page up MKT chọn cơ sở bất kỳ trong cả 3 chi nhánh',
            'lead.assign_direct' => '[Chia – toàn công ty] Chia lead thẳng — CM chọn thẳng 1 nhân sự trong scope để giao lead phase 2 (không qua UPS)',

            // [CHIA LEAD THEO PHÒNG BAN / CÁ NHÂN] — chia trong scope của user
            'lead.distribute_tele' => '[Chia – phòng ban/cá nhân] Chia số tele (nhóm 1 cho tele/booker gọi khách)',
            'lead.distribute_sale' => '[Chia – phòng ban/cá nhân] Chia số tiếp đón (sale tiếp đón khách tại clinic — nhóm 2/3)',
            'lead.distribute_to_team' => '[Chia – phòng ban/cá nhân] CM cơ sở: chia lead từ kho công ty/cơ sở xuống kho team',
            'lead.distribute_to_sale' => '[Chia – phòng ban/cá nhân] CM team: chia lead từ kho team xuống sale (owner)',
            'lead.pull_pool' => '[Chia – phòng ban/cá nhân] Phân bổ từ kho số — chia thẳng lead trong kho cho 1 Sale/Tele (dashboard widget Kho số)',
            'lead.distribute_pool_ups' => '[Chia – phòng ban/cá nhân] Chia kho số theo UPS — bấm "Chia tự động" trên lead trong kho, hệ thống pick round-robin UPS list',

            // [CẤU HÌNH THỜI GIAN THU HỒI]
            'lead.recall' => '[Cấu hình thu hồi] Thu hồi lead + đặt mốc thu hồi khi chia',
            'ops.manage' => '[Cấu hình thu hồi] Cấu hình Quy tắc vận hành (thời gian recall/escalate/UPS lock)',
            'rule.manage' => '[Cấu hình thu hồi] Cấu hình rule chia số',

            // Còn lại
            'lead.approve_source' => '[Thao tác] Duyệt lead từ luồng Walk-in (WI)',
        ],
        'organization' => [
            'user.manage' => 'Quản lý nhân viên & phân quyền',
            'role.manage' => 'Quản lý vai trò',
            'org.manage' => 'Quản lý sơ đồ tổ chức',
            'field.manage' => 'Quản lý trường tùy biến của phòng ban',
            'field.approve' => 'Duyệt trường bắt buộc của cấp dưới',
            'staff.manage' => 'Chỉnh sửa danh mục bác sĩ & cơ sở',
        ],
        'service' => [
            'service.manage' => 'Quản lý danh mục dịch vụ',
            'payment.record' => 'Ghi nhận thu tiền',
            'contribution.set' => 'Đánh % đóng góp khi Close',
        ],
        'report' => [
            'report.view' => 'Xem báo cáo cá nhân / phòng ban',
            'report.view_all' => 'Xem báo cáo toàn bộ hệ thống',
        ],
        'system' => [
            'connection.manage' => 'Quản lý kết nối nguồn lead (Ads API, webhook)',
            'system.backup' => 'Sao lưu & khôi phục cấu hình / dữ liệu hệ thống',
        ],
        'source_up' => [
            // 2026-08-09: refactor 1-1 với 7 nguồn.
            'source.up.mkt'    => 'Đăng nguồn: MKT (Marketing)',
            'source.up.mkt_br' => 'Đăng nguồn: MKT BR (Marketing BR)',
            'source.up.sa'     => 'Đăng nguồn: SA (Sale hẹn lại)',
            'source.up.ba'     => 'Đăng nguồn: BA (Bạn giới thiệu)',
            'source.up.bdm'    => 'Đăng nguồn: BDM',
            'source.up.bod'    => 'Đăng nguồn: BOD',
            'source.up.wi'     => 'Đăng nguồn: WI (Walk-in)',
            'source.up.hl'     => 'Đăng nguồn: HL (Hotline)',
        ],
        'recall' => [
            'recall.import' => 'Import xlsx số re-call (Trực Page)',
            'recall.view' => 'Xem kho re-call',
            'recall.assign' => 'Chia hàng loạt kho re-call cho Sale UPS MKT',
        ],
        'ups' => [
            'ups.view' => 'Xem bảng UPS check-in',
            'ups.checkin' => 'Bấm check-in sale đầu ngày',
            'ups.override' => 'Sửa bucket / bỏ OFF LIST (BO only)',
            'ups.confirm_daily' => 'Chốt UPS hôm nay (mở khóa chia số)',
        ],
        'customer_flow' => [
            'phase.close.new' => 'Chốt phase 1 — Tạo mới & Chia số (gộp)',
            'phase.close.call' => 'Chốt phase 2 — Gọi điện',
            'phase.close.booking' => 'Chốt phase 3 — Booking thăm khám',
            'phase.close.checkin' => 'Chốt phase 4 — Check-in',
            'phase.rollback' => 'Lùi phase (Admin vận hành only)',
        ],
    ];

    public function run(): void
    {
        $position = 0;
        foreach (self::PERMISSIONS as $group => $items) {
            foreach ($items as $key => $label) {
                Permission::updateOrCreate(
                    ['key' => $key],
                    ['label' => $label, 'group' => $group, 'position' => $position++]
                );
            }
        }
    }
}
