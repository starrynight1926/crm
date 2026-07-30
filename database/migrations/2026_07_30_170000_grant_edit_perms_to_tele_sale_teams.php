<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

/**
 * Phase 6.21g (2026-07-30) — cấp quyền edit info khách + book_action cho Tele/Sale.
 *
 * Bối cảnh (Customer Flow 7 phase mới):
 *   Phase 3 (Gọi điện) → Tele xử lý → cần sửa info + đặt booking hộ nếu cần.
 *   Phase 4 (Booking) → Sale (CM chỉ định) → cần sửa info + đặt booking.
 *
 * Nhưng gate permission cũ (dựa pipeline_phase legacy) yêu cầu:
 *   lead.update_booking khi pipeline_phase=booking  → Tele cần cấp
 *   lead.update_sale    khi pipeline_phase=sale     → Sale cần cấp
 *   lead.book_action    để bấm nút Đặt booking      → cả Tele và Sale cần
 */
return new class extends Migration
{
    public function up(): void
    {
        $matrix = [
            'Team Tele'    => ['lead.update_booking'], // book_action đã có sẵn
            'Team sale'    => ['lead.update_sale', 'lead.book_action'],
            'Team sale ĐN' => ['lead.update_sale', 'lead.book_action'],
            'Sale'         => ['lead.update_sale', 'lead.book_action'],
        ];

        foreach ($matrix as $roleName => $permKeys) {
            $role = Role::where('name', $roleName)->first();
            if (! $role) continue;
            $permIds = Permission::whereIn('key', $permKeys)->pluck('id')->all();
            if ($permIds) {
                $role->permissions()->syncWithoutDetaching($permIds);
            }
        }
    }

    public function down(): void
    {
        $matrix = [
            'Team Tele'    => ['lead.update_booking'],
            'Team sale'    => ['lead.update_sale', 'lead.book_action'],
            'Team sale ĐN' => ['lead.update_sale', 'lead.book_action'],
            'Sale'         => ['lead.update_sale', 'lead.book_action'],
        ];
        foreach ($matrix as $roleName => $permKeys) {
            $role = Role::where('name', $roleName)->first();
            if (! $role) continue;
            $permIds = Permission::whereIn('key', $permKeys)->pluck('id')->all();
            if ($permIds) {
                $role->permissions()->detach($permIds);
            }
        }
    }
};
