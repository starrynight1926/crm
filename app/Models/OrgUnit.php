<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['parent_id', 'name', 'code', 'path', 'depth', 'position', 'active'])]
class OrgUnit extends Model
{
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    public function managers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'org_unit_managers')->withTimestamps();
    }

    /**
     * Tạo node kèm materialized path. Luôn dùng hàm này thay vì create() trực tiếp
     * để path/depth nhất quán.
     */
    public static function createNode(array $attributes, ?self $parent = null): self
    {
        $node = new self($attributes);
        $node->parent_id = $parent?->id;
        $node->depth = $parent ? $parent->depth + 1 : 0;
        $node->path = ''; // tạm, cần id trước
        $node->save();

        $node->path = ($parent ? rtrim($parent->path, '/') : '') . '/' . $node->id . '/';
        $node->save();

        return $node;
    }

    /** Id của node này + toàn bộ node con (mọi cấp) — path của con luôn bắt đầu bằng path của cha. */
    public function subtreeIds(): array
    {
        return self::query()
            ->where('path', 'like', $this->path . '%')
            ->pluck('id')
            ->all();
    }

    /** Scope subtree cho query builder. */
    public function scopeInSubtreeOf($query, self $node)
    {
        return $query->where('path', 'like', $node->path . '%');
    }
}
