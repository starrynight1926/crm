<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

/**
 * Chỉnh mapping role ↔ permission cho khớp flow mới (bảng chia số theo 7 nguồn):
 *
 * 1) Trực Page: gỡ 3 perm chia số. Trực Page chỉ up lead vào kho cơ sở,
 *    CM/Admin cơ sở mới là người kéo về kho team tele.
 * 2) Team Tele: thêm lead.create (Tele phải tạo được lead nguồn BA — ưu tiên 1
 *    trong bảng flow).
 * 3) CM sale: thêm lead.book_action + source.up.admin. CM sale kiêm sale trực
 *    tiếp cho nguồn SA/WI/BDM/BOD → phải tự up (source.up.admin) + tự đặt lịch
 *    (lead.book_action) cho khách sale.
 */
return new class extends Migration {
    private const DETACH = [
        'Trực Page' => ['lead.distribute_tele', 'lead.distribute_to_team', 'lead.distribute_to_sale'],
    ];

    private const ATTACH = [
        // Phase C1.b rev11 2026-08-02: cấp đủ phase.close.* cho các team tự tạo lead
        // (nguồn direct-sale: SA/BA/BOD/WI). Bulk mode mở 1→startPhase → cần close cả cụm.
        'Team Tele'    => ['lead.create', 'phase.close.new', 'phase.close.distribute'],
        'Team sale'    => ['phase.close.distribute'],
        'Team sale ĐN' => ['phase.close.new', 'phase.close.distribute', 'phase.close.call', 'phase.close.booking'],
        'CM sale'      => ['lead.book_action', 'source.up.admin', 'phase.close.new', 'phase.close.call'],
        'CM Tele'      => ['phase.close.new'],
        'Trực Page'    => ['phase.close.new'],
    ];

    public function up(): void
    {
        foreach (self::DETACH as $roleName => $keys) {
            $role = Role::firstWhere('name', $roleName);
            if (! $role) continue;
            $permIds = Permission::whereIn('key', $keys)->pluck('id')->all();
            if ($permIds) $role->permissions()->detach($permIds);
        }

        foreach (self::ATTACH as $roleName => $keys) {
            $role = Role::firstWhere('name', $roleName);
            if (! $role) continue;
            $permIds = Permission::whereIn('key', $keys)->pluck('id')->all();
            if ($permIds) $role->permissions()->syncWithoutDetaching($permIds);
        }
    }

    public function down(): void
    {
        // Đảo lại: gắn lại 3 perm cho Trực Page, gỡ những perm vừa attach.
        foreach (self::DETACH as $roleName => $keys) {
            $role = Role::firstWhere('name', $roleName);
            if (! $role) continue;
            $permIds = Permission::whereIn('key', $keys)->pluck('id')->all();
            if ($permIds) $role->permissions()->syncWithoutDetaching($permIds);
        }

        foreach (self::ATTACH as $roleName => $keys) {
            $role = Role::firstWhere('name', $roleName);
            if (! $role) continue;
            $permIds = Permission::whereIn('key', $keys)->pluck('id')->all();
            if ($permIds) $role->permissions()->detach($permIds);
        }
    }
};
