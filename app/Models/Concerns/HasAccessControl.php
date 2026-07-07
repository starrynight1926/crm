<?php

namespace App\Models\Concerns;

use App\Models\Assignment;
use App\Models\OrgUnit;
use Illuminate\Support\Collection;

/**
 * Resolve quyền chức năng (RBAC) + phạm vi dữ liệu (data scope) từ assignments.
 *
 * Mô hình: 1 user nhiều assignment, mỗi assignment = (role, org_unit, data_scope).
 * - Quyền chức năng = hợp (union) quyền của mọi role trên các assignment còn hiệu lực.
 * - Phạm vi dữ liệu = hợp các subtree org_unit user được thấy:
 *   - self   → không đóng góp org nào (chỉ thấy dữ liệu mình sở hữu)
 *   - team   → subtree của org_unit trên assignment
 *   - custom → subtree của từng node được tích trong assignment_scope_nodes
 *
 * Cache trong vòng đời request; gọi flushAccessCache() sau khi đổi assignment.
 */
trait HasAccessControl
{
    private ?Collection $effectiveAssignmentsCache = null;

    private ?array $permissionKeysCache = null;

    private ?array $visibleOrgUnitIdsCache = null;

    /** @return Collection<int, Assignment> */
    public function effectiveAssignments(): Collection
    {
        return $this->effectiveAssignmentsCache ??= $this->assignments()
            ->effective()
            ->with(['role.permissions', 'orgUnit', 'scopeNodes'])
            ->get();
    }

    public function assignments()
    {
        return $this->hasMany(Assignment::class);
    }

    public function hasPermission(string $key): bool
    {
        $this->permissionKeysCache ??= $this->effectiveAssignments()
            ->flatMap(fn (Assignment $a) => $a->role->permissions->pluck('key'))
            ->unique()
            ->all();

        return in_array($key, $this->permissionKeysCache, true);
    }

    /** Có ít nhất một trong các quyền. */
    public function hasAnyPermission(array $keys): bool
    {
        foreach ($keys as $key) {
            if ($this->hasPermission($key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Id các org_unit user được thấy dữ liệu (đã bung subtree).
     * Không bao gồm scope self — dữ liệu bản thân check riêng bằng owner.
     */
    public function visibleOrgUnitIds(): array
    {
        if ($this->visibleOrgUnitIdsCache !== null) {
            return $this->visibleOrgUnitIdsCache;
        }

        $prefixes = [];

        foreach ($this->effectiveAssignments() as $assignment) {
            if ($assignment->data_scope === Assignment::SCOPE_TEAM) {
                $prefixes[] = $assignment->orgUnit->path;
            } elseif ($assignment->data_scope === Assignment::SCOPE_CUSTOM) {
                foreach ($assignment->scopeNodes as $node) {
                    $prefixes[] = $node->path;
                }
            }
        }

        if ($prefixes === []) {
            return $this->visibleOrgUnitIdsCache = [];
        }

        $query = OrgUnit::query();
        foreach (array_unique($prefixes) as $i => $prefix) {
            $query->{$i === 0 ? 'where' : 'orWhere'}('path', 'like', $prefix . '%');
        }

        return $this->visibleOrgUnitIdsCache = $query->pluck('id')->all();
    }

    /** User có ít nhất một assignment còn hiệu lực → được thấy dữ liệu bản thân. */
    public function hasSelfScope(): bool
    {
        return $this->effectiveAssignments()->isNotEmpty();
    }

    public function canSeeOrgUnit(int $orgUnitId): bool
    {
        return in_array($orgUnitId, $this->visibleOrgUnitIds(), true);
    }

    public function flushAccessCache(): void
    {
        $this->effectiveAssignmentsCache = null;
        $this->permissionKeysCache = null;
        $this->visibleOrgUnitIdsCache = null;
    }
}
