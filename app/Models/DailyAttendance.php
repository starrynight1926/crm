<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'facility_pool_unit_id', 'user_id', 'work_date', 'checkin_at',
    'list_bucket', 'is_off', 'override_by', 'override_at',
])]
class DailyAttendance extends Model
{
    protected $table = 'daily_attendance';

    public const BUCKETS = ['A', 'B', 'C', 'OFF', 'MKT'];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'checkin_at' => 'datetime',
            'override_at' => 'datetime',
            'is_off' => 'boolean',
        ];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(PoolUnit::class, 'facility_pool_unit_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function overrider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'override_by');
    }
}
