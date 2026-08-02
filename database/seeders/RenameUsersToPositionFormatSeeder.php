<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\OrgUnit;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Đổi username + email của user CRM sang format vị trí: <cơ_sở>.<chức_vụ><nn>.
 * Ví dụ: hn.sale01, hcm.cmb02, dn.page01, vh.nvvh01.
 *
 * Idempotent: user đã có username khớp format mới → skip.
 * KHÔNG đụng:
 *   - Admin chính (id=1, username 'admin')
 *   - User không có assignment CRM (BS/KTV/ĐD bên sbooking sync sang)
 *   - User có role/branch không nằm trong map (giữ nguyên, để admin xử tay)
 */
class RenameUsersToPositionFormatSeeder extends Seeder
{
    /** Prefix cơ sở theo code OrgUnit depth=1. */
    private const BRANCH_MAP = [
        'branch-hn'   => 'hn',
        'branch-hcm'  => 'hcm',
        'branch-dn'   => 'dn',
        'ops-monitor' => 'vh',
    ];

    /** Prefix chức vụ theo tên Role. */
    private const ROLE_MAP = [
        'CM sale'            => 'cms',
        'CM booking'         => 'cmb',
        'Team Leader'        => 'tl',
        'Sale'               => 'sale',
        'Team sale ĐN'       => 'sale',   // ĐN gộp chung
        'Team Tele'       => 'book',
        'Trực Page'          => 'page',
        'DM HCM'             => 'dm',
        'Observer'           => 'obs',
        'Trợ lý kinh doanh'  => 'tlkd',
        'Nhân viên vận hành' => 'nvvh',
        'Nhân viên giám sát' => 'nvgs',
    ];

    /** Role gán cứng vào branch cụ thể (bỏ qua org thật của assignment). */
    private const ROLE_FORCED_BRANCH = [
        'Trợ lý kinh doanh' => 'hn', // 1 user duy nhất, assign @ company nhưng ngồi ở HN
    ];

    public function run(): void
    {
        // 1) Load tất cả user + first assignment (role + org)
        $users = User::with(['assignments.role', 'assignments.orgUnit'])->orderBy('id')->get();

        // 2) Với mỗi user: tính (branchCode, rolePrefix). Bỏ user không map được.
        $candidates = [];   // [group_key => [user, ...]] để đánh số ổn định
        foreach ($users as $u) {
            if ($u->id === 1) continue; // admin chính giữ nguyên
            $a = $u->assignments->first();
            if (! $a || ! $a->role || ! $a->orgUnit) continue;

            $rolePrefix = self::ROLE_MAP[$a->role->name] ?? null;
            if (! $rolePrefix) continue;

            // Role gán cứng branch (ví dụ Trợ lý kinh doanh @ company → luôn hn)
            $branchPrefix = self::ROLE_FORCED_BRANCH[$a->role->name]
                ?? self::BRANCH_MAP[$this->branchCodeOf($a->orgUnit)] ?? null;
            if (! $branchPrefix) continue;

            $key = "$branchPrefix.$rolePrefix";
            $candidates[$key][] = $u;
        }

        // 3) Trong mỗi group, giữ user đã có format đúng ở vị trí cũ,
        //    còn lại đánh số tuần tự từ 01 (theo id để ổn định).
        $renames = []; // [user_id => new_username]
        foreach ($candidates as $prefix => $group) {
            usort($group, fn (User $a, User $b) => $a->id <=> $b->id);
            $taken = [];       // số đã bị chiếm (giữ nguyên user format sẵn)
            $freshQueue = [];  // user cần đánh số mới

            foreach ($group as $u) {
                if (preg_match('/^' . preg_quote($prefix, '/') . '(\d{2,})$/', (string) $u->username, $m)) {
                    $taken[(int) $m[1]] = $u->id;
                } else {
                    $freshQueue[] = $u;
                }
            }
            $next = 1;
            foreach ($freshQueue as $u) {
                while (isset($taken[$next])) $next++;
                $newUsername = $prefix . str_pad((string) $next, 2, '0', STR_PAD_LEFT);
                $renames[$u->id] = $newUsername;
                $taken[$next] = $u->id;
                $next++;
            }
        }

        if (empty($renames)) {
            $this->command?->info('RenameUsersToPositionFormat: không có user cần đổi.');
            return;
        }

        // 4) Apply. Đổi cả username + email (email = <username>@longevity.com.vn).
        DB::transaction(function () use ($renames) {
            foreach ($renames as $userId => $newUsername) {
                $newEmail = $newUsername . '@longevity.com.vn';
                // Bỏ qua nếu đã trùng (defensive)
                $u = User::find($userId);
                if (! $u) continue;
                if ($u->username === $newUsername && $u->email === $newEmail) continue;

                $u->forceFill([
                    'username' => $newUsername,
                    'email'    => $newEmail,
                ])->save();
            }
        });

        $this->command?->info('RenameUsersToPositionFormat: đã đổi ' . count($renames) . ' user.');
    }

    /** Tìm ancestor cấp depth=1 (cơ sở) của org unit. Fallback: chính nó nếu là root/branch. */
    private function branchCodeOf(OrgUnit $org): ?string
    {
        if ($org->depth === 1) return $org->code;
        $ancestor = OrgUnit::where('depth', 1)
            ->whereRaw('? LIKE CONCAT(path, "%")', [$org->path])
            ->first();
        return $ancestor?->code;
    }
}
