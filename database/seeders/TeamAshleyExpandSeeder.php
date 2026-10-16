<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\User;
use App\Support\DefaultPassword;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * 2026-10-01 — Bổ sung 4 sale cho Team Ashley HCM.
 *
 *   - Nguyễn Ngọc Minh Cẩm (HC)
 *   - Bùi Lê Thanh Tâm     (HC)
 *   - Nguyễn Minh Đạt      (SHC)
 *   - Nguyễn Thành Phát    (HC)
 *
 * Dù danh sách gốc ghi "137 NCT" (nơi làm việc) — org tree chưa có nhánh cho 137 NCT,
 * nên tạm gán vào team-ashley-sale (207 NVT) theo chỉ đạo. Pool/cơ sở lead vẫn 207 NVT.
 *
 * Password mặc định: 207@nvt (hằng số DefaultPassword::HCM).
 * Idempotent — chạy lại không nhân đôi user hay assignment.
 */
class TeamAshleyExpandSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            ['email' => 'nnmc@longevity.com.vn', 'name' => 'Nguyễn Ngọc Minh Cẩm', 'job_title' => 'HC'],
            ['email' => 'bltt@longevity.com.vn', 'name' => 'Bùi Lê Thanh Tâm',     'job_title' => 'HC'],
            ['email' => 'nmd@longevity.com.vn',  'name' => 'Nguyễn Minh Đạt',      'job_title' => 'SHC'],
            ['email' => 'ntp@longevity.com.vn',  'name' => 'Nguyễn Thành Phát',    'job_title' => 'HC'],
        ];

        $role = Role::firstWhere('name', 'Sale');
        $org  = OrgUnit::firstWhere('code', 'team-ashley-sale');
        if (! $role || ! $org) {
            $this->command?->error('TeamAshleyExpandSeeder: thiếu role "Sale" hoặc org "team-ashley-sale" — chạy OrgStaffSeeder trước.');
            return;
        }

        foreach ($users as $u) {
            $existing = User::firstWhere('email', $u['email'])
                ?? User::firstWhere('name', $u['name']);

            if (! $existing) {
                $user = User::create([
                    'name'      => $u['name'],
                    'email'     => $u['email'],
                    'job_title' => $u['job_title'],
                    'password'  => Hash::make(DefaultPassword::HCM),
                    'status'    => User::STATUS_ACTIVE,
                ]);
                $this->command?->info("Tạo user: {$u['name']} <{$u['email']}>");
            } else {
                $existing->update([
                    'name'      => $u['name'],
                    'job_title' => $u['job_title'],
                    'status'    => User::STATUS_ACTIVE,
                ]);
                $user = $existing;
                $this->command?->line("Giữ user: {$user->name} <{$user->email}> (không đụng password)");
            }

            $assignment = Assignment::firstOrNew([
                'user_id'     => $user->id,
                'role_id'     => $role->id,
                'org_unit_id' => $org->id,
            ]);
            $assignment->data_scope = Assignment::SCOPE_SELF;
            $assignment->save();
            $assignment->scopeNodes()->sync([]);
        }

        $this->command?->info('TeamAshleyExpandSeeder: xong 4 sale Team Ashley HCM.');
    }
}
