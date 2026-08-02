<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'booking_log_id',
    'lead_id',
    'source',
    'user_id',
    'sbooking_user_id',
    'user_name',
    'content',
])]
class BookingLogComment extends Model
{
    public function bookingLog(): BelongsTo
    {
        return $this->belongsTo(BookingLog::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
