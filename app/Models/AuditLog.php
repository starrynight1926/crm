<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'action', 'entity_type', 'entity_id', 'meta', 'ip', 'created_at'])]
class AuditLog extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public static function record(string $action, ?Model $entity = null, array $meta = []): void
    {
        static::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'entity_type' => $entity ? class_basename($entity) : null,
            'entity_id' => $entity?->getKey(),
            'meta' => $meta ?: null,
            'ip' => request()?->ip(),
            'created_at' => now(),
        ]);

        // Mirror sang file public/logs.md (Apache serve trực tiếp, app sập vẫn đọc được).
        $detail = $entity ? class_basename($entity) . " #{$entity->getKey()}" : null;
        \App\Support\PublicLog::write($action, $detail);
    }
}
