<?php

namespace App\Services;

use App\Models\OrgUnit;
use Illuminate\Support\Facades\DB;

/**
 * Resolve recall_policies theo cây org (Phase 6.6).
 *
 * Quy tắc: ancestor cấp cao nhất (gần root nhất) có policy → thắng. Cả cây không ai set → dùng default hệ thống.
 */
class RecallPolicyResolver
{
    /** @return array{recall_after_days:?int, escalate_after_days:?int, allow_permanent_assignment:bool, source:string} */
    public static function for(OrgUnit $orgUnit): array
    {
        $ancestorIds = self::ancestorIds($orgUnit);

        $policy = DB::table('recall_policies')
            ->join('org_units', 'org_units.id', '=', 'recall_policies.org_unit_id')
            ->whereIn('recall_policies.org_unit_id', $ancestorIds)
            ->orderBy('org_units.depth', 'asc')
            ->select('recall_policies.*', 'org_units.id as source_id')
            ->first();

        $defaults = self::systemDefaults();

        if ($policy) {
            return [
                'recall_after_days' => $policy->recall_after_days !== null ? (int) $policy->recall_after_days : $defaults['recall_after_days'],
                'escalate_after_days' => $policy->escalate_after_days !== null ? (int) $policy->escalate_after_days : $defaults['escalate_after_days'],
                'allow_permanent_assignment' => (bool) $policy->allow_permanent_assignment,
                'source' => 'org:' . $policy->source_id,
            ];
        }

        return [
            'recall_after_days' => $defaults['recall_after_days'],
            'escalate_after_days' => $defaults['escalate_after_days'],
            'allow_permanent_assignment' => $defaults['allow_permanent_assignment'],
            'source' => 'system',
        ];
    }

    private static function ancestorIds(OrgUnit $orgUnit): array
    {
        $trimmed = trim((string) $orgUnit->path, '/');
        if ($trimmed === '') {
            return [$orgUnit->id];
        }

        return array_map('intval', explode('/', $trimmed));
    }

    /** @return array{recall_after_days:?int, escalate_after_days:?int, allow_permanent_assignment:bool} */
    private static function systemDefaults(): array
    {
        $rows = DB::table('system_settings')->pluck('value', 'key')->all();

        return [
            'recall_after_days' => isset($rows['default_recall_after_days']) ? (int) $rows['default_recall_after_days'] : null,
            'escalate_after_days' => isset($rows['default_escalate_after_days']) ? (int) $rows['default_escalate_after_days'] : null,
            'allow_permanent_assignment' => isset($rows['default_allow_permanent']) ? (bool) $rows['default_allow_permanent'] : true,
        ];
    }
}
