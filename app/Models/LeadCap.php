<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['scope_type', 'scope_id', 'daily_cap', 'active'])]
class LeadCap extends Model
{
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public static function capFor(string $scopeType, int $scopeId): ?int
    {
        return static::where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->where('active', true)
            ->value('daily_cap');
    }
}
