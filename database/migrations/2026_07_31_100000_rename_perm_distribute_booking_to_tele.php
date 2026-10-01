<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rebrand: lead.distribute_booking → lead.distribute_tele
 * Team booking cũ đã được đổi tên thành "tele" (xem 2026_07_30_160000_rename_org_team_booking_to_tele.php);
 * perm chia số ở kho đó cũng đổi theo cho nhất quán.
 * Chỉ đổi key + label; giữ nguyên id + toàn bộ liên kết permission_role.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('permissions')
            ->where('key', 'lead.distribute_booking')
            ->update([
                'key'   => 'lead.distribute_tele',
                'label' => 'Chia số ở kho Tele (CM team tele)',
            ]);
    }

    public function down(): void
    {
        DB::table('permissions')
            ->where('key', 'lead.distribute_tele')
            ->update([
                'key'   => 'lead.distribute_booking',
                'label' => 'Chia số ở kho Booking (CM team booking)',
            ]);
    }
};
