<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['user_id', 'role_id', 'org_unit_id', 'data_scope', 'active', 'valid_from', 'valid_to'])]
class Assignment extends Model
{
    public const SCOPE_SELF = 'self';
    public const SCOPE_TEAM = 'team';
    public const SCOPE_CUSTOM = 'custom';

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    public function scopeNodes(): BelongsToMany
    {
        return $this->belongsToMany(OrgUnit::class, 'assignment_scope_nodes');
    }

    /** Assignment đang có hiệu lực: active + trong khoảng valid_from/valid_to. */
    public function scopeEffective(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query->where('active', true)
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $today));
    }
}
