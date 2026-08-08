<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * SNAPSHOT role → permissions (2026-08-03) — nguồn sự thật duy nhất.
 * Chạy CUỐI DatabaseSeeder để đè lên các sync trước đó. Dùng sync() (replace) — không phải
 * syncWithoutDetaching — để đảm bảo state khớp 100%.
 *
 * Sửa role/perm: cập nhật MẢNG dưới đây, không sửa rải rác nhiều seeder khác.
 */
class RolePermissionSyncSeeder extends Seeder
{
    public const MATRIX = [
        'Admin' => [
            'connection.manage', 'contribution.set', 'field.approve', 'field.manage',
            'lead.approve_source', 'lead.assign_direct',
            'lead.book_action', 'lead.consult', 'lead.create', 'lead.delete',
            'lead.distribute', 'lead.distribute_sale', 'lead.distribute_tele',
            'lead.distribute_to_sale', 'lead.distribute_to_team', 'lead.export',
            'lead.import', 'lead.pull_pool', 'lead.read_booking', 'lead.recall',
            'lead.source_all',
            'lead.update', 'lead.update_booking', 'lead.update_sale', 'lead.view',
            'lead.view_phone', 'lead.view_pool', 'ops.manage', 'org.manage',
            'payment.record', 'phase.close.booking', 'phase.close.call',
            'phase.close.checkin', 'phase.close.new',
            'phase.rollback', 'report.view', 'report.view_all', 'role.manage',
            'rule.manage', 'service.manage', 'source.up.admin', 'source.up.sa',
            'source.up.sale', 'source.up.tele', 'source.up.trucpage', 'staff.manage',
            'system.backup',
            'ups.checkin', 'ups.confirm_daily', 'ups.override', 'ups.view',
            'user.manage',
        ],
        'DM HCM' => [
            'contribution.set', 'field.approve', 'field.manage', 'lead.approve_source',
            'lead.assign_direct',
            'lead.create', 'lead.delete', 'lead.distribute',
            'lead.distribute_sale', 'lead.distribute_tele', 'lead.distribute_to_sale',
            'lead.distribute_to_team', 'lead.export', 'lead.import', 'lead.read_booking',
            'lead.recall', 'lead.update', 'lead.update_booking', 'lead.update_sale',
            'lead.view', 'lead.view_phone', 'lead.view_pool', 'payment.record',
            'phase.close.booking', 'phase.close.call', 'phase.close.checkin',
            'phase.close.new', 'report.view', 'report.view_all',
            'rule.manage', 'service.manage', 'source.up.admin', 'source.up.sa',
            'source.up.sale', 'source.up.tele', 'source.up.trucpage', 'user.manage',
        ],
        'Manager' => [
            'lead.approve_source', 'lead.assign_direct',
            'lead.create', 'lead.distribute',
            'lead.distribute_sale', 'lead.distribute_tele', 'lead.distribute_to_sale',
            'lead.distribute_to_team', 'lead.read_booking', 'lead.recall', 'lead.update',
            'lead.update_booking', 'lead.update_sale', 'lead.view', 'lead.view_phone',
            'lead.view_pool', 'phase.close.booking', 'phase.close.call',
            'phase.close.checkin', 'phase.close.new',
            'report.view', 'source.up.sa', 'source.up.sale',
        ],
        'Admin cơ sở' => [
            'lead.book_action', 'lead.create', 'lead.delete', 'lead.distribute',
            'lead.distribute_sale', 'lead.distribute_tele', 'lead.distribute_to_sale',
            'lead.distribute_to_team', 'lead.import', 'lead.read_booking', 'lead.recall',
            'lead.update_booking', 'lead.view', 'lead.view_phone', 'lead.view_pool',
            'phase.close.new', 'phase.close.booking', 'phase.close.call', 'phase.close.checkin',
            'report.view', 'source.up.admin', 'source.up.sa',
            'ups.view',
        ],
        'CM sale' => [
            'lead.assign_direct',
            'lead.consult', 'lead.create', 'lead.distribute',
            // 2026-08-07: CM có CẢ 2 quyền chia số (tele + tiếp đón). Trước đây tách CM sale / CM Tele
            // mỗi role 1 quyền — giờ gộp lại: ai là CM thì chia được cả hai luồng.
            'lead.distribute_sale', 'lead.distribute_tele',
            'lead.distribute_to_sale', 'lead.recall',
            'lead.update', 'lead.update_sale', 'lead.update_booking', 'lead.view', 'lead.view_phone', 'lead.view_pool',
            'lead.read_booking',
            'payment.record', 'phase.close.booking', 'phase.close.call',
            'phase.close.new', 'report.view',
            'source.up.admin', 'source.up.sa', 'source.up.sale',
        ],
        'CM Tele' => [
            'lead.assign_direct',
            'lead.create', 'lead.distribute',
            // 2026-08-07: CM Tele cũng có luôn distribute_sale — nhất quán với 'CM sale'.
            'lead.distribute_tele', 'lead.distribute_sale',
            'lead.distribute_to_sale', 'lead.read_booking', 'lead.recall', 'lead.update',
            'lead.update_booking', 'lead.view', 'lead.view_phone', 'lead.view_pool',
            'payment.record', 'phase.close.call',
            'phase.close.new', 'report.view', 'source.up.tele',
        ],
        'Team Leader' => [
            'lead.approve_source', 'lead.create', 'lead.distribute',
            'lead.distribute_sale', 'lead.distribute_tele', 'lead.distribute_to_sale',
            'lead.read_booking', 'lead.recall', 'lead.update', 'lead.view',
            'lead.view_phone', 'lead.view_pool', 'payment.record',
            'phase.close.booking', 'phase.close.call',
            'phase.close.new', 'report.view', 'source.up.sa', 'source.up.sale',
        ],
        'Sale' => [
            'lead.consult', 'lead.create', 'lead.update',
            // 2026-08-05: thêm update_booking + read_booking + book_action — Sale nhận lead MKT qua UPS bucket
            // (owner = sale, pipeline_phase=booking) phải sửa info + đặt booking được, không chỉ Team Tele.
            'lead.update_sale', 'lead.update_booking', 'lead.read_booking', 'lead.book_action',
            'lead.view', 'payment.record', 'phase.close.booking',
            'phase.close.call', 'phase.close.new', 'report.view', 'source.up.sa',
            'source.up.sale',
        ],
        'Team sale' => [
            'lead.consult', 'lead.update', 'lead.update_sale',
            // 2026-08-05: cùng lý do như Sale — Team sale được UPS chia lead MKT (owner + phase booking).
            'lead.update_booking', 'lead.read_booking', 'lead.book_action',
            'lead.view', 'lead.view_phone', 'payment.record', 'phase.close.booking',
            'phase.close.call', 'phase.close.new',
            'report.view', 'source.up.sa', 'source.up.sale',
        ],
        'Team sale ĐN' => [
            // 2026-08-07: Team Linda ĐN — xuyên suốt tele+booking+sale. Thêm book_action để tự
            // đặt booking (trước bị thiếu, không tạo được booking được).
            'lead.book_action',
            'lead.consult', 'lead.create', 'lead.read_booking',
            'lead.update', 'lead.update_booking', 'lead.update_sale', 'lead.view',
            'lead.view_phone', 'payment.record', 'phase.close.booking',
            'phase.close.call', 'phase.close.new',
            'report.view', 'source.up.sa', 'source.up.sale',
        ],
        'Team Tele' => [
            'lead.create', 'lead.read_booking', 'lead.update',
            'lead.update_booking', 'lead.view', 'lead.view_phone', 'phase.close.call',
            'phase.close.new', 'source.up.tele',
        ],
        'Trực Page' => [
            'lead.create', 'lead.import', 'lead.view', 'phase.close.new',
            'source.up.trucpage',
        ],
        'Trợ lý kinh doanh' => [
            'lead.view', 'report.view',
        ],
        'BO (Lễ Tân)' => [
            // 2026-08-08: BO chỉ dùng UPS list — bỏ 3 perm lead.* (không cần Dashboard/Khách hàng/Thiết lập).
            'ups.checkin', 'ups.confirm_daily', 'ups.override', 'ups.view',
        ],
        'Observer' => [
            // 2026-08-08: thêm view_pool để Observer xem được kho số (kho chung/team chưa chia).
            'lead.export', 'lead.view', 'lead.view_phone', 'lead.view_pool',
            'report.view', 'report.view_all',
        ],
    ];

    public function run(): void
    {
        foreach (self::MATRIX as $roleName => $permKeys) {
            $role = Role::firstOrCreate(['name' => $roleName]);
            $permIds = Permission::whereIn('key', $permKeys)->pluck('id')->all();
            if (count($permIds) < count($permKeys)) {
                $missing = array_diff($permKeys, Permission::whereIn('key', $permKeys)->pluck('key')->all());
                $this->command?->warn("Role '{$roleName}' thiếu perm chưa seed: " . implode(', ', $missing));
            }
            $role->permissions()->sync($permIds);
        }
    }
}
