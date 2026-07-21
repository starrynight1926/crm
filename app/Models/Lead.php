<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'raw_lead_id', 'code', 'received_date',
    'insight', 'link', 'name', 'phone', 'region', 'classification',
    'status_1', 'status_2', 'note',
    'pool_level', 'owner_id', 'receiver_id', 'org_unit_id',
    'past_org_unit_ids',
    'facility_id', 'doctor_id', 'consultant_1_id', 'consultant_2_id', 'consultant_3_id',
    'assigned_at', 'last_care_at',
    'birthday', 'address', 'medical_history', 'occupation',
    'service_name',
    'potential_service',
    // Phase 6.6
    'source_group', 'approval_status', 'approval_by', 'approved_at',
    'overdue_marked_at', 'recall_at', 'is_permanent_assignment',
    'booking_status', 'booking_ma', 'booked_at',
    // Phase 6.8
    'pipeline_phase', 'pipeline_status',
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

    // Mã nối vào mã KH theo nhóm nguồn: KH-{id}-{SOURCE_CODE}-...
    public const SOURCE_GROUP_CODES = [
        self::SOURCE_MARKETING => 'MKT',
        self::SOURCE_DATA_COLD => 'COLD',
        self::SOURCE_BDM => 'BDM',
        self::SOURCE_REFERRAL => 'REF',
        self::SOURCE_CTV => 'CTV',
        self::SOURCE_WALK_IN => 'WI',
    ];

    public function sourceGroupCode(): string
    {
        return self::SOURCE_GROUP_CODES[$this->source_group] ?? '';
    }

    // Permission tương ứng cần có để thấy nhóm nguồn đó ở form thêm lead.
    // referral + walk_in: ai cũng thấy (giá trị null = mọi user tạo lead).
    public const SOURCE_PERMISSIONS = [
        self::SOURCE_MARKETING => 'lead.distribute_booking',
        self::SOURCE_DATA_COLD => 'lead.distribute_booking',
        self::SOURCE_BDM => 'lead.distribute_booking',
        self::SOURCE_CTV => 'lead.distribute_ctv',
        self::SOURCE_REFERRAL => null,
        self::SOURCE_WALK_IN => null,
    ];

    // Phase 6.8 — Trục lifecycle: phase (giai đoạn) + status (trạng thái trong giai đoạn)
    public const PHASE_BOOKING = 'booking';
    public const PHASE_SALE = 'sale';

    public const PHASES = [
        self::PHASE_BOOKING => 'Booking',
        self::PHASE_SALE => 'Sale',
    ];

    public const PSTATUS_WAITING = 'waiting_distribute';
    public const PSTATUS_IN_CARE = 'in_care';

    public const PIPELINE_STATUSES = [
        self::PSTATUS_WAITING => 'Chờ chia',
        self::PSTATUS_IN_CARE => 'Đang chăm sóc',
    ];

    /** Perm cần có để sửa info cá nhân (cột trái) của lead — theo phase hiện tại. */
    public function personalInfoPermission(): string
    {
        return $this->pipeline_phase === self::PHASE_BOOKING
            ? 'lead.update_booking'
            : 'lead.update_sale';
    }

    /** Gate: user này có được sửa info cá nhân của lead không (đúng phase + trong scope). */
    public function canEditPersonalInfo(User $user): bool
    {
        return $user->hasPermission($this->personalInfoPermission()) && $this->isVisibleTo($user);
    }

    /** Nhãn phase-status đọc được (VD "Booking · Chờ chia"). */
    public function pipelineLabel(): string
    {
        return (self::PHASES[$this->pipeline_phase] ?? $this->pipeline_phase)
            . ' · ' . (self::PIPELINE_STATUSES[$this->pipeline_status] ?? $this->pipeline_status);
    }

    /**
     * Chuyển sang phase Sale, trạng thái Chờ chia — bấm khi team booking chốt "khách đồng ý gặp".
     * Team CM sale sẽ chia số ở kho Sale sau đó.
     */
    public function moveToSaleWaiting(): void
    {
        $this->update([
            'pipeline_phase'  => self::PHASE_SALE,
            'pipeline_status' => self::PSTATUS_WAITING,
        ]);
    }

    /** Suy ra phase/status khởi tạo cho lead mới dựa trên source_group + owner_id. */
    public static function initialPipelineFor(?string $sourceGroup, ?int $ownerId): array
    {
        // Nhóm 1-3 (Marketing / Data lạnh / BDM) → vào kho booking, chờ QL booking chia.
        if (in_array($sourceGroup, [self::SOURCE_MARKETING, self::SOURCE_DATA_COLD, self::SOURCE_BDM], true)) {
            return [self::PHASE_BOOKING, self::PSTATUS_WAITING];
        }
        // Nhóm 4 (Bạn giới thiệu) & 5 (CTV): đã có owner từ lúc up → sale/in_care.
        // Nếu chưa có owner (nhóm 6 Khách tự đến, hoặc CTV chưa chia) → sale/waiting.
        return $ownerId
            ? [self::PHASE_SALE, self::PSTATUS_IN_CARE]
            : [self::PHASE_SALE, self::PSTATUS_WAITING];
    }

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
            'approved_at' => 'datetime',
            'overdue_marked_at' => 'datetime',
            'recall_at' => 'datetime',
            'is_permanent_assignment' => 'boolean',
            'booked_at' => 'datetime',
            'past_org_unit_ids' => 'array',
        ];
    }

    /**
     * Tự động ghi lại các org đã từng giữ lead (past handler).
     * Mỗi lần org_unit_id đổi → append giá trị CŨ vào past_org_unit_ids (unique, bỏ null).
     * Nhờ đó user thuộc team booking cũ vẫn thấy lead sau khi lead chuyển sang team sale.
     */
    protected static function booted(): void
    {
        static::saving(function (Lead $lead) {
            if (! $lead->isDirty('org_unit_id')) {
                return;
            }
            $oldOrgId = $lead->getOriginal('org_unit_id');
            if ($oldOrgId === null) {
                return;
            }
            $past = $lead->past_org_unit_ids ?? [];
            if (! in_array($oldOrgId, $past, true)) {
                $past[] = (int) $oldOrgId;
                $lead->past_org_unit_ids = $past;
            }
        });
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

    // Phase 6.9 — Chuyên viên tư vấn = user (team sale), không phải staff_member.
    public function consultant1(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consultant_1_id');
    }

    public function consultant2(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consultant_2_id');
    }

    public function consultant3(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consultant_3_id');
    }

    public function treatments(): HasMany
    {
        return $this->hasMany(LeadTreatment::class)->orderBy('sequence');
    }

    /**
     * Phase 6.21 — Accessor cho page/camp: đọc từ lead_custom_values qua relation `field`.
     * Field có thể ở nhiều cấp (công ty / phòng) — pick value đầu tiên match key.
     */
    public function customValueByKey(string $key): ?string
    {
        $this->loadMissing('customValues.field');
        foreach ($this->customValues as $v) {
            if ($v->field?->key === $key) {
                return $v->value;
            }
        }
        return null;
    }

    /** Reset accessor cache (dùng cho test). @deprecated giữ để không phá test cũ. */
    protected static array $_coreCustomFieldIds = [];

    /** Attribute magic: $lead->page và $lead->camp giờ đọc từ custom_values (thay cột core đã drop). */
    public function getPageAttribute(): ?string
    {
        return $this->customValueByKey('page');
    }

    public function getCampAttribute(): ?string
    {
        return $this->customValueByKey('camp');
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
        if ($src = $this->sourceGroupCode()) {
            $code .= '-' . $src;
        }
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
            // Phase 6.23 — Kho chung công ty chỉ visible với user có perm `lead.view_pool`.
            $canSeePool = $user->hasPermission('lead.view_pool');
            if ($orgIds !== []) {
                $q->orWhereIn('org_unit_id', $orgIds);
                if ($canSeePool) {
                    $q->orWhere(fn (Builder $sub) => $sub->whereNull('org_unit_id')->where('pool_level', self::POOL_COMMON));
                }
            }
            // Kho chung phòng/team: thành viên (org của mình + cấp cha) thấy được, dù scope self
            if ($memberOrgIds !== []) {
                $q->orWhere(fn (Builder $sub) => $sub->where('pool_level', self::POOL_TEAM)->whereIn('org_unit_id', $memberOrgIds));
            }
            // Past handler: lead đã từng ở org của user → user vẫn thấy (read-only + add note).
            $pastOrgIds = array_values(array_unique(array_merge($orgIds ?: [], $memberOrgIds ?: [])));
            if ($pastOrgIds !== []) {
                foreach ($pastOrgIds as $oid) {
                    $q->orWhereJsonContains('past_org_unit_ids', (int) $oid);
                }
            }
            if ($user->hasSelfScope()) {
                $q->orWhere('owner_id', $user->id)->orWhere('receiver_id', $user->id);
            }
            if ($orgIds === [] && $memberOrgIds === [] && ! $user->hasSelfScope()) {
                $q->whereRaw('1 = 0');
            }
        });
    }

    /**
     * User có phải là past handler của lead không (từng thuộc org đã giữ lead, nhưng không thuộc org hiện tại).
     * Dùng để phân biệt "canFullyEdit" (org hiện tại) vs "canAddNote" (past handler).
     */
    public function isPastHandlerFor(User $user): bool
    {
        $past = $this->past_org_unit_ids ?? [];
        if ($past === []) return false;
        $userOrgs = array_values(array_unique(array_merge(
            $user->visibleOrgUnitIds() ?: [],
            $user->memberOrgUnitIds() ?: [],
        )));
        return array_intersect($past, $userOrgs) !== [];
    }

    /** Lead này có nằm trong scope của user không (dùng cho chi tiết / mask SĐT). */
    public function isVisibleTo(User $user): bool
    {
        if ($user->hasSelfScope() && ($this->owner_id === $user->id || $this->receiver_id === $user->id)) {
            return true;
        }

        if ($this->org_unit_id === null) {
            // Phase 6.23 — Kho chung công ty chỉ visible với user có perm `lead.view_pool`.
            return $this->pool_level === self::POOL_COMMON && $user->hasPermission('lead.view_pool');
        }

        // Kho chung phòng/team: thành viên phòng/team (hoặc cấp cha) thấy được
        if ($this->pool_level === self::POOL_TEAM && in_array($this->org_unit_id, $user->memberOrgUnitIds(), true)) {
            return true;
        }

        if ($user->canSeeOrgUnit($this->org_unit_id)) {
            return true;
        }

        // Past handler: user thuộc org đã từng giữ lead → thấy read-only + add note.
        return $this->isPastHandlerFor($user);
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
