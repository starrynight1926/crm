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
    /**
     * 2026-08-05: MAP CỨNG NAME → USERNAME — đồng bộ 1-1 với sbooking (SyncUsernamesFromCrmSeeder).
     * Fix mismatch: trước dùng auto-number theo user.id thì lệch số so với sbooking (mapping cứng bên đó).
     * Kết quả: cùng người → cùng username ở cả 2 hệ → sbooking_user_id map đúng qua username.
     * Khi thêm nhân sự mới: cập nhật cả 2 file (scrm + sbooking) với 1 dòng khớp nhau.
     */
    private const NAME_TO_USERNAME = [
        // ---- Đà Nẵng ----
        'Lương Thị Kim Phấn'        => 'dn.cms01',
        'Tài khoản Trực Page cơ sở ĐN' => 'dn.page01',
        'Nguyễn Thị Ánh Nhung'      => 'dn.sale01',
        'Lê Thị Hoàng Uyên'         => 'dn.sale02',
        'Lương Thị Kim Hiếu'        => 'dn.sale03',
        'Sử Trung Kiên'             => 'dn.sale04',
        'Lương Thị Tường Vy'        => 'dn.sale05',
        'Trần Ngọc An Hoà'          => 'dn.sale06',
        'Nguyễn Thị Mỹ Hạnh'        => 'dn.sale07',
        'Nguyễn Thị Bông'           => 'dn.tl01',

        // ---- HCM ----
        'CM Booking Team Ashley'    => 'hcm.cmb01',
        'Trần Thị Bích Trâm'        => 'hcm.cms01',
        'Nguyễn Thị Minh Thư'       => 'hcm.cms02',
        'Huỳnh Bùi Thanh Lan'       => 'hcm.cms03',
        'Trần Nguyễn Kim Ngân'      => 'hcm.dm01',
        'Tài khoản Trực Page cơ sở HCM' => 'hcm.page01',
        'Trương Thị Yến Nhi'        => 'hcm.sale01',
        'Nguyễn Thị Hoài Như'       => 'hcm.sale02',
        'Huỳnh Thị My My'           => 'hcm.sale03',
        'Nguyễn Thị Thanh'          => 'hcm.sale04',
        'Nguyễn Thị Kim Chi'        => 'hcm.sale05',
        'Lê Phát Đạt'               => 'hcm.sale06',

        // ---- Hà Nội ----
        'Tài khoản Booking 1'       => 'hn.book01',
        'Tài khoản Booking 2'       => 'hn.book02',
        'CM Booking'                => 'hn.cmb01',
        'CM Booking Team Giang'     => 'hn.cmb02',
        'Trần Thị Thu Giang'        => 'hn.cms01',
        'Tạ Văn Hợi'                => 'hn.cms02',
        'CM Sale'                   => 'hn.cms03',
        'CM Sale Team Giang'        => 'hn.cms04',
        'Tài khoản Trực Page cơ sở HN' => 'hn.page01',
        'Trần Huy Kiên'             => 'hn.sale03',
        'Nguyễn Hương Giang'        => 'hn.sale04',
        'Nguyễn Minh Phương'        => 'hn.sale05',
        'Nguyễn Thị Anh'            => 'hn.sale06',
        'Nguyễn Thị Nga'            => 'hn.sale07',
        'Cao Thị Lan Anh'           => 'hn.sale08',
        'Phạm Thanh Trúc'           => 'hn.sale09',
        'Nguyễn Thị Thúy'           => 'hn.sale10',
        'Phạm Tú Anh'               => 'hn.sale11',
        'Nguyễn Trà My'             => 'hn.sale12',
        'Nguyễn Mai Anh'            => 'hn.sale13',
        'Nguyễn Hoành Đức'          => 'hn.tl01',
        // 2026-08-26: Quỳnh base @ HN (team-quynh), assignment phụ TL @ team-ashley (HCM).
        'Phan Trần Khánh Quỳnh'     => 'hn.tl02',
        'Lê Thị Phương Tự'          => 'hn.tlkd01',

        // ---- Vận hành & Giám sát (Observer) ----
        'Huyền' => 'vh.obs01',
        'Hằng'  => 'vh.obs02',
        'Ly'    => 'vh.obs03',
        'An'    => 'vh.obs04',
        'Tuyết' => 'vh.obs05',
    ];

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

        // 1.5) 2026-08-05: PASS 1 — apply mapping cứng NAME → USERNAME (đồng bộ với sbooking).
        //      Đây là source of truth cho username; auto-number chỉ chạy cho user không có mapping.
        $renames = [];
        $mappedUserIds = [];
        foreach ($users as $u) {
            if ($u->id === 1) continue;
            $target = self::NAME_TO_USERNAME[trim((string) $u->name)] ?? null;
            if ($target) {
                if ($u->username !== $target) $renames[$u->id] = $target;
                $mappedUserIds[$u->id] = true;
            }
        }

        // 2) Với mỗi user: tính (branchCode, rolePrefix). Bỏ user không map được.
        $candidates = [];   // [group_key => [user, ...]] để đánh số ổn định
        foreach ($users as $u) {
            if ($u->id === 1) continue; // admin chính giữ nguyên
            if (isset($mappedUserIds[$u->id])) continue; // đã có mapping cứng, không auto-number
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
        //    $renames đã init ở PASS 1 (mapping cứng); PASS 2 chỉ merge thêm.
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
        //    2026-08-26: Tự xử duplicate — nếu slot đích đang bị 1 user KHÁC (id cũ hơn) chiếm
        //    (thường do sync lại từ sbooking sinh ra bản mới cùng tên), thì:
        //      - chuyển FK org_unit_managers của user cũ → user mới (bỏ nếu trùng),
        //      - xoá user cũ,
        //      - rồi rename user mới.
        //    Rule user chốt: LUÔN giữ bản mới (id lớn hơn), xoá bản cũ.
        DB::transaction(function () use ($renames) {
            foreach ($renames as $userId => $newUsername) {
                $newEmail = $newUsername . '@longevity.com.vn';
                $u = User::find($userId);
                if (! $u) continue;
                if ($u->username === $newUsername && $u->email === $newEmail) continue;

                $conflict = User::where('id', '!=', $userId)
                    ->where(fn ($q) => $q->where('username', $newUsername)->orWhere('email', $newEmail))
                    ->first();
                if ($conflict) {
                    $this->resolveDuplicate($conflict, $userId, $newUsername);
                }

                $u->forceFill([
                    'username' => $newUsername,
                    'email'    => $newEmail,
                    // Reset password theo email mới (bug fix 2026-08-28):
                    // OrgStaffSeeder tạo user với email tạm (ntan@, tyn@…) → forEmail fallback HN.
                    // Sau khi email chuyển sang dn.*/hcm.*/hn.* thì password phải khớp cơ sở.
                    'password' => \App\Support\DefaultPassword::forEmail($newEmail),
                ])->save();
            }
        });

        $this->command?->info('RenameUsersToPositionFormat: đã đổi ' . count($renames) . ' user.');
    }

    /**
     * 2026-08-26: Bản cũ chiếm slot username → dời FK sang user mới rồi xoá.
     * Hiện chỉ có 1 pivot ref thực tế là org_unit_managers (đã scan toàn DB).
     * Nếu về sau có thêm bảng ref user_id, bổ sung tại đây.
     */
    private function resolveDuplicate(User $old, int $newUserId, string $targetUsername): void
    {
        $rows = DB::table('org_unit_managers')->where('user_id', $old->id)->get();
        foreach ($rows as $r) {
            $dup = DB::table('org_unit_managers')
                ->where('user_id', $newUserId)
                ->where('org_unit_id', $r->org_unit_id)
                ->exists();
            if ($dup) {
                DB::table('org_unit_managers')
                    ->where('user_id', $old->id)
                    ->where('org_unit_id', $r->org_unit_id)
                    ->delete();
            } else {
                DB::table('org_unit_managers')
                    ->where('user_id', $old->id)
                    ->where('org_unit_id', $r->org_unit_id)
                    ->update(['user_id' => $newUserId]);
            }
        }
        $this->command?->warn("RenameUsersToPositionFormat: xoá user cũ id={$old->id} ('{$old->name}') để nhường slot '{$targetUsername}' cho id={$newUserId}.");
        User::where('id', $old->id)->delete();
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
