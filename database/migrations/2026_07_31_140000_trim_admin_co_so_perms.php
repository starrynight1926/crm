<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

// Trim role "Admin cơ sở" theo yêu cầu 2026-07-31:
//   Chỉ giữ quyền xem + chia số phase 2 + phase 4 + báo cáo.
// BỎ: create, update, import, export, source.up.trucpage/sale/tele/admin,
//     source_all, close.new/call/checkin, update_sale, payment, approve_source,
//     delete + phase.close.distribute (P2 tự close khi lead có owner).
// GIỮ (14 perm): lead.view + view_phone + view_pool + distribute +
//     distribute_tele + distribute_sale + distribute_to_team + distribute_to_sale +
//     recall + book_action + update_booking + read_booking + phase.close.booking +
//     report.view.
return new class extends Migration {
    private const KEEP = [
        'lead.view', 'lead.view_phone',
        'lead.view_pool', 'lead.distribute',
        'lead.distribute_tele', 'lead.distribute_sale',
        'lead.distribute_to_team', 'lead.distribute_to_sale', 'lead.recall',
        'lead.book_action', 'lead.update_booking', 'lead.read_booking',
        'phase.close.booking',
        'report.view',
    ];

    public function up(): void
    {
        $role = Role::firstWhere('name', 'Admin cơ sở');
        if (! $role) return;
        $keepIds = Permission::whereIn('key', self::KEEP)->pluck('id')->all();
        $role->permissions()->sync($keepIds);
    }

    public function down(): void
    {
        // Không rollback — perm cũ quá rộng, không phù hợp production.
    }
};
