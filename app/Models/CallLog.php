<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['lead_id', 'user_id', 'status', 'note', 'called_at'])]
class CallLog extends Model
{
    public const STATUS_THANH_CONG = 'thanh_cong';
    public const STATUS_THAT_BAI = 'that_bai';
    public const STATUS_KHONG_NGHE_MAY = 'khong_nghe_may';

    public const STATUSES = [
        self::STATUS_THANH_CONG      => 'Thành công',
        self::STATUS_THAT_BAI        => 'Thất bại',
        self::STATUS_KHONG_NGHE_MAY  => 'Không nghe máy',
    ];

    protected $casts = [
        'called_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    protected static function booted(): void
    {
        // B1d (2026-08-14) — ghi cuộc gọi mới cho lead MKT → gia hạn recall lên 3 ngày.
        static::created(function (CallLog $log) {
            $log->lead?->bumpMktRecallOnCall();
        });
    }
}
