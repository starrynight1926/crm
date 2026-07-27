<?php

use App\Models\OrgUnit;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Chuẩn hoá username + email cho toàn bộ tài khoản theo pattern
 *     {branch}{role}.{id}@longevity.com.vn
 *
 * Trong đó:
 *   branch: hn | hcm | dn | ops | company (mặc định 'company' nếu không xác định được cơ sở)
 *   role:   admin | mgr | dm | cm | tl | booking | sale | page | obs | asst | user
 *
 * Nếu người dùng có nhiều assignment, lấy assignment có vai trò cấp cao nhất.
 * Người dùng không có assignment nào được giữ nguyên username/email cũ.
 *
 * Migration ghi lại mapping cũ → mới vào bảng audit_logs để có thể tra cứu về sau.
 */
return new class extends Migration
{
    private const ROLE_CODE = [
        'Admin' => 'admin',
        'Manager' => 'mgr',
        'DM HCM' => 'dm',
        'CM sale' => 'cm',
        'CM booking' => 'cm',
        'Team Leader' => 'tl',
        'Team booking' => 'booking',
        'Team sale' => 'sale',
        'Team sale ĐN' => 'sale',
        'Sale' => 'sale',
        'Team trực page' => 'page',
        'Observer' => 'obs',
        'Trợ lý kinh doanh' => 'asst',
    ];

    private const RANK = [
        'Admin' => 0, 'Manager' => 0, 'DM HCM' => 0,
        'CM sale' => 1, 'CM booking' => 1,
        'Team Leader' => 2,
        'Observer' => 9,
    ];

    public function up(): void
    {
        // Map id branch → code cơ sở
        $branchCodeById = [];
        foreach (OrgUnit::where('depth', 1)->get() as $b) {
            $branchCodeById[$b->id] = match ($b->code) {
                'branch-hn' => 'hn',
                'branch-hcm' => 'hcm',
                'branch-dn' => 'dn',
                'ops-monitor' => 'ops',
                default => preg_replace('/^branch-/', '', $b->code),
            };
        }

        $mapping = [];

        DB::transaction(function () use ($branchCodeById, &$mapping) {
            $users = User::with(['assignments' => fn ($q) => $q->effective()->with('role', 'orgUnit')])
                ->orderBy('id')->get();

            foreach ($users as $u) {
                if ($u->assignments->isEmpty()) {
                    continue;
                }

                // Chọn assignment có role rank thấp nhất (cấp cao nhất)
                $best = $u->assignments->sortBy(fn ($a) => self::RANK[$a->role?->name ?? ''] ?? 3)->first();

                // Xác định branch từ path org_unit
                $segs = array_values(array_filter(explode('/', (string) $best->orgUnit->path)));
                $branchId = isset($segs[1]) ? (int) $segs[1] : null;
                $branchCode = $branchCodeById[$branchId] ?? 'company';

                $roleCode = self::ROLE_CODE[$best->role?->name] ?? 'user';
                $newUsername = $branchCode . $roleCode . '.' . $u->id;
                $newEmail = $newUsername . '@longevity.com.vn';

                if ($u->username === $newUsername && $u->email === $newEmail) {
                    continue;
                }

                $mapping[] = [
                    'id' => $u->id,
                    'name' => $u->name,
                    'old_username' => $u->username,
                    'old_email' => $u->email,
                    'new_username' => $newUsername,
                    'new_email' => $newEmail,
                ];

                DB::table('users')->where('id', $u->id)->update([
                    'username' => $newUsername,
                    'email' => $newEmail,
                    'updated_at' => now(),
                ]);
            }
        });

        if ($mapping) {
            DB::table('audit_logs')->insert([
                'user_id' => null,
                'action' => 'normalize_usernames',
                'entity_type' => 'User',
                'entity_id' => null,
                'meta' => json_encode(['count' => count($mapping), 'mapping' => $mapping], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Không tự khôi phục — mapping cũ được lưu trong audit_logs với action='normalize_usernames'.
    }
};
