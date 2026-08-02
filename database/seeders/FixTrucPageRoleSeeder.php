<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * FIX 2026-08-03 — trên host fresh, OrgStaffSeeder tạo role "Team nhập lead" mới
 * (không có perm) trong khi migration đã rename Team nhập lead → Trực Page trước đó.
 * Kết quả: hn.page01 gán role trắng, dropdown nguồn khoá.
 *
 * Seeder này:
 *   1) Migrate assignments đang trỏ Team nhập lead → Trực Page.
 *   2) Xoá role Team nhập lead nếu không còn assignment nào.
 *
 * Idempotent — chạy lại lần 2 không sao.
 */
class FixTrucPageRoleSeeder extends Seeder
{
    public function run(): void
    {
        $oldRole = Role::firstWhere('name', 'Team nhập lead');
        if (! $oldRole) return;

        $newRole = Role::firstOrCreate(['name' => 'Trực Page']);

        $moved = Assignment::where('role_id', $oldRole->id)
            ->update(['role_id' => $newRole->id]);
        $this->command?->info("Đã chuyển {$moved} assignment từ 'Team nhập lead' → 'Trực Page'.");

        // Detach perm cũ + xóa role rác nếu không còn ai dùng.
        $stillUsed = Assignment::where('role_id', $oldRole->id)->exists();
        if (! $stillUsed) {
            DB::table('permission_role')->where('role_id', $oldRole->id)->delete();
            $oldRole->delete();
            $this->command?->info("Đã xoá role rác 'Team nhập lead'.");
        }
    }
}
