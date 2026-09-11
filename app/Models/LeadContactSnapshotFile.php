<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadContactSnapshotFile extends Model
{
    protected $fillable = ['snapshot_id', 'path', 'mime', 'size_bytes'];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(LeadContactSnapshot::class, 'snapshot_id');
    }

    public function url(): string
    {
        return \Illuminate\Support\Facades\Storage::disk('public')->url($this->path);
    }
}
