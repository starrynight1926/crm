<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['lead_id', 'service_id', 'agreed_price', 'status', 'started_at', 'completed_at'])]
class CustomerService extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function phases(): HasMany
    {
        return $this->hasMany(CustomerServicePhase::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** Đã thu bao nhiêu cho dịch vụ này. */
    public function totalPaid(): int
    {
        return (int) $this->payments()->sum('amount');
    }

    /** Công nợ còn lại = giá chốt − Σ đã thu (tính động, không lưu). */
    public function outstanding(): int
    {
        return max(0, $this->agreed_price - $this->totalPaid());
    }

    /** Sinh sẵn dòng tiến độ cho toàn bộ phase của dịch vụ. */
    public function initPhases(): void
    {
        foreach ($this->service->phases as $phase) {
            $this->phases()->firstOrCreate(['service_phase_id' => $phase->id]);
        }
    }

    public function doneCount(): int
    {
        return $this->phases()->where('status', CustomerServicePhase::STATUS_DONE)->count();
    }
}
