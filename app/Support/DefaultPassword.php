<?php

namespace App\Support;

/**
 * Password mặc định (dev/staging) theo prefix email hoặc branch code.
 *
 * Rule (2026-07-31):
 *   - Cơ sở HN  (email prefix "hn.*"  / branch-hn)  → 59@ntn   (59 Ngô Thì Nhậm)
 *   - Cơ sở HCM (email prefix "hcm.*" / branch-hcm) → 207@nvt  (207 Nguyễn Văn Thụ)
 *   - Cơ sở ĐN  (email prefix "dn.*"  / branch-dn)  → 23@tdn   (Lô 2+3 Trần Đăng Ninh)
 *   - Vận hành  (email prefix "vh.*"  / ops-monitor) → 59ntn   (không có @)
 *   - admin@... (superadmin)                         → 59ntn
 *   - admin.hn / admin.hcm / admin.dn                → theo cơ sở
 *   - Còn lại (bs./ktv./dd. sync từ sbooking, …)     → 59@ntn (fallback dev)
 */
class DefaultPassword
{
    public const HN  = '59@ntn';
    public const HCM = '207@nvt';
    public const DN  = '23@tdn';
    public const VH  = '59ntn';

    public static function forEmail(string $email): string
    {
        $lower = strtolower($email);
        $local = strstr($lower, '@', true) ?: $lower;

        if ($local === 'admin')       return self::VH;
        if ($local === 'admin.hn')    return self::HN;
        if ($local === 'admin.hcm')   return self::HCM;
        if ($local === 'admin.dn')    return self::DN;

        if (str_starts_with($local, 'hn.'))  return self::HN;
        if (str_starts_with($local, 'hcm.')) return self::HCM;
        if (str_starts_with($local, 'dn.'))  return self::DN;
        if (str_starts_with($local, 'vh.'))  return self::VH;

        return self::HN; // fallback (bs./ktv./dd. và các seed cũ chưa rename)
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
}
