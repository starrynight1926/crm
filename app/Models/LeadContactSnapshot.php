<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * B2 (2026-08-14) — Liên hệ gần nhất: mỗi bản ghi = 1 lượt upload (sale + note + N ảnh).
 */
class LeadContactSnapshot extends Model
{
    protected $fillable = ['lead_id', 'user_id', 'note'];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(LeadContactSnapshotFile::class, 'snapshot_id');
    }
}
