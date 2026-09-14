<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Xoá hẳn permission `lead.distribute_team` (deprecated 2026-07-19) khỏi hệ thống.
 * Đã không còn code check key này; các role đều đã migrate sang distribute_booking/distribute_sale.
 */
return new class extends Migration {
    public function up(): void
    {
        $id = Permission::where('key', 'lead.distribute_team')->value('id');
        if (! $id) return;
        DB::table('permission_role')->where('permission_id', $id)->delete();
        Permission::where('id', $id)->delete();
    }

    public function down(): void
    {
        Permission::updateOrCreate(
            ['key' => 'lead.distribute_team'],
            [
                'label' => '[DEPRECATED] Chia số cho team — thay bằng distribute_booking/distribute_sale',
                'group' => 'distribution',
                'position' => 100,
            ]
        );
    }
};
