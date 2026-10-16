<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'batch_date', 'lead_id', 'imported_by', 'assigned_to_user_id', 'assigned_by',
    'assigned_at', 'facility_pool_unit_id', 'imported_name', 'imported_phone',
])]
class RecallEntry extends Model
{
    protected function casts(): array
    {
        return [
            'batch_date' => 'date',
            'assigned_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(PoolUnit::class, 'facility_pool_unit_id');
    }
}
