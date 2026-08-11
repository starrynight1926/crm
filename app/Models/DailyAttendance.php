<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'facility_pool_unit_id', 'user_id', 'work_date', 'checkin_at',
    'list_bucket', 'is_off', 'override_by', 'override_at',
    'dung_nhan_lead', 'dung_nhan_lead_since', 'is_mkt',
])]
class DailyAttendance extends Model
{
    protected $table = 'daily_attendance';

    public const BUCKETS = ['A', 'B', 'C', 'OFF'];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'checkin_at' => 'datetime',
            'override_at' => 'datetime',
            'is_off' => 'boolean',
            'is_mkt' => 'boolean',
            'dung_nhan_lead' => 'boolean',
            'dung_nhan_lead_since' => 'datetime',
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
