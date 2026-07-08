<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

#[Fillable([
    'org_unit_id', 'key', 'label', 'field_type', 'options', 'rules', 'affects_code',
    'required', 'position', 'active', 'status', 'requested_by', 'reviewed_by', 'reviewed_at', 'reject_reason',
])]
class CustomField extends Model
{
    public const TYPES = [
        'text' => 'Văn bản',
        'number' => 'Số',
        'date' => 'Ngày',
        'email' => 'Email',
        'select' => 'Danh sách chọn',
        'tick' => 'Ô tích (có / không)',
        'code' => 'Mã phân loại (nối vào mã KH)',
    ];

    public const STATUS_ACTIVE = 'active';     // đã áp lên lead
    public const STATUS_PENDING = 'pending';   // chờ cấp trên duyệt
    public const STATUS_REJECTED = 'rejected';

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'rules' => 'array',
            'required' => 'boolean',
            'active' => 'boolean',
            'affects_code' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isLive(): bool
    {
        return $this->active && $this->status === self::STATUS_ACTIVE;
    }

    /** Ids của node công ty (null) + phòng hiện tại + các phòng cha, theo path. */
    protected static function ancestorOrgIds(?OrgUnit $orgUnit): array
    {
        return $orgUnit
            ? array_filter(array_map('intval', explode('/', trim($orgUnit->path, '/'))))
            : [];
    }

    /**
     * Bộ trường ĐANG ÁP (active + đã duyệt) cho lead ở $orgUnit: trường mức công ty
     * (org null) + trường của phòng đó và các phòng cha. Sắp theo cây (công ty trước)
     * rồi position — dùng cho cả render form lẫn nối mã.
     */
    public static function applicableTo(?OrgUnit $orgUnit): Collection
    {
        $ancestorIds = static::ancestorOrgIds($orgUnit);

        $fields = static::query()
            ->where('active', true)
            ->where('status', self::STATUS_ACTIVE)
            ->where(fn ($q) => $q
                ->whereNull('org_unit_id')
                ->when($ancestorIds !== [], fn ($qq) => $qq->orWhereIn('org_unit_id', $ancestorIds)))
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        // Sắp theo độ sâu org (công ty trước, rồi cha → con), rồi position, rồi id.
        // Dùng 1 sort key gộp cho ổn định (multi-closure sortBy không đảm bảo thứ tự).
        $depthOf = fn ($orgId) => $orgId === null ? -1 : (int) array_search($orgId, $ancestorIds, true);

        return $fields->sortBy(fn ($f) => sprintf(
            '%03d.%05d.%010d',
            $depthOf($f->org_unit_id) + 1,
            $f->position,
            $f->id
        ))->values();
    }

    /**
     * Các đoạn mã (uppercase) nối vào mã KH cho $lead, theo thứ tự cây.
     * Giá trị: code_kind=fixed → rules.fixed_value; còn lại lấy từ lead_custom_values.
     */
    public static function codeSegmentsFor(Lead $lead, bool $onlyRequired = false): array
    {
        $fields = static::applicableTo($lead->orgUnit)->where('affects_code', true);
        if ($onlyRequired) {
            $fields = $fields->where('required', true);
        }
        if ($fields->isEmpty()) {
            return [];
        }

        $values = $lead->relationLoaded('customValues')
            ? $lead->customValues->keyBy('custom_field_id')
            : LeadCustomValue::where('lead_id', $lead->id)->get()->keyBy('custom_field_id');

        $segments = [];
        foreach ($fields as $field) {
            $raw = ($field->rules['code_kind'] ?? null) === 'fixed'
                ? ($field->rules['fixed_value'] ?? '')
                : (string) ($values[$field->id]->value ?? '');

            $seg = static::normalizeCode($raw);
            if ($seg !== '') {
                $segments[] = $seg;
            }
        }

        return $segments;
    }

    /**
     * Map [field_id => nhãn hiển thị] cho một BỘ trường: nhãn nào trùng nhau giữa các
     * cấp thì gắn thêm hậu tố phạm vi để phân biệt, VD "Nguồn (Công ty)" / "Nguồn (Kinh doanh)".
     */
    public static function labelMap(Collection $fields): array
    {
        $dupLabels = $fields->groupBy('label')->filter(fn ($g) => $g->count() > 1)->keys()->all();

        $map = [];
        foreach ($fields as $f) {
            $label = $f->label;
            if (in_array($f->label, $dupLabels, true)) {
                $scope = $f->org_unit_id === null ? 'Công ty' : ($f->orgUnit?->name ?? 'Phòng');
                $label .= ' (' . $scope . ')';
            }
            $map[$f->id] = $label;
        }

        return $map;
    }

    /** Nhãn hiển thị của một giá trị select (rules.option_labels); fallback = chính giá trị. */
    public function optionLabel(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return (string) ($this->rules['option_labels'][$value] ?? $value);
    }

    /** Chuẩn hóa 1 đoạn mã: bỏ khoảng trắng, hoa hết, chỉ giữ chữ/số. */
    public static function normalizeCode(string $raw): string
    {
        $s = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw));
        return $s;
    }
}
