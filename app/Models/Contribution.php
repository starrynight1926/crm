<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['lead_id', 'customer_service_id', 'user_id', 'role_label', 'percent', 'set_by', 'created_at'])]
class Contribution extends Model
{
    public $timestamps = false;

    public const ROLE_LABELS = [
        'collector' => 'Thu thập data',
        'care_1' => 'Care lần 1',
        'care_2' => 'Care lần 2',
        'phase_worker' => 'Làm phase',
        'closer' => 'Chốt deal',
        'other' => 'Khác',
    ];

    protected function casts(): array
    {
        return [
            'percent' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
