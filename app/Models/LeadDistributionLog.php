<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'lead_id', 'action', 'from_pool_level', 'to_pool_level',
    'from_owner_id', 'to_owner_id', 'org_unit_id', 'rule_id', 'actor_id', 'created_at',
])]
class LeadDistributionLog extends Model
{
    public $timestamps = false;

    public const ACTION_DISTRIBUTE = 'distribute';
    public const ACTION_RECALL = 'recall';
    public const ACTION_PULL = 'pull';
    public const ACTION_MANUAL = 'manual_assign';

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function toOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_owner_id');
    }
}
