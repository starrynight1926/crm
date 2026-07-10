<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Nhân viên sale của Team Hợi. Email = tên viết tắt (initials, bỏ dấu) + id.
 * Vai trò Sale, gán vào Team Hợi, data_scope = self. Idempotent theo họ tên.
 */
class TeamHoiStaffSeeder extends Seeder
{
    private const NAMES = [
        'Trần Thị Thu Giang',
        'Nguyễn Hương Giang',
        'Nguyễn Trà My',
        'Cao Lan Anh',
        'Nguyễn Thị Nga',
        'Nguyễn Thị Thuý',
        'Tạ Văn Hợi',
        'Nguyễn Hoành Đức',
        'Nguyễn Khánh Quỳnh',
    ];

    public function run(): void
    {
        $sale = Role::firstWhere('name', 'Sale');
        $team = OrgUnit::firstWhere('code', 'team-hoi');
        if (! $sale || ! $team) {
            $this->command?->error('Thiếu role Sale hoặc Team Hợi. Chạy OrgAndRoleSeeder + TeamHoiCustomFieldSeeder trước.');
            return;
        }

        foreach (self::NAMES as $full) {
            $user = User::firstWhere('name', $full);
            if (! $user) {
                $user = User::create([
                    'name' => $full,
                    'email' => 'tmp_' . Str::random(12) . '@placeholder.local',
                    'password' => '123456',
                    'status' => User::STATUS_ACTIVE,
                ]);
                $user->update(['email' => $this->initials($full) . $user->id . '@longevity.com.vn']);
            }

            if (! Assignment::where('user_id', $user->id)->exists()) {
                Assignment::create([
                    'user_id' => $user->id,
                    'role_id' => $sale->id,
                    'org_unit_id' => $team->id,
                    'data_scope' => Assignment::SCOPE_SELF,
                ]);
            }
        }

        $this->command?->info('Seeded ' . count(self::NAMES) . ' nhân viên sale vào Team Hợi.');
    }

    /** Chữ cái đầu mỗi từ (bỏ dấu, viết thường): "Tạ Văn Hợi" → "tvh". */
    private function initials(string $name): string
    {
        $words = preg_split('/\s+/', trim(Str::ascii($name)));
        return strtolower(implode('', array_map(fn ($w) => substr($w, 0, 1), $words)));
    }
}
