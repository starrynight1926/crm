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
            'lead.approve_source', 'lead.book_action', 'lead.consult', 'lead.create', 'lead.delete',
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
            'lead.approve_source', 'lead.create', 'lead.distribute',
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
            'lead.consult', 'lead.create', 'lead.distribute',
            'lead.distribute_sale', 'lead.distribute_to_sale', 'lead.recall',
            'lead.update', 'lead.update_sale', 'lead.view', 'lead.view_phone',
            'payment.record', 'phase.close.booking', 'phase.close.call',
            'phase.close.new', 'report.view',
            'source.up.admin', 'source.up.sa', 'source.up.sale',
        ],
        'CM Tele' => [
            'lead.create', 'lead.distribute', 'lead.distribute_tele',
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
            'lead.update_sale', 'lead.view', 'payment.record', 'phase.close.booking',
            'phase.close.call', 'phase.close.new', 'report.view', 'source.up.sa',
            'source.up.sale',
        ],
        'Team sale' => [
            'lead.consult', 'lead.update', 'lead.update_sale',
            'lead.view', 'lead.view_phone', 'payment.record', 'phase.close.booking',
            'phase.close.call', 'phase.close.new',
            'report.view', 'source.up.sa', 'source.up.sale',
        ],
        'Team sale ĐN' => [
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
            'lead.distribute_sale', 'lead.view', 'lead.view_phone',
            'ups.checkin', 'ups.confirm_daily', 'ups.override', 'ups.view',
        ],
        'Observer' => [
            'lead.export', 'lead.view', 'lead.view_phone', 'report.view',
            'report.view_all',
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
