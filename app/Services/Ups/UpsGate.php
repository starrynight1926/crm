<?php

namespace App\Services\Ups;

use App\Models\OrgUnit;
use App\Models\PoolUnit;
use App\Models\UpsDailyConfirm;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Gate chia số theo UPS.
 *
 * Rule: chi nhánh của user phải có ÍT NHẤT 1 cơ sở đã chốt UPS hôm nay
 * thì mới cho chia. BO của chi nhánh là người bấm "Chốt UPS".
 *
 * Admin toàn quyền (user.manage) → bypass.
 */
class UpsGate
{
    public function isBlockedFor(User $user): bool
    {
        if ($user->hasPermission('user.manage')) {
            return false;
        }
        $facilityIds = $this->facilityPoolIdsInUserScope($user);
        if (empty($facilityIds)) {
            return false;
        }

        return ! UpsDailyConfirm::whereIn('facility_pool_unit_id', $facilityIds)
            ->whereDate('work_date', now()->toDateString())
            ->exists();
    }

    /**
     * Pool facility ids dưới các chi nhánh (org) là TỔ TIÊN của các org user được gán.
     * VD: user gán ở team-sale-hn (path /1/5/12/) → tổ tiên gồm [1, 5, 12] → tra org_pool_map
     * để tìm branch org đã map sang pool branch.
     */
    private function facilityPoolIdsInUserScope(User $user): array
    {
        $orgs = $user->assignments()->with('orgUnit')->get()->pluck('orgUnit')->filter();
        if ($orgs->isEmpty()) {
            return [];
        }

        $ancestorIds = [];
        foreach ($orgs as $org) {
            foreach (array_filter(explode('/', $org->path)) as $id) {
                $ancestorIds[(int) $id] = true;
            }
            $ancestorIds[$org->id] = true;
        }

        $poolBranchIds = DB::table('org_pool_map')
            ->whereIn('org_unit_id', array_keys($ancestorIds))
            ->pluck('pool_unit_id')->all();

        if (! $poolBranchIds) {
            return [];
        }

        return PoolUnit::whereIn('parent_id', $poolBranchIds)
            ->where('kind', 'facility')
            ->pluck('id')->all();
    }
}
