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
            'lead.update' => 'Sửa lead',
            'lead.delete' => 'Xóa lead',
            'lead.import' => 'Import lead (Excel/CSV)',
            'lead.export' => 'Export lead (mặc định tắt, ghi audit)',
            'lead.view_phone' => 'Xem SĐT đầy đủ ngoài scope',
        ],
        'distribution' => [
            'lead.distribute' => 'Chia số thủ công',
            'lead.recall' => 'Thu hồi lead',
            'lead.pull_pool' => 'Kéo lead từ kho',
            'rule.manage' => 'Cấu hình rule chia số',
        ],
        'organization' => [
            'user.manage' => 'Quản lý nhân viên & phân quyền',
            'role.manage' => 'Quản lý vai trò',
            'org.manage' => 'Quản lý sơ đồ tổ chức',
            'field.manage' => 'Quản lý trường tùy biến của phòng ban',
            'field.approve' => 'Duyệt trường bắt buộc của cấp dưới',
        ],
        'service' => [
            'service.manage' => 'Quản lý danh mục dịch vụ',
            'payment.record' => 'Ghi nhận thu tiền',
            'contribution.set' => 'Đánh % đóng góp khi Close',
        ],
        'report' => [
            'report.view' => 'Xem báo cáo & dashboard',
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
