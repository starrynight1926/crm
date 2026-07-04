<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['file_name', 'uploaded_by', 'column_mapping', 'total', 'success', 'failed', 'duplicated', 'created_at'])]
class ImportBatch extends Model
{
    protected $connection = 'pgsql';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'column_mapping' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function rawLeads(): HasMany
    {
        return $this->hasMany(RawLead::class, 'import_batch_id');
    }

    public function refreshStats(): void
    {
        $counts = $this->rawLeads()
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $this->update([
            'success' => $counts[RawLead::STATUS_PROCESSED] ?? 0,
            'failed' => $counts[RawLead::STATUS_FAILED] ?? 0,
            'duplicated' => $counts[RawLead::STATUS_DUPLICATE] ?? 0,
        ]);
    }

    public function pendingCount(): int
    {
        return $this->rawLeads()->where('status', RawLead::STATUS_PENDING)->count();
    }
}
