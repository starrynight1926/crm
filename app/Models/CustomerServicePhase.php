<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['customer_service_id', 'service_phase_id', 'status', 'done_by', 'done_at', 'handover_note'])]
class CustomerServicePhase extends Model
{
    public $timestamps = false;

    public const STATUS_PENDING = 'pending';
    public const STATUS_DONE = 'done';
    public const STATUS_SKIPPED = 'skipped';

    protected function casts(): array
    {
        return ['done_at' => 'datetime'];
    }

    public function customerService(): BelongsTo
    {
        return $this->belongsTo(CustomerService::class);
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(ServicePhase::class, 'service_phase_id');
    }

    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }
}
