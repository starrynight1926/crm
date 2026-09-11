<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * B1a (2026-08-14) — Lịch sử ownership từng có của lead.
 *
 * Reasons: 'assigned' | 'recalled_1d' | 'recalled_3d' | 'recalled_30d'
 *        | 'reassigned' | 'released_manual' | 'booking_transfer'
 */
class LeadOwnershipHistory extends Model
{
    protected $table = 'lead_ownership_history';

    protected $fillable = [
        'lead_id', 'user_id', 'assigned_at', 'released_at', 'released_reason',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
