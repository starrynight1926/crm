<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['facility_pool_unit_id', 'cutoff_time'])]
class UpsConfig extends Model
{
    protected $table = 'ups_config';

    public const DEFAULT_CUTOFF = '08:35:00';

    public function facility(): BelongsTo
    {
        return $this->belongsTo(PoolUnit::class, 'facility_pool_unit_id');
    }

    public static function cutoffFor(int $facilityPoolUnitId): string
    {
        return static::query()
            ->where('facility_pool_unit_id', $facilityPoolUnitId)
            ->value('cutoff_time') ?? self::DEFAULT_CUTOFF;
    }
}
