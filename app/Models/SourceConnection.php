<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['type', 'name', 'credentials', 'webhook_token', 'field_mapping', 'default_type_code', 'active', 'last_synced_at'])]
class SourceConnection extends Model
{
    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'field_mapping' => 'array',
            'active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }
}
