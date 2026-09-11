<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['parent_id', 'name', 'code', 'pricing_type', 'package_price', 'price_usd', 'active', 'notes'])]
class Service extends Model
{
    public const PRICING_PACKAGE = 'package';
    public const PRICING_PER_PHASE = 'per_phase';

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
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }

    public function phases(): HasMany
    {
        return $this->hasMany(ServicePhase::class)->orderBy('position');
    }

    public function isCategory(): bool
    {
        return $this->parent_id === null && $this->children()->exists();
    }

    /** Giá niêm yết: trọn gói lấy package_price, theo phase = Σ phase_price. */
    public function listPrice(): int
    {
        return $this->pricing_type === self::PRICING_PACKAGE
            ? (int) ($this->package_price ?? 0)
            : (int) $this->phases()->sum('phase_price');
    }
}
