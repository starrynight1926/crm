<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'raw_lead_id', 'code', 'type_code', 'source_code', 'received_date', 'page', 'camp',
    'insight', 'link', 'ad_source', 'name', 'phone', 'region', 'classification',
    'status_1', 'status_2', 'note',
    'pool_level', 'owner_id', 'receiver_id', 'org_unit_id', 'assigned_at', 'last_care_at',
])]
class Lead extends Model
{
    use SoftDeletes;

    // Phân loại kết quả — thứ tự theo funnel trong scope.md mục 4
    public const CLASSIFICATIONS = [
        'new' => 'Mới',
        'lead' => 'Lead',
        'follow' => 'Follow',
        'net' => 'Nét',
        'tai_chinh_yeu' => 'Tài chính yếu',
        'quan_tam' => 'Quan tâm',
        'tham_khao' => 'Tham khảo',
        'tim_hieu' => 'Tìm hiểu',
        'goi_lai_sau' => 'Gọi lại sau',
        'klld' => 'KLLD',
        'missed' => 'Missed',
        'booking' => 'Booking',
        'show' => 'Show',
        'close' => 'Close',
    ];

    public const POOL_COMMON = 'common';
    public const POOL_TEAM = 'team';
    public const POOL_PERSONAL = 'personal';

    // Loại data theo whiteboard (scope.md 4.1)
    public const TYPE_CODES = [
        'MKT' => 'Data Marketing',
        'C' => 'Data lạnh (telesale)',
        'BDM' => 'Data BDM',
        'SI' => 'Tự giới thiệu (sale)',
        'N' => 'Tự đến',
    ];

    // Map nguồn quảng cáo → mã nguồn gắn vào mã KH
    public const SOURCE_CODES = [
        'Facebook Ads' => 'FB',
        'Google Ads' => 'GG',
        'TikTok Ads' => 'TT',
        'Zalo' => 'ZL',
        'Website' => 'WEB',
        'Giới thiệu' => 'GT',
    ];

    protected function casts(): array
    {
        return [
            'received_date' => 'date',
            'assigned_at' => 'datetime',
            'last_care_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(LeadStatusLog::class)->orderByDesc('created_at');
    }

    public function customValues(): HasMany
    {
        return $this->hasMany(LeadCustomValue::class);
    }

    public function customerServices(): HasMany
    {
        return $this->hasMany(CustomerService::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(Contribution::class);
    }

    /**
     * Sinh mã KH-{số}-{loại}[-{nguồn}] từ id (số tăng dần toàn hệ thống).
     * Gọi sau khi lead đã có id; idempotent.
     */
    public function generateCode(): string
    {
        $code = sprintf('KH-%05d-%s', $this->id, $this->type_code ?: 'N');
        if ($this->source_code) {
            $code .= '-' . strtoupper($this->source_code);
        }

        if ($this->code !== $code) {
            $this->forceFill(['code' => $code])->save();
        }

        return $code;
    }

    public static function sourceCodeFor(?string $adSource): ?string
    {
        return $adSource ? (self::SOURCE_CODES[$adSource] ?? null) : null;
    }

    public function classificationLabel(): string
    {
        return self::CLASSIFICATIONS[$this->classification] ?? $this->classification;
    }

    /**
     * Data scope: user thấy lead khi lead thuộc org_unit trong phạm vi được cấp,
     * hoặc chính mình là người giữ/người nhận (scope self).
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user) {
            $orgIds = $user->visibleOrgUnitIds();
            if ($orgIds !== []) {
                $q->orWhereIn('org_unit_id', $orgIds);
            }
            if ($user->hasSelfScope()) {
                $q->orWhere('owner_id', $user->id)->orWhere('receiver_id', $user->id);
            }
            // User không có assignment nào còn hiệu lực → không thấy gì
            if ($orgIds === [] && ! $user->hasSelfScope()) {
                $q->whereRaw('1 = 0');
            }
        });
    }

    /** Lead này có nằm trong scope của user không (dùng cho chi tiết / mask SĐT). */
    public function isVisibleTo(User $user): bool
    {
        if ($user->hasSelfScope() && ($this->owner_id === $user->id || $this->receiver_id === $user->id)) {
            return true;
        }

        return $this->org_unit_id !== null && $user->canSeeOrgUnit($this->org_unit_id);
    }

    /**
     * SĐT hiển thị cho user: trong scope hoặc có quyền lead.view_phone thì thấy đầy đủ,
     * ngoài scope thì mask dạng 090***4567.
     */
    public function phoneFor(User $user): string
    {
        if ($this->canViewFullPhone($user)) {
            return $this->phone;
        }

        return self::maskPhone($this->phone);
    }

    public function canViewFullPhone(User $user): bool
    {
        return $this->isVisibleTo($user) || $user->hasPermission('lead.view_phone');
    }

    public static function maskPhone(string $phone): string
    {
        $len = strlen($phone);
        if ($len <= 7) {
            return str_repeat('*', $len);
        }

        return substr($phone, 0, 3) . str_repeat('*', $len - 7) . substr($phone, -4);
    }

    /**
     * Chuẩn hóa SĐT VN về dạng 0XXXXXXXXX (10 số). Trả null nếu không hợp lệ.
     */
    public static function normalizePhone(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw);

        if (str_starts_with($digits, '84')) {
            $digits = '0' . substr($digits, 2);
        }
        if (strlen($digits) === 9 && ! str_starts_with($digits, '0')) {
            $digits = '0' . $digits;
        }

        return preg_match('/^0\d{9}$/', $digits) ? $digits : null;
    }
}
