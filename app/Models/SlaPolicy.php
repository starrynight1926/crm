<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['org_unit_id', 'mode', 'recall_after_hours', 'recall_to'])]
class SlaPolicy extends Model
{
    public const MODE_AUTO = 'auto';
    public const MODE_MANUAL = 'manual';
    public const MODE_OFF = 'off';

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    /** Policy áp cho 1 org: riêng của org nếu có, không thì mặc định toàn cty (org null). */
    public static function forOrgUnit(?int $orgUnitId): ?self
    {
        if ($orgUnitId !== null) {
            $own = static::where('org_unit_id', $orgUnitId)->first();
            if ($own) {
                return $own;
            }
        }

        return static::whereNull('org_unit_id')->first();
    }
}
