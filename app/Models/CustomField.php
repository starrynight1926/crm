<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

#[Fillable(['org_unit_id', 'key', 'label', 'field_type', 'options', 'required', 'position', 'active'])]
class CustomField extends Model
{
    public const TYPES = [
        'text' => 'Văn bản',
        'number' => 'Số',
        'date' => 'Ngày',
        'select' => 'Danh sách chọn',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'required' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    /**
     * Bộ trường áp cho lead đang nằm ở $orgUnit: trường mức công ty (org null)
     * + trường của chính phòng đó và các phòng cha (theo path).
     * $orgUnit null (lead kho chung) → chỉ trường mức công ty.
     */
    public static function applicableTo(?OrgUnit $orgUnit): Collection
    {
        $ancestorIds = $orgUnit
            ? array_filter(array_map('intval', explode('/', trim($orgUnit->path, '/'))))
            : [];

        return static::query()
            ->where('active', true)
            ->where(fn ($q) => $q
                ->whereNull('org_unit_id')
                ->when($ancestorIds !== [], fn ($qq) => $qq->orWhereIn('org_unit_id', $ancestorIds)))
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }
}
