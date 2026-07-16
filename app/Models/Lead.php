<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'raw_lead_id', 'code', 'received_date', 'page', 'camp',
    'insight', 'link', 'ad_source', 'name', 'phone', 'region', 'classification',
    'status_1', 'status_2', 'note',
    'pool_level', 'owner_id', 'receiver_id', 'org_unit_id',
    'facility_id', 'doctor_id', 'consultant_1_id', 'consultant_2_id', 'consultant_3_id',
    'assigned_at', 'last_care_at',
    'birthday', 'address', 'medical_history', 'occupation',
    'service_name',
    'treatment_1', 'treatment_2', 'treatment_3', 'treatment_4',
    'performing_doctor_id', 'quality_rating',
    'potential_service',
    // Phase 6.6
    'source_group', 'approval_status', 'approval_by', 'approved_at',
    'overdue_marked_at', 'recall_at', 'is_permanent_assignment',
    'booking_status',
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

    // Phase 6.6 — 6 nhóm nguồn
    public const SOURCE_MARKETING = 'marketing';
    public const SOURCE_DATA_COLD = 'data_cold';
    public const SOURCE_BDM = 'bdm';
    public const SOURCE_REFERRAL = 'referral';
    public const SOURCE_CTV = 'ctv';
    public const SOURCE_WALK_IN = 'walk_in';

    public const SOURCE_GROUPS = [
        self::SOURCE_MARKETING => 'Marketing',
        self::SOURCE_DATA_COLD => 'Data lạnh',
        self::SOURCE_BDM => 'BDM',
        self::SOURCE_REFERRAL => 'Bạn giới thiệu',
        self::SOURCE_CTV => 'Cộng tác viên',
        self::SOURCE_WALK_IN => 'Khách tự đến',
    ];

    // Permission tương ứng cần có để thấy nhóm nguồn đó ở form thêm lead.
    // referral + walk_in: ai cũng thấy (giá trị null = mọi user tạo lead).
    public const SOURCE_PERMISSIONS = [
        self::SOURCE_MARKETING => 'lead.distribute_team',
        self::SOURCE_DATA_COLD => 'lead.distribute_team',
        self::SOURCE_BDM => 'lead.distribute_team',
        self::SOURCE_CTV => 'lead.distribute_ctv',
        self::SOURCE_REFERRAL => null,
        self::SOURCE_WALK_IN => null,
    ];

    // Phase 6.6+ — trạng thái đặt lịch booking (khách đồng ý gặp)
    public const BOOKING_NOT_BOOKED = 'not_booked';
    public const BOOKING_BOOKED = 'booked';
    public const BOOKING_RESCHEDULED = 'rescheduled';

    public const BOOKING_STATUSES = [
        self::BOOKING_NOT_BOOKED => 'Chưa đặt',
        self::BOOKING_BOOKED => 'Đã đặt',
        self::BOOKING_RESCHEDULED => 'Hẹn lại',
    ];

    public const APPROVAL_NONE = 'none';
    public const APPROVAL_PENDING = 'pending';
    public const APPROVAL_APPROVED = 'approved';
    public const APPROVAL_REJECTED = 'rejected';

    protected function casts(): array
    {
        return [
            'received_date' => 'date',
            'assigned_at' => 'datetime',
            'last_care_at' => 'datetime',
            'birthday' => 'date',
            'treatment_1' => 'date',
            'treatment_2' => 'date',
            'treatment_3' => 'date',
            'treatment_4' => 'date',
            'approved_at' => 'datetime',
            'overdue_marked_at' => 'datetime',
            'recall_at' => 'datetime',
            'is_permanent_assignment' => 'boolean',
        ];
    }

    /** Danh sách nguồn user hiện tại được phép chọn khi tạo lead. */
    public static function allowedSourceGroupsFor(User $user): array
    {
        $out = [];
        foreach (self::SOURCE_GROUPS as $key => $label) {
            $perm = self::SOURCE_PERMISSIONS[$key];
            if ($perm === null || $user->hasPermission($perm)) {
                $out[$key] = $label;
            }
        }

        return $out;
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

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class, 'doctor_id');
    }

    public function consultant1(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class, 'consultant_1_id');
    }

    public function consultant2(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class, 'consultant_2_id');
    }

    public function consultant3(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class, 'consultant_3_id');
    }

    public function performingDoctor(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class, 'performing_doctor_id');
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

    public function upsells(): HasMany
    {
        return $this->hasMany(LeadUpsell::class);
    }

    /**
     * Mã KH = KH-{id} (core cố định, pad tối thiểu 3 số) + các đoạn "mã phân loại"
     * do classification field của công ty→phòng→nhóm sinh ra (xem CustomField).
     * Gọi sau khi lead có id; idempotent; gọi lại khi đổi giá trị/đổi phòng.
     */
    public function generateCode(): string
    {
        $code = 'KH-' . str_pad((string) $this->id, 3, '0', STR_PAD_LEFT);
        foreach (CustomField::codeSegmentsFor($this) as $segment) {
            $code .= '-' . $segment;
        }

        if ($this->code !== $code) {
            $this->forceFill(['code' => $code])->save();
        }

        return $code;
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
            $memberOrgIds = $user->memberOrgUnitIds();
            if ($orgIds !== []) {
                $q->orWhereIn('org_unit_id', $orgIds);
                // Lead kho chung (chưa chia) cũng visible cho user có scope tổ chức
                $q->orWhere(fn (Builder $sub) => $sub->whereNull('org_unit_id')->where('pool_level', self::POOL_COMMON));
            }
            // Kho chung phòng/team: thành viên (org của mình + cấp cha) thấy được, dù scope self
            if ($memberOrgIds !== []) {
                $q->orWhere(fn (Builder $sub) => $sub->where('pool_level', self::POOL_TEAM)->whereIn('org_unit_id', $memberOrgIds));
            }
            if ($user->hasSelfScope()) {
                $q->orWhere('owner_id', $user->id)->orWhere('receiver_id', $user->id);
            }
            if ($orgIds === [] && $memberOrgIds === [] && ! $user->hasSelfScope()) {
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

        if ($this->org_unit_id === null) {
            return false;
        }

        // Kho chung phòng/team: thành viên phòng/team (hoặc cấp cha) thấy được
        if ($this->pool_level === self::POOL_TEAM && in_array($this->org_unit_id, $user->memberOrgUnitIds(), true)) {
            return true;
        }

        return $user->canSeeOrgUnit($this->org_unit_id);
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
