<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

/**
 * Phase C1.b rev11 (2026-08-02) — cấp phase.close.* cho các role team tự tạo lead:
 *   Bulk mode mở 1→startPhase → phải close được cả cụm.
 * Đã cập nhật ATTACH của migration 120000; đây là bổ sung idempotent cho DB đã chạy trước rev11.
 */
return new class extends Migration {
    private const ATTACH = [
        'Team Tele'    => ['phase.close.new', 'phase.close.distribute'],
        'Team sale'    => ['phase.close.distribute'],
        'Team sale ĐN' => ['phase.close.new', 'phase.close.distribute', 'phase.close.call', 'phase.close.booking'],
        'CM sale'      => ['phase.close.new', 'phase.close.call'],
        'CM Tele'      => ['phase.close.new'],
        'Trực Page'    => ['phase.close.new'],
    ];

    public function up(): void
    {
        foreach (self::ATTACH as $roleName => $keys) {
            $role = Role::firstWhere('name', $roleName);
            if (! $role) continue;
            $permIds = Permission::whereIn('key', $keys)->pluck('id')->all();
            if ($permIds) $role->permissions()->syncWithoutDetaching($permIds);
        }
    }

    public function down(): void
    {
        // No-op: khôi phục về pre-rev11 rủi ro cho flow.
    }
};
