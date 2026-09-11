<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['parent_id', 'name', 'code', 'kind', 'path', 'depth', 'sort', 'is_active'])]
class PoolUnit extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort');
    }

    public static function createNode(array $attributes, ?self $parent = null): self
    {
        $node = new self($attributes);
        $node->parent_id = $parent?->id;
        $node->depth = $parent ? $parent->depth + 1 : 0;
        $node->path = '';
        $node->save();

        $node->path = ($parent ? rtrim($parent->path, '/') : '').'/'.$node->id.'/';
        $node->save();

        return $node;
    }

    public function subtreeIds(): array
    {
        return self::query()->where('path', 'like', $this->path.'%')->pluck('id')->all();
    }

    public function scopeInSubtreeOf($query, self $node)
    {
        return $query->where('path', 'like', $node->path.'%');
    }
}
