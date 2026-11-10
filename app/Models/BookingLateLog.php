<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'booking_log_id', 'lead_id', 'sbooking_booking_id', 'sbooking_booking_ma',
    'expected_at', 'arrived_at', 'late_minutes', 'marked_by', 'note',
])]
class BookingLateLog extends Model
{
    protected $casts = [
        'expected_at' => 'datetime',
        'arrived_at' => 'datetime',
        'late_minutes' => 'integer',
    ];

    public function bookingLog(): BelongsTo
    {
        return $this->belongsTo(BookingLog::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
