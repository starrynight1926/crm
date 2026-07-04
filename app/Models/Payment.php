<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['lead_id', 'customer_service_id', 'customer_service_phase_id', 'amount', 'method', 'paid_at', 'collected_by', 'note'])]
class Payment extends Model
{
    public const METHODS = [
        'cash' => 'Tiền mặt',
        'transfer' => 'Chuyển khoản',
        'card' => 'Quẹt thẻ',
    ];

    protected function casts(): array
    {
        return ['paid_at' => 'date'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function customerService(): BelongsTo
    {
        return $this->belongsTo(CustomerService::class);
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }
}
