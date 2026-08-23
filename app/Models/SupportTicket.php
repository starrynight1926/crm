<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends Model
{
    protected $guarded = [];

    public const STATUSES = [
        'cho_xu_ly' => 'Chờ xử lý',
        'da_xu_ly' => 'Đã xử lý',
        'tu_choi' => 'Từ chối',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class, 'ticket_id')->orderBy('created_at');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'da_xu_ly' => 'bg-green-100 text-green-700 border-green-200',
            'tu_choi' => 'bg-red-100 text-red-700 border-red-200',
            default => 'bg-amber-100 text-amber-700 border-amber-200',
        };
    }
}
