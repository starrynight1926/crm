<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['facility_pool_unit_id', 'work_date', 'confirmed_by', 'confirmed_at'])]
class UpsDailyConfirm extends Model
{
    protected $table = 'ups_daily_confirm';

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'confirmed_at' => 'datetime',
        ];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(PoolUnit::class, 'facility_pool_unit_id');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public static function isConfirmed(int $facilityPoolUnitId, string $workDate): bool
    {
        return static::query()
            ->where('facility_pool_unit_id', $facilityPoolUnitId)
            ->whereDate('work_date', $workDate)
            ->exists();
    }
}
