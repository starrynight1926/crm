<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026-08-13 — Backfill pool_unit_id cho lead legacy trước fix DistributionEngine.
 *
 * Trước đây distribute()/manualAssign()/assignToOwner() set org_unit_id + pool_level
 * nhưng KHÔNG set pool_unit_id → lead pool_level=team + pool_unit_id=null không match
 * tab facility/branch/department trong /distribution/pools (whereHas('poolUnit', kind=X)).
 * User bấm "Xem tất cả" từ widget Kho số → tab nào cũng rỗng.
 *
 * Backfill: resolve pool_unit_id (kind=facility) từ org_unit_id qua ancestors + org_pool_map.
 */
return new class extends Migration {
    public function up(): void
    {
        $leads = DB::table('leads')
            ->whereNull('pool_unit_id')
            ->whereNotNull('org_unit_id')
            ->get(['id', 'org_unit_id']);

        $fixed = 0;
        foreach ($leads as $lead) {
            $orgUnit = DB::table('org_units')->find($lead->org_unit_id);
            if (! $orgUnit) continue;
            $ancestors = array_filter(explode('/', trim($orgUnit->path, '/')));
            if (! $ancestors) continue;

            $poolUnitId = DB::table('pool_units')
                ->where('kind', 'facility')
                ->where('is_active', true)
                ->whereIn('id', function ($q) use ($ancestors) {
                    $q->select('pool_unit_id')->from('org_pool_map')->whereIn('org_unit_id', $ancestors);
                })
                ->orderBy('depth')
                ->value('id');
            if ($poolUnitId) {
                DB::table('leads')->where('id', $lead->id)->update(['pool_unit_id' => $poolUnitId]);
                $fixed++;
            }
        }

        if (function_exists('logger')) logger()->info("Backfill pool_unit_id: fixed {$fixed}/{$leads->count()} leads.");
    }

    public function down(): void
    {
        // no-op: không xoá pool_unit_id để tránh vỡ data.
    }
};
