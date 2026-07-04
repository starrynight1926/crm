<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'source_type', 'source_ref', 'import_batch_id', 'payload',
    'status', 'error_reason', 'clean_lead_id', 'created_at', 'processed_at',
])]
class RawLead extends Model
{
    protected $connection = 'pgsql';

    public $timestamps = false;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DUPLICATE = 'duplicate';

    public const SOURCE_EXCEL = 'excel';
    public const SOURCE_ADS_API = 'ads_api';
    public const SOURCE_WEBHOOK = 'webhook';
    public const SOURCE_MANUAL = 'manual';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }
}
