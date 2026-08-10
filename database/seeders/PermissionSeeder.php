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
            'lead.view' => 'Xem lead',
            'lead.create' => 'Tạo lead',
            'lead.update' => 'Sửa lead (ghi chú, phân loại, booking_status, dịch vụ)',
            'lead.consult' => 'Là chuyên viên tư vấn (được chọn ở khối CV tư vấn của lead)',
            'lead.read_booking' => 'Vào màn Cập nhật ở chế độ readonly khi phase Booking (Team booking xem info + bấm Đặt booking)',
            'lead.update_booking' => 'Sửa info cá nhân khi lead ở phase Booking',
            'lead.book_action' => 'Bấm nút "Đặt booking" (chuyển sang lara-sbooking)',
            'lead.update_sale' => 'Sửa info cá nhân khi lead ở phase Sale',
            'lead.delete' => 'Xóa lead',
            'lead.import' => 'Import lead (Excel/CSV)',
            'lead.export' => 'Export lead (mặc định tắt, ghi audit)',
            'lead.view_phone' => 'Xem SĐT đầy đủ ngoài scope',
            'lead.source_all' => 'Up data mọi nguồn — bypass gate SOURCE_PERMISSIONS (dropdown Nhóm nguồn không bị disable)',
        ],
        'distribution' => [
            'lead.view_pool' => 'Xem kho số (kho chung công ty, chưa chia)',
            'lead.distribute' => 'Chia số thủ công',
            'lead.distribute_tele' => 'Chia số tele (chia lead nhóm 1 cho tele/booker gọi khách)',
            'lead.distribute_sale' => 'Chia số tiếp đón (chia sale tiếp đón khách tại clinic — nhóm 2/3)',
            'lead.distribute_to_team' => 'CM cơ sở: chia lead từ kho công ty/cơ sở xuống kho team',
            'lead.distribute_to_sale' => 'CM team: chia lead từ kho team xuống sale (owner)',
            'lead.recall' => 'Thu hồi lead + đặt mốc thu hồi khi chia',
            'lead.approve_source' => 'Duyệt lead từ luồng Walk-in (WI)',
            'lead.pull_pool' => 'Phân bổ từ kho số — chia thẳng lead trong kho cho 1 Sale/Tele (dashboard widget Kho số)',
            'lead.distribute_branch' => 'Chia toàn Chi nhánh — Trực Page up lead MKT được chọn cơ sở bất kỳ trong chi nhánh của mình (không tick = chỉ cấp cơ sở của họ)',
            'lead.distribute_company' => 'Chia toàn Công ty — Trực Page up lead MKT được chọn cơ sở bất kỳ trong cả 3 chi nhánh (cao hơn Chia toàn Chi nhánh)',
            'lead.assign_direct' => 'Chia lead thẳng — CM chọn thẳng 1 nhân sự trong scope để giao lead phase 2 (không qua UPS)',
            'rule.manage' => 'Cấu hình rule chia số',
            'ops.manage' => 'Cấu hình Quy tắc vận hành (thời gian recall/escalate)',
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
