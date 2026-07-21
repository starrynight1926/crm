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
            'lead.update_booking' => 'Sửa info cá nhân khi lead ở phase Booking',
            'lead.update_sale' => 'Sửa info cá nhân khi lead ở phase Sale',
            'lead.delete' => 'Xóa lead',
            'lead.import' => 'Import lead (Excel/CSV)',
            'lead.export' => 'Export lead (mặc định tắt, ghi audit)',
            'lead.view_phone' => 'Xem SĐT đầy đủ ngoài scope',
        ],
        'distribution' => [
            'lead.view_pool' => 'Xem kho số (kho chung công ty, chưa chia)',
            'lead.distribute' => 'Chia số thủ công',
            'lead.distribute_booking' => 'Chia số ở kho Booking (QL team booking)',
            'lead.distribute_sale' => 'Chia số ở kho Sale (CM team sale)',
            'lead.distribute_team' => '[DEPRECATED] Chia số cho team — thay bằng distribute_booking/distribute_sale',
            'lead.distribute_ctv' => 'Phân bổ nguồn Cộng tác viên (theo khu vực)',
            'lead.recall' => 'Thu hồi lead + đặt mốc thu hồi khi chia',
            'lead.approve_source' => 'Duyệt lead từ luồng "Khách tự đến"',
            'lead.pull_pool' => 'Kéo lead từ kho (legacy — chỉ hiện khi role tick tay)',
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
