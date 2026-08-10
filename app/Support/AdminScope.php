<?php

namespace App\Support;

use App\Models\OrgUnit;

/**
 * 2026-08-11 — Cho super admin (is_admin) "chọn cơ sở đang xem" tạm thời
 * để các widget dashboard scope theo branch cụ thể (thay vì worldwide).
 *
 * User thường: helper trả memberOrgUnitIds() gốc — không đổi hành vi.
 * Super admin:
 *   - Chưa chọn branch (session 'admin_scope_org_unit_id' null) → return null
 *     nghĩa "toàn công ty" (caller quyết định: không apply filter).
 *   - Đã chọn branch → return subtree org_unit_ids của branch đó.
 */
class AdminScope
{
    public const SESSION_KEY = 'admin_scope_org_unit_id';

    public static function isSuperAdmin(): bool
    {
        $u = auth()->user();
        return $u && ($u->is_admin || $u->email === 'admin@longevity.com.vn');
    }

    public static function currentBranchId(): ?int
    {
        $id = session(self::SESSION_KEY);
        return $id ? (int) $id : null;
    }

    public static function currentBranchName(): ?string
    {
        $id = self::currentBranchId();
        if (! $id) return null;
        return OrgUnit::find($id)?->name;
    }

    /**
     * Org_unit_ids để scope query cho widget/list "chỉ Sale trong scope".
     * Return null = worldwide (không filter). Return array = filter theo ids.
     */
    public static function orgUnitIds(): ?array
    {
        $u = auth()->user();
        if (! $u) return [];

        if (self::isSuperAdmin()) {
            $branchId = self::currentBranchId();
            if (! $branchId) return null; // worldwide
            return self::subtreeOrgIds($branchId);
        }

        return $u->memberOrgUnitIds();
    }

    /** Nhánh dropdown chọn — depth=1 (cơ sở/chi nhánh trực thuộc công ty). */
    public static function branchOptions()
    {
        return OrgUnit::where('depth', 1)
            ->orderBy('position')->orderBy('name')
            ->get(['id', 'name']);
    }

    private static function subtreeOrgIds(int $branchId): array
    {
        $branch = OrgUnit::find($branchId);
        if (! $branch) return [];
        return OrgUnit::where('path', 'like', $branch->path . '%')->pluck('id')->all();
    }
}
