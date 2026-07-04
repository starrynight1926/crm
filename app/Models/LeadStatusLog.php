<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['lead_id', 'user_id', 'field', 'old_value', 'new_value', 'created_at'])]
class LeadStatusLog extends Model
{
    public $timestamps = false;

    public const FIELD_LABELS = [
        'classification' => 'Phân loại kết quả',
        'status_1' => 'Tình trạng lần 1',
        'status_2' => 'Tình trạng lần 2',
        'note' => 'Ghi chú',
        'created' => 'Tạo lead',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(Lead $lead, string $field, ?string $old, ?string $new, ?int $userId): void
    {
        static::create([
            'lead_id' => $lead->id,
            'user_id' => $userId,
            'field' => $field,
            'old_value' => $old,
            'new_value' => $new,
            'created_at' => now(),
        ]);
    }
}
