<?php

namespace App\Support;

use App\Models\OrgUnit;
use App\Models\User;

/**
 * Password mặc định (dev/staging) theo cơ sở làm việc.
 *
 * Rule (2026-08-13):
 *   - Cơ sở HN  (branch-hn)  → 59@ntn   (59 Ngô Thì Nhậm)
 *   - Cơ sở HCM (branch-hcm) → 207@nvt  (207 Nguyễn Văn Thụ)
 *   - Cơ sở ĐN  (branch-dn)  → l23@tdn  (Lô 23 Trần Đăng Ninh)
 *   - Vận hành  (ops-monitor / admin superadmin / vh.*) → 59ntn
 *
 * Cơ sở được resolve qua assignment.org_unit → tổ tiên đầu tiên có code branch-*.
 * Nếu user chưa có assignment (admin.*, seed sớm) → fallback theo email prefix.
 */
class DefaultPassword
{
    public const HN  = '59@ntn';
    public const HCM = '207@nvt';
    public const DN  = 'l23@tdn';
    public const VH  = '59ntn';

    /** Ưu tiên: user → email fallback. Dùng cho reset password và seeder sau khi assignment đã có. */
    public static function forUser(User $user): string
    {
        $email = strtolower((string) $user->email);
        $local = strstr($email, '@', true) ?: $email;

        // Nhóm ops / superadmin: vẫn map theo email.
        if (in_array($local, ['admin', 'admin.hn', 'admin.hcm', 'admin.dn'], true)
            || str_starts_with($local, 'vh.')) {
            return self::forEmail($email);
        }

        $assignment = $user->assignments()->first();
        if ($assignment) {
            $unit = OrgUnit::find($assignment->org_unit_id);
            $branch = $unit ? self::resolveBranchCode($unit) : null;
            if ($branch) {
                return self::forBranchCode($branch);
            }
        }

        return self::forEmail($email);
    }

    public static function forEmail(string $email): string
    {
        $lower = strtolower($email);
        $local = strstr($lower, '@', true) ?: $lower;

        if ($local === 'admin')     return self::VH;
        if ($local === 'admin.hn')  return self::HN;
        if ($local === 'admin.hcm') return self::HCM;
        if ($local === 'admin.dn')  return self::DN;

        if (str_starts_with($local, 'hn.'))  return self::HN;
        if (str_starts_with($local, 'hcm.')) return self::HCM;
        if (str_starts_with($local, 'dn.'))  return self::DN;
        if (str_starts_with($local, 'vh.'))  return self::VH;

        return self::HN; // fallback dev
    }

    public static function forBranchCode(?string $branchCode): string
    {
        return match ($branchCode) {
            'branch-hn'   => self::HN,
            'branch-hcm'  => self::HCM,
            'branch-dn'   => self::DN,
            'ops-monitor' => self::VH,
            default       => self::HN,
        };
    }

    /** Đi ngược lên tổ tiên tới khi gặp branch-* hoặc ops-monitor. */
    public static function resolveBranchCode(OrgUnit $unit): ?string
    {
        $current = $unit;
        $guard = 0;
        while ($current && $guard++ < 20) {
            if (in_array($current->code, ['branch-hn', 'branch-hcm', 'branch-dn', 'ops-monitor'], true)) {
                return $current->code;
            }
            $current = $current->parent;
        }
        return null;
    }
}
