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
    'pool_level', 'owner_id', 'receiver_id', 'imported_by', 'org_unit_id', 'pool_unit_id',
    'past_org_unit_ids',
    'facility_id', 'doctor_id', 'consultant_1_id', 'consultant_2_id', 'consultant_3_id',
    'assigned_at', 'last_care_at',
    'birthday', 'address', 'medical_history', 'occupation', 'cccd_image_path', 'cccd_number',
    'service_name',
    'potential_service',
    // Phase 6.6
    'source_group', 'approval_status', 'approval_by', 'approved_at',
    'overdue_marked_at', 'recall_at', 'mkt_recall_at', 'is_permanent_assignment', 'skip_recall',
    'booking_status', 'booking_ma', 'booked_at',
    // Phase 6.8
    'pipeline_phase', 'pipeline_status',
    // Phase 6.21 — Customer Flow 7 phase (2026-07-30)
    'phase', 'is_first_visit',
])]
class Lead extends Model
{
    use SoftDeletes;

    // Phase 6.21 — default cho cột mới (Laravel không auto-refresh sau create nếu không truyền)
    protected $attributes = [
        'phase' => 1,
        'is_first_visit' => true,
    ];

    protected $casts = [
        'phase' => 'integer',
        'is_first_visit' => 'boolean',
    ];

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

    // 7 nhóm nguồn — chia 3 luồng xử lý:
    //   Nhóm 1 (qua Team Booking): MKT, MKT_BR, BDM
    //   Nhóm 2 (lối tắt qua CM Sale): BOD, SA, BA
    //   Nhóm 3 (khách đến trực tiếp): WI
    public const SOURCE_MKT = 'mkt';
    public const SOURCE_MKT_BR = 'mkt_br';
    public const SOURCE_BDM = 'bdm';
    public const SOURCE_BOD = 'bod';
    public const SOURCE_SA = 'sa';
    public const SOURCE_BA = 'ba';
    public const SOURCE_WI = 'wi';

    public const SOURCE_GROUPS = [
        self::SOURCE_MKT => 'Marketing',
        self::SOURCE_MKT_BR => 'Marketing BR',
        self::SOURCE_BDM => 'BDM',
        self::SOURCE_BOD => 'Ban lãnh đạo giới thiệu',
        self::SOURCE_SA => 'Sale Appointment',
        self::SOURCE_BA => 'Booking Appointment',
        self::SOURCE_WI => 'Walk-in',
    ];

    // Mã nối vào mã KH theo nhóm nguồn: KH-{id}-{SOURCE_CODE}-...
    public const SOURCE_GROUP_CODES = [
        self::SOURCE_MKT => 'MKT',
        self::SOURCE_MKT_BR => 'MKTBR',
        self::SOURCE_BDM => 'BDM',
        self::SOURCE_BOD => 'BOD',
        self::SOURCE_SA => 'SA',
        self::SOURCE_BA => 'BA',
        self::SOURCE_WI => 'WI',
    ];

    public function sourceGroupCode(): string
    {
        return self::SOURCE_GROUP_CODES[$this->source_group] ?? '';
    }

    // Permission cần có để thấy nhóm nguồn đó ở form thêm lead.
    // 2026-08-09: refactor 1-1 với 7 nguồn để admin tick UI theo tên nguồn (không cần map role→group).
    public const SOURCE_PERMISSIONS = [
        self::SOURCE_MKT    => 'source.up.mkt',
        self::SOURCE_MKT_BR => 'source.up.mkt_br',
        self::SOURCE_SA     => 'source.up.sa',
        self::SOURCE_BA     => 'source.up.ba',
        self::SOURCE_BDM    => 'source.up.bdm',
        self::SOURCE_BOD    => 'source.up.bod',
        self::SOURCE_WI     => 'source.up.wi',
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

    /**
     * True nếu lead thuộc nhóm nguồn "sale nhận trực tiếp" (không qua team booking):
     * BOD / SA / BA (lối tắt qua CM Sale) + WI (walk-in).
     */
    public function isDirectSaleSource(): bool
    {
        return in_array($this->source_group, [
            self::SOURCE_BOD, self::SOURCE_SA, self::SOURCE_BA, self::SOURCE_WI,
        ], true);
    }

    /**
     * True nếu $user là chủ sở hữu trực tiếp của lead (owner hoặc receiver).
     * Dùng cho các override quyền theo quan hệ sở hữu.
     */
    public function isOwnedBy(User $user): bool
    {
        return $this->owner_id === $user->id || $this->receiver_id === $user->id;
    }

    /**
     * 2026-08-03 fix bug #5: user chỉ được gán làm CV (booking_log_consultants) của lead này,
     * KHÔNG có role quản lý (Admin/CM có `lead.distribute_sale`) và KHÔNG phải người nhập lead.
     * CV-only chỉ được viết bình luận + phản hồi khách; mọi form khác readonly.
     */
    public function isCvViewerOnly(User $user): bool
    {
        if ($user->hasPermission('lead.distribute_sale')) return false;
        if ($user->hasPermission('lead.distribute')) return false;
        if ($this->imported_by === $user->id && $user->hasPermission('lead.create')) return false;
        return \App\Models\BookingLog::where('lead_id', $this->id)
            ->whereHas('consultants', fn ($q) => $q->where('users.id', $user->id))
            ->exists();
    }

    /** Gate: user này có được sửa info cá nhân của lead không (đúng phase + trong scope). */
    public function canEditPersonalInfo(User $user): bool
    {
        if (! $this->isVisibleTo($user)) return false;
        // CV-only luôn readonly (dù họ có thể là owner do CV1 handoff).
        if ($this->isCvViewerOnly($user)) return false;
        if ($user->hasPermission($this->personalInfoPermission())) return true;
        // Override 1: người nhập lead (Trực Page, Sale, Tele tự up) — luôn được sửa lead mình up,
        // dù role không có update_booking/update_sale. Fix Wave 1 #2 (2026-07-31).
        if ($this->imported_by && $this->imported_by === $user->id && $user->hasPermission('lead.create')) return true;
        // Override 2: nguồn "sale nhận trực tiếp" (BOD/SA/BA/WI) → owner tự sửa được dù role không có update_sale/update_booking.
        return $this->isDirectSaleSource() && $this->isOwnedBy($user) && $user->hasPermission('lead.update');
    }

    /**
     * Gate: user có được mở màn Cập nhật (dù chỉ readonly) không.
     * Phase 6.20 — Team booking chỉ có lead.read_booking (readonly) — vẫn phải mở được form để bấm Đặt booking.
     */
    public function canOpenEditForm(User $user): bool
    {
        if (! $this->isVisibleTo($user)) return false;
        if ($this->canEditPersonalInfo($user)) return true;
        // 2026-08-05: owner đang giữ lead luôn mở được form (ghi call log ở phase 2,
        // booking log ở phase 4). Trước đó chỉ Team booking có read_booking mới mở được
        // → Sale bị đá về /leads/{id} show không ghi được cuộc gọi.
        if ($this->owner_id !== null && $this->owner_id === $user->id) return true;
        // Sale tiếp đón (CV1 booking da_xac_nhan — sau khi Admin BO duyệt) hoặc CV1 cũ (past) — mở form.
        // Form tự readonly toàn phase với past CV qua flag phaseLocked.
        $isCvNowOrPast = $this->bookingLogs()
            ->where(function ($q) use ($user) {
                $q->whereJsonContains('past_consultant_user_ids', (int) $user->id)
                  ->orWhere(function ($q2) use ($user) {
                      $q2->where('status', 'da_xac_nhan')
                         ->whereHas('consultants', fn ($cq) => $cq
                             ->where('users.id', $user->id)
                             ->where('booking_log_consultants.position', 1));
                  });
            })->exists();
        if ($isCvNowOrPast) return true;
        return $this->pipeline_phase === self::PHASE_BOOKING && $user->hasPermission('lead.read_booking');
    }

    /**
     * Gate: user có được bấm nút "Đặt booking" (chuyển sang lara-sbooking) không.
     * Override: nguồn "sale nhận trực tiếp" + owner → luôn được bấm (họ tự đặt lịch cho khách).
     */
    public function canBookAction(User $user): bool
    {
        if (! $this->isVisibleTo($user)) return false;
        if ($user->hasPermission('lead.book_action')) return true;
        return $this->isDirectSaleSource() && $this->isOwnedBy($user);
    }

    /**
     * Slug cơ sở bên lara-sbooking dùng cho URL nút "Đặt booking".
     * Thứ tự fallback:
     *   1) Lead có facility → dùng slug facility (hoặc parent facility nếu là dept).
     *   2) Lead chưa gán facility → suy từ branch org của owner (mapping code branch → slug facility cùng tên).
     */
    public function resolvedBookingSlug(): ?string
    {
        $f = $this->facility;
        if ($f) {
            $slug = $f->booking_co_so_slug ?: $f->parent?->booking_co_so_slug;
            if ($slug) return $slug;
        }
        $owner = $this->owner ?? $this->receiver;
        if (! $owner) return null;
        foreach ($owner->assignments()->with('orgUnit')->get() as $a) {
            $org = $a->orgUnit;
            while ($org?->parent && $org->parent->code !== 'company') {
                $org = $org->parent;
            }
            if (! $org) continue;
            $facilityName = match ($org->code) {
                'branch-hn'  => 'Hà Nội',
                'branch-hcm' => 'HCM',
                'branch-dn'  => 'Đà Nẵng',
                default      => null,
            };
            if (! $facilityName) continue;
            $slug = \App\Models\Facility::whereNull('parent_id')->where('name', $facilityName)->value('booking_co_so_slug');
            if ($slug) return $slug;
        }
        return null;
    }

    /** Nhãn phase-status đọc được (VD "Booking · Chờ CM booking chia"). */
    public function pipelineLabel(): string
    {
        if ($this->owner_id === null && $this->pool_level === self::POOL_COMMON && $this->receiver_id === null) {
            return 'Kho chung · Chưa chia';
        }
        // 2026-08-10: pool_level=TEAM + chưa có owner → "Kho chung <tên kho> · Chưa chia".
        //   Sau khi admin chia hàng loạt từ /distribution/pools "về kho HN" nhưng chưa gán sale.
        if ($this->owner_id === null && $this->pool_level === self::POOL_TEAM) {
            $poolName = $this->poolUnit?->name ?? $this->orgUnit?->name;
            return $poolName ? "Kho chung {$poolName} · Chưa chia" : 'Kho team · Chưa chia';
        }

        // 2026-08-03: sau khi Kết thúc phase 3 (Gọi điện) — Tele đã care xong, chờ book thăm khám.
        //   Điều kiện: đã có closure phase 3 + chưa book (booking_status = not_booked / rescheduled) + phase >= 4.
        if ((int) $this->phase >= self::CF_PHASE_BOOKING
            && in_array($this->booking_status, [self::BOOKING_NOT_BOOKED, self::BOOKING_RESCHEDULED], true)
            && $this->phaseClosures->contains('phase', self::CF_PHASE_CALL)) {
            return 'Chờ book thăm khám';
        }

        $phase = self::PHASES[$this->pipeline_phase] ?? $this->pipeline_phase;
        if ($this->pipeline_status === self::PSTATUS_WAITING) {
            // 2026-08-09: MKT/MKT_BR/BDM mode pool → chờ tele nhặt/cập nhật (không còn CM booking chia trong flow mới).
            if ($this->pipeline_phase === self::PHASE_BOOKING
                && in_array($this->source_group, [self::SOURCE_MKT, self::SOURCE_MKT_BR, self::SOURCE_BDM], true)) {
                $statusLabel = 'Chờ tele cập nhật';
            } else {
                $statusLabel = $this->pipeline_phase === self::PHASE_BOOKING
                    ? 'Chờ CM booking chia'
                    : 'Chờ CM sale chia';
            }
        } else {
            $statusLabel = self::PIPELINE_STATUSES[$this->pipeline_status] ?? $this->pipeline_status;
        }
        return $phase . ' · ' . $statusLabel;
    }

    /**
     * Chuyển sang phase Sale, trạng thái Chờ chia — bấm khi team booking chốt "khách đồng ý gặp".
     * Team CM sale sẽ chia số ở kho Sale sau đó.
     */
    public function moveToSaleWaiting(): void
    {
        // Rule cứng: chỉ được bàn giao sang phase Sale khi khách đã đặt booking.
        // Áp cho mọi caller (kể cả sale/tele tự care) — tránh gán tên sale khi khách chưa book.
        if ($this->booking_status !== self::BOOKING_BOOKED) {
            throw new \DomainException('Chưa đặt booking cho khách. Đặt booking xong mới được chuyển sang phase Sale.');
        }

        // Đưa lead về kho chung để CM sale chia số ở kho Sale.
        // Owner cũ (booking user) → chuyển vào receiver_id để giữ lịch sử người vừa bàn giao;
        // owner_id null + pool_level=common → CM sale thấy trong pool để chia.
        $prevOwner = $this->owner_id;
        $this->update([
            'pipeline_phase'  => self::PHASE_SALE,
            'pipeline_status' => self::PSTATUS_WAITING,
            'receiver_id'     => $prevOwner,
            'owner_id'        => null,
            'pool_level'      => self::POOL_COMMON,
            'org_unit_id'     => null,
            'assigned_at'     => null,
        ]);
    }

    /** Suy ra phase/status khởi tạo cho lead mới dựa trên source_group + owner_id. */
    public static function initialPipelineFor(?string $sourceGroup, ?int $ownerId): array
    {
        // Nhóm 1 (MKT / MKT_BR / BDM) → vào kho booking.
        // 2026-08-09: MKT_BR đi cùng luồng MKT (auto MKT List UPS).
        if (in_array($sourceGroup, [self::SOURCE_MKT, self::SOURCE_MKT_BR, self::SOURCE_BDM], true)) {
            return [self::PHASE_BOOKING, $ownerId ? self::PSTATUS_IN_CARE : self::PSTATUS_WAITING];
        }
        // Nhóm direct sale (BOD / SA / BA / WI): sale nhận trực tiếp.
        // Có owner → sale/in_care; chưa có → sale/waiting (chờ CM sale chia).
        return $ownerId
            ? [self::PHASE_SALE, self::PSTATUS_IN_CARE]
            : [self::PHASE_SALE, self::PSTATUS_WAITING];
    }

    // Phase 6.6+ — trạng thái đặt lịch booking (khách đồng ý gặp)
    public const BOOKING_NOT_BOOKED = 'not_booked';
    public const BOOKING_BOOKED = 'booked';
    public const BOOKING_RESCHEDULED = 'rescheduled';
    // Phase 6.21 — 4 trạng thái sync từ lara-sbooking (trang_thai_khach + trang_thai=da_xong).
    public const BOOKING_KHACH_DA_TOI = 'khach_da_toi';
    public const BOOKING_KHACH_TOI_TRE = 'khach_toi_tre';
    public const BOOKING_KHACH_HUY = 'khach_huy';
    public const BOOKING_DA_XONG = 'da_xong';

    public const BOOKING_STATUSES = [
        self::BOOKING_NOT_BOOKED    => 'Chưa đặt',
        self::BOOKING_BOOKED        => 'Đã đặt',
        self::BOOKING_RESCHEDULED   => 'Hẹn lại',
        self::BOOKING_KHACH_DA_TOI  => 'Khách đã tới',
        self::BOOKING_KHACH_TOI_TRE => 'Khách tới trễ',
        self::BOOKING_KHACH_HUY     => 'Khách hủy',
        self::BOOKING_DA_XONG       => 'Đã hoàn thành',
    ];

    /** Emoji cho từng trạng thái booking (hiển thị badge — không cần font ngoài). */
    public const BOOKING_STATUS_ICONS = [
        self::BOOKING_NOT_BOOKED    => '🕐',
        self::BOOKING_BOOKED        => '📅',
        self::BOOKING_RESCHEDULED   => '🔄',
        self::BOOKING_KHACH_DA_TOI  => '✅',
        self::BOOKING_KHACH_TOI_TRE => '⏰',
        self::BOOKING_KHACH_HUY     => '❌',
        self::BOOKING_DA_XONG       => '🎉',
    ];

    /** Class Tailwind cho badge theo trạng thái. */
    public const BOOKING_STATUS_COLORS = [
        self::BOOKING_NOT_BOOKED    => 'bg-ink/5 text-ink/50 border-ink/10',
        self::BOOKING_BOOKED        => 'bg-blue-100 text-blue-800 border-blue-200',
        self::BOOKING_RESCHEDULED   => 'bg-amber-100 text-amber-800 border-amber-200',
        self::BOOKING_KHACH_DA_TOI  => 'bg-green-100 text-green-800 border-green-200',
        self::BOOKING_KHACH_TOI_TRE => 'bg-amber-100 text-amber-800 border-amber-200',
        self::BOOKING_KHACH_HUY     => 'bg-red-100 text-red-800 border-red-200',
        self::BOOKING_DA_XONG       => 'bg-purple-100 text-purple-800 border-purple-200',
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
            'mkt_recall_at' => 'datetime',
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

        // B1b (2026-08-14) — Ghi lịch sử ownership tự động.
        // Không xâm nhập vào từng service đổi owner_id — hook Eloquent event là đủ.
        static::updated(function (Lead $lead) {
            if (! $lead->wasChanged('owner_id')) {
                return;
            }
            $prev = $lead->getOriginal('owner_id');
            $new = $lead->owner_id;

            if ($prev) {
                LeadOwnershipHistory::where('lead_id', $lead->id)
                    ->where('user_id', $prev)
                    ->whereNull('released_at')
                    ->update(['released_at' => now(), 'updated_at' => now()]);
            }
            if ($new) {
                LeadOwnershipHistory::create([
                    'lead_id' => $lead->id,
                    'user_id' => $new,
                    'assigned_at' => now(),
                ]);
            }
        });

        static::created(function (Lead $lead) {
            if ($lead->owner_id) {
                LeadOwnershipHistory::create([
                    'lead_id' => $lead->id,
                    'user_id' => $lead->owner_id,
                    'assigned_at' => now(),
                ]);
                $lead->bumpMktRecallOnAssign();
            }
        });

        // B1d (2026-08-14) — MKT recall: khi lead nguồn MKT được gán/hủy owner
        // thì set/clear mkt_recall_at tương ứng.
        static::updated(function (Lead $lead) {
            if (! $lead->wasChanged('owner_id')) return;
            if ($lead->source_group !== self::SOURCE_MKT) return;
            if ($lead->owner_id === null) {
                $lead->newQuery()->whereKey($lead->id)->update(['mkt_recall_at' => null]);
            } else {
                $lead->bumpMktRecallOnAssign();
            }
        });
    }

    // ---------- B1d: MKT recall helpers ----------

    /** Vừa gán sale MKT → deadline 1 ngày để ghi cuộc gọi.
     *  Không rút ngắn nếu đã có deadline xa hơn (VD ownership transfer sau khi tạo booking). */
    public function bumpMktRecallOnAssign(): void
    {
        if ($this->source_group !== self::SOURCE_MKT) return;
        $target = now()->addDay();
        if (! $this->mkt_recall_at || $this->mkt_recall_at->lt($target)) {
            $this->newQuery()->whereKey($this->id)->update(['mkt_recall_at' => $target]);
        }
    }

    /** Vừa ghi cuộc gọi → deadline 3 ngày để tiến triển phân loại/kết quả. */
    public function bumpMktRecallOnCall(): void
    {
        if ($this->source_group !== self::SOURCE_MKT || $this->owner_id === null) return;
        $target = now()->addDays(3);
        if (! $this->mkt_recall_at || $this->mkt_recall_at->lt($target)) {
            $this->newQuery()->whereKey($this->id)->update(['mkt_recall_at' => $target]);
        }
    }

    /** Vừa tạo booking → deadline 30 ngày để lịch tiến triển. */
    public function bumpMktRecallOnBooking(): void
    {
        if ($this->source_group !== self::SOURCE_MKT || $this->owner_id === null) return;
        $target = now()->addDays(30);
        if (! $this->mkt_recall_at || $this->mkt_recall_at->lt($target)) {
            $this->newQuery()->whereKey($this->id)->update(['mkt_recall_at' => $target]);
        }
    }

    /** Danh sách nguồn user hiện tại được phép chọn khi tạo lead. */
    public static function allowedSourceGroupsFor(User $user): array
    {
        if ($user->hasPermission('lead.source_all')) {
            return self::SOURCE_GROUPS;
        }
        $bucketOverride = self::todayBucketSourceOverride($user);
        $out = [];
        foreach (self::SOURCE_GROUPS as $key => $label) {
            if ($bucketOverride !== null && array_key_exists($key, $bucketOverride)) {
                if ($bucketOverride[$key]) {
                    $out[$key] = $label;
                }
                continue;
            }
            $perm = self::SOURCE_PERMISSIONS[$key];
            if ($perm === null || $user->hasPermission($perm)) {
                $out[$key] = $label;
            }
        }
        return $out;
    }

    /**
     * Rule 2026-08-09: SA / BA cho user Sale chịu chi phối bởi UPS bucket hôm nay.
     *  - Bucket MKT (Tele hôm nay) → up được SA, KHÔNG up BA.
     *  - Bucket A/B/C/OFF (Tiếp đón hôm nay) → up được BA, KHÔNG up SA.
     *  - Không có attendance hôm nay → không override (fallback perm mặc định).
     * Admin có `lead.source_all` bypass hoàn toàn (check trước khi gọi hàm này).
     *
     * @return array<string,bool>|null map [SOURCE_SA|SOURCE_BA => allow?]. Null = không áp bucket gate.
     */
    public static function todayBucketSourceOverride(User $user, ?string $date = null): ?array
    {
        $date = $date ?? now()->toDateString();
        $att = DailyAttendance::query()
            ->where('user_id', $user->id)
            ->whereDate('work_date', $date)
            ->first(['list_bucket', 'is_mkt']);

        if (! $att) return null;

        $inMkt = (bool) $att->is_mkt || $att->list_bucket === 'MKT';
        $inGreet = in_array($att->list_bucket, ['A', 'B', 'C', 'OFF'], true);
        if (! $inMkt && ! $inGreet) return null;

        // 2026-08-12: dual-list — sale có is_mkt VÀ bucket A/B/C/OFF cùng lúc → được up cả SA lẫn BA.
        return [
            self::SOURCE_SA => $inMkt,
            self::SOURCE_BA => $inGreet,
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    // B1a (2026-08-14) — Lịch sử ownership. Sale từng được giao lead này
    // vẫn được phép ghi cuộc gọi / tạo booking kể cả sau khi bị thu hồi.
    public function ownershipHistory(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LeadOwnershipHistory::class);
    }

    public function hasHistoricalOwnership(User $user): bool
    {
        return $this->ownershipHistory()->where('user_id', $user->id)->exists();
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    /**
     * 3 người theo dõi vòng đời lead: nhập → booking → sale.
     * Nguồn "sale nhận trực tiếp" (BOD/SA/BA/WI) không qua booking → slot booking null.
     * Nếu 1 người kiêm nhiều vai (VD Team ĐN xuyên suốt) → cùng user trong nhiều slot,
     * đúng luồng thực tế.
     *
     * @return array{importer: ?User, booking: ?User, sale: ?User}
     */
    public function handlerTrio(): array
    {
        $importer = $this->importer;

        // Chưa chia (kho chung + null owner) → không show booking/sale từ data cũ
        // (receiver_id có thể còn giá trị residual sau khi thu hồi).
        if ($this->owner_id === null && $this->pool_level === self::POOL_COMMON) {
            return ['importer' => $importer, 'booking' => null, 'sale' => null];
        }

        // 2026-08-11 fix v2: 1 user vừa Tele vừa Sale tùy UPS bucket → không thể phân biệt
        // theo pipeline_phase. Slot Sale = CV1 nếu đã tạo booking, else owner (Sale UPS-assigned
        // vẫn hiện tên trong cột "Sale tiếp đón" ngay khi chia).
        // Slot Tele = receiver (nếu khác owner) — người bàn giao trước, hoặc null nếu owner nhận trực tiếp.
        $sale = $this->consultant1 ?? $this->owner;
        $booking = ($this->receiver_id && $this->receiver_id !== $this->owner_id)
            ? $this->receiver
            : null;
        return ['importer' => $importer, 'booking' => $booking, 'sale' => $sale];
    }

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    /** Phase 6.24 — cây Kho số (pool_units) thay cho org_unit ở khía cạnh "kho lead". */
    public function poolUnit(): BelongsTo
    {
        return $this->belongsTo(PoolUnit::class);
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    /** Tên cơ sở gốc (root — parent_id null) — walk up từ facility trực tiếp. */
    public function rootFacilityName(): ?string
    {
        $f = $this->facility;
        while ($f?->parent) $f = $f->parent;
        return $f?->name;
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
            $poolUnitIds = $user->visiblePoolUnitIds();
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
            // Fix 2026-08-08 (Phase 6.24 regression): lead nằm trong Kho số (pool_unit_id) với
            // org_unit_id=null vẫn phải visible cho user có scope đến kho đó.
            if ($poolUnitIds !== []) {
                $q->orWhereIn('pool_unit_id', $poolUnitIds);
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
                // Sale tiếp đón (CV1 của booking đã Admin BO duyệt = da_xac_nhan).
                $q->orWhereHas('bookingLogs', function ($bq) use ($user) {
                    $bq->where('status', 'da_xac_nhan')
                        ->whereHas('consultants', fn ($cq) => $cq
                            ->where('users.id', $user->id)
                            ->where('booking_log_consultants.position', 1));
                });
                // CV1 cũ (đã bị đổi sang sale khác) — vẫn thấy lead nhưng ở chế độ chỉ đọc.
                $q->orWhereHas('bookingLogs', fn ($bq) => $bq
                    ->whereJsonContains('past_consultant_user_ids', (int) $user->id));
            }
            // Người nhập lead: luôn thấy được data mình đã up, kể cả sau khi engine
            // chia số cho sale khác (dùng để "trực page" theo dõi + chia lại nếu cần).
            $q->orWhere('imported_by', $user->id);
            // B1c (2026-08-14): past owner — user từng được giao lead này (kể cả đã bị thu hồi)
            // vẫn nhìn thấy lead để tiếp tục ghi cuộc gọi / tạo booking.
            $q->orWhereHas('ownershipHistory', fn ($hq) => $hq->where('user_id', $user->id));
            if ($orgIds === [] && $memberOrgIds === [] && ! $user->hasSelfScope()) {
                // whereRaw('1=0') cũ chặn hết — giờ vẫn phải OR nhánh imported_by ở trên,
                // nên không cần chặn cứng nữa. Nếu user không import gì → nhánh imported_by
                // rỗng tự nhiên.
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

    /**
     * User là CV1 cũ (đã bị đổi sang sale khác) của bất kỳ booking nào của lead này?
     * Dùng để force readonly: sale cũ vẫn thấy lead nhưng không sửa được.
     */
    public function isPastConsultantFor(User $user): bool
    {
        return $this->bookingLogs()
            ->whereJsonContains('past_consultant_user_ids', (int) $user->id)
            ->exists();
    }

    /** Lead này có nằm trong scope của user không (dùng cho chi tiết / mask SĐT). */
    public function isVisibleTo(User $user): bool
    {
        // Người nhập lead: luôn được xem đầy đủ (đã thấy dữ liệu khi up file).
        if ($this->imported_by === $user->id) {
            return true;
        }

        if ($user->hasSelfScope() && ($this->owner_id === $user->id || $this->receiver_id === $user->id)) {
            return true;
        }

        // B1c (2026-08-14): past owner (từng được giao lead này) vẫn nhìn thấy để ghi call/booking.
        if ($user->hasSelfScope() && $this->hasHistoricalOwnership($user)) {
            return true;
        }

        // Sale tiếp đón (CV1 booking da_xac_nhan — sau khi Admin BO duyệt) hoặc CV1 cũ (past).
        if ($user->hasSelfScope()) {
            $isCvNowOrPast = $this->bookingLogs()
                ->where(function ($q) use ($user) {
                    $q->whereJsonContains('past_consultant_user_ids', (int) $user->id)
                      ->orWhere(function ($q2) use ($user) {
                          $q2->where('status', 'da_xac_nhan')
                             ->whereHas('consultants', fn ($cq) => $cq
                                 ->where('users.id', $user->id)
                                 ->where('booking_log_consultants.position', 1));
                      });
                })->exists();
            if ($isCvNowOrPast) return true;
        }

        if ($this->org_unit_id === null) {
            // Fix 2026-08-08 (Phase 6.24 regression): lead trong Kho số → check pool_unit_id.
            if ($this->pool_unit_id && in_array((int) $this->pool_unit_id, $user->visiblePoolUnitIds(), true)) {
                return true;
            }
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

        // Fix 2026-08-08: chỉ strip '84' khi tổng độ dài = 11 (mã quốc gia +84 + 9 số mobile chuẩn).
        // Trước đó: '842798596' (9 số, bắt đầu 084 - đầu số Viettel) bị strip 84 nhầm → '02798596'.
        if (str_starts_with($digits, '84') && strlen($digits) === 11) {
            $digits = '0' . substr($digits, 2);
        }
        if (strlen($digits) === 9 && ! str_starts_with($digits, '0')) {
            $digits = '0' . $digits;
        }

        return preg_match('/^0\d{9}$/', $digits) ? $digits : null;
    }

    // =====================================================================
    // Phase 6.21 — Customer Flow 7 phase (2026-07-30)
    // Design: docs/design/customer_flow_30-07-2026.md
    // =====================================================================

    // ---------------------------------------------------------------------
    // Customer Flow 6 phase (Phase 6.23, 2026-08-03) — GỘP "Tạo mới" + "Chia số"
    // thành phase 1 duy nhất. Data cũ đã wipe (chỉ demo).
    // ---------------------------------------------------------------------
    public const CF_PHASE_NEW      = 1; // Tạo mới & Chia số (gộp)
    public const CF_PHASE_CALL     = 2;
    public const CF_PHASE_BOOKING  = 3;
    public const CF_PHASE_CHECKIN  = 4;
    public const CF_PHASE_SALES    = 5;
    public const CF_PHASE_SERVICE  = 6;

    public const CF_PHASE_LABELS = [
        1 => 'Tạo mới & Chia số',
        2 => 'Gọi điện',
        3 => 'Booking thăm khám',
        4 => 'Check-in',
        5 => 'Bán hàng',
        6 => 'Sử dụng dịch vụ',
    ];

    public const CF_PHASE_CLOSE_PERM = [
        1 => 'phase.close.new',       // đảm nhiệm luôn phần chia số (gộp)
        2 => 'phase.close.call',
        3 => 'phase.close.booking',
        4 => 'phase.close.checkin',
        // phase 5-6 chưa build
    ];

    public const CF_ROLLBACK_PERM = 'phase.rollback';

    public const CF_START_PHASE_BY_SOURCE = [
        self::SOURCE_MKT    => 1,
        self::SOURCE_MKT_BR => 3, // trước là 4 (Booking)
        self::SOURCE_BA     => 2, // trước là 3 (Call)
        self::SOURCE_SA     => 1, // trước là 2 (Distribute) → gộp về 1
        self::SOURCE_BDM    => 1,
        self::SOURCE_BOD    => 1,
        self::SOURCE_WI     => 1,
    ];

    // ---- Relations ----
    public function phaseClosures(): HasMany
    {
        return $this->hasMany(LeadPhaseClosure::class);
    }

    public function callLogs(): HasMany
    {
        return $this->hasMany(CallLog::class);
    }

    public function bookingLogs(): HasMany
    {
        return $this->hasMany(BookingLog::class);
    }

    // ---- Helpers ----

    /** Phase cao nhất được chốt khi user tạo lead bấm "Lưu — chốt N phase". */
    public function startPhase(): int
    {
        return self::CF_START_PHASE_BY_SOURCE[$this->source_group] ?? 1;
    }

    /** Phase thấp nhất mở thông khi bulk edit lần đầu. Khách quay lại: 2 (nếu start ≥ 2). */
    public function openFrom(): int
    {
        if (! $this->is_first_visit && $this->startPhase() >= 2) {
            return 2;
        }
        return 1;
    }

    /** True nếu lead đang ở chế độ "mở thông" (chưa từng bấm Lưu chốt lần đầu). */
    public function isBulkOpen(): bool
    {
        // Chế độ bulk: chưa có closure ở phase startPhase (nghĩa là chưa Lưu chốt).
        // Khách quay lại → cũng bulk (mở thông từ phase 3).
        $expectedClosed = max(1, $this->openFrom() - 1);
        return ! $this->phaseClosures()->where('phase', $this->startPhase())->exists();
    }

    /**
     * Trạng thái từng phase để render UI.
     * done | current | open | pending | skipped | notbuilt
     */
    public function phaseState(int $idx): string
    {
        if ($idx === 6 || $idx === 7) return 'notbuilt';

        $closed = $this->phaseClosures()->pluck('phase')->all();
        if (in_array($idx, $closed, true)) return 'done';

        $bulk = $this->isBulkOpen();
        $openFrom = $this->openFrom();
        $start = $this->startPhase();

        if ($bulk) {
            if ($idx < $openFrom) return 'skipped';
            if ($idx >= $openFrom && $idx <= $start) return 'open';
            return 'pending';
        }
        // Tuần tự: phase hiện tại = min phase chưa closed và > 0
        if ($idx === (int) $this->phase) return 'current';
        return 'pending';
    }

    /** Có được nhập vào phase $idx không (không phân biệt user — chỉ dựa trạng thái). */
    public function isPhaseEditable(int $idx): bool
    {
        $st = $this->phaseState($idx);
        return $st === 'open' || $st === 'current';
    }

    /** User có được ghi call_log không (owner hiện tại + past owner + QL Sale + Admin ops).
     *  B1c (2026-08-14): past owner (từng được giao) vẫn được ghi cuộc gọi ngay cả sau khi bị thu hồi. */
    public function canLogCall(User $user): bool
    {
        if (! $this->isVisibleTo($user)) return false;
        if ($user->hasPermission('phase.rollback')) return true;
        if ($this->owner_id !== null && $this->owner_id === $user->id) return true;
        return $this->hasHistoricalOwnership($user);
    }

    /**
     * User có được ghi check-in (phase 5) không.
     * Phase C1.b rev5 2026-08-01: đổi rule — chỉ user có perm `phase.close.checkin`
     * (Admin / Lễ tân) hoặc `phase.rollback`. Owner (sale) chỉ xem readonly vì
     * check-in phải do sbooking Admin bấm khi khách tới, hoặc lễ tân bấm tay.
     */
    public function canCheckin(User $user): bool
    {
        if (! $this->isVisibleTo($user)) return false;
        return $user->hasPermission('phase.rollback')
            || $user->hasPermission('phase.close.checkin');
    }

    /** User có được ghi booking_log không.
     *  B1c (2026-08-14): past owner cũng được tạo booking — sale bị thu hồi vẫn có thể đặt lịch,
     *  ownership sẽ transfer về người tạo booking (B1d/B6). */
    public function canLogBooking(User $user): bool
    {
        if (! $this->isVisibleTo($user)) return false;
        if ($user->hasPermission('phase.rollback')) return true;
        if ($user->hasPermission('phase.close.checkin') && $user->hasPermission('lead.book_action')) return true;
        if (! $user->hasPermission('lead.book_action')) return false;
        if ($this->owner_id !== null && $this->owner_id === $user->id) return true;
        return $this->hasHistoricalOwnership($user);
    }

    /**
     * Bulk save: chốt tất cả phase từ openFrom → startPhase, đưa lead.phase = startPhase + 1.
     * Yêu cầu user có TẤT CẢ perm phase.close.<slug> cho các phase mở thông.
     * Trả về danh sách phase đã đóng, throw exception nếu thiếu perm.
     */
    public function bulkSave(User $user, ?string $note = null): array
    {
        if (! $this->isBulkOpen()) {
            throw new \RuntimeException('Lead đã ra khỏi chế độ mở thông — không thể bulk save.');
        }
        $from = $this->openFrom();
        $to = $this->startPhase();
        // Check perm cho toàn cụm
        for ($p = $from; $p <= $to; $p++) {
            $perm = self::CF_PHASE_CLOSE_PERM[$p] ?? null;
            if ($perm && ! $user->hasPermission($perm)) {
                throw new \RuntimeException("Thiếu quyền {$perm} để chốt phase {$p}.");
            }
        }
        $closed = [];
        $now = now();
        for ($p = $from; $p <= $to; $p++) {
            LeadPhaseClosure::updateOrCreate(
                ['lead_id' => $this->id, 'phase' => $p],
                ['closed_by' => $user->id, 'closed_at' => $now, 'note' => $note]
            );
            $closed[] = $p;
        }
        $this->update(['phase' => min($to + 1, 4)]);
        return $closed;
    }

    /** Chốt 1 phase tuần tự. */
    public function closePhase(int $idx, User $user, ?string $note = null): void
    {
        // Phase C1.b rev11 2026-08-02: bulk mode → cho phép chốt từng phase riêng
        // trong khoảng [openFrom, startPhase]. Tuần tự → chỉ được chốt phase hiện tại.
        if ($this->isBulkOpen()) {
            if ($idx < $this->openFrom() || $idx > $this->startPhase()) {
                throw new \RuntimeException("Phase {$idx} nằm ngoài khoảng mở thông ({$this->openFrom()}→{$this->startPhase()}).");
            }
        } elseif ($idx !== (int) $this->phase) {
            throw new \RuntimeException("Chỉ chốt được phase hiện tại (đang ở phase {$this->phase}).");
        }
        $perm = self::CF_PHASE_CLOSE_PERM[$idx] ?? null;
        if ($perm && ! $user->hasPermission($perm)) {
            throw new \RuntimeException("Thiếu quyền {$perm}.");
        }
        LeadPhaseClosure::updateOrCreate(
            ['lead_id' => $this->id, 'phase' => $idx],
            ['closed_by' => $user->id, 'closed_at' => now(), 'note' => $note]
        );
        // Tính phase kế tiếp = phase nhỏ nhất trong 1..5 chưa closed; nếu đã closed hết → min(startPhase+1, 5).
        $closed = $this->phaseClosures()->pluck('phase')->all();
        $nextPhase = null;
        for ($p = 1; $p <= 4; $p++) {
            if (! in_array($p, $closed, true)) { $nextPhase = $p; break; }
        }
        $this->update(['phase' => $nextPhase ?: min($this->startPhase() + 1, 4)]);
    }

    /** Lùi phase (Admin vận hành only). Xóa closure từ $idx trở đi, set phase = $idx. */
    public function rollbackTo(int $idx, User $user, ?string $note = null): void
    {
        if (! $user->hasPermission(self::CF_ROLLBACK_PERM)) {
            throw new \RuntimeException('Chỉ Admin vận hành được lùi phase.');
        }
        if ($idx < 1 || $idx > 4) {
            throw new \RuntimeException('Chỉ lùi được về phase 1..4.');
        }
        $this->phaseClosures()->where('phase', '>=', $idx)->delete();
        $this->update(['phase' => $idx]);
    }

    /**
     * Khách quay lại: bỏ tick is_first_visit → phase reset về $targetPhase, giữ lịch sử.
     * Phase C1.b rev8 2026-08-01: 2 luồng —
     *   - Tele "khởi động cuộc gọi mới" → phase 2 (Gọi điện). Gate: canRestartCall.
     *   - Sale "khởi động đặt lịch mới" → phase 3 (Booking). Gate: canRestartBooking.
     */
    public function markReturning(User $user, int $targetPhase = 2): void
    {
        if (! in_array($targetPhase, [2, 3], true)) {
            throw new \RuntimeException('Phase đích không hợp lệ (chỉ 2 hoặc 3).');
        }
        $can = $targetPhase === 2 ? $this->canRestartCall($user) : $this->canRestartBooking($user);
        if (! $can) {
            throw new \RuntimeException($targetPhase === 2
                ? 'Không có quyền khởi động cuộc gọi mới. Chỉ Tele đã gọi khách này (hoặc Admin) mới bấm được.'
                : 'Không có quyền khởi động đặt lịch mới. Chỉ Sale phụ trách (hoặc Admin) mới bấm được.');
        }
        // Xóa closure từ $targetPhase trở đi để mở lại tab edit (nếu không xóa → tab vẫn readonly do lockedByClosure).
        $this->phaseClosures()->where('phase', '>=', $targetPhase)->delete();
        $this->update(['is_first_visit' => false, 'phase' => $targetPhase]);
    }

    /** Tele đã từng gọi khách này (có call_log), hoặc receiver, hoặc Admin có perm. */
    public function canRestartCall(User $user): bool
    {
        if (! $this->isVisibleTo($user)) return false;
        if ($user->hasPermission(self::CF_ROLLBACK_PERM)) return true;
        if ($user->hasPermission('phase.close.checkin')) return true; // Admin cơ sở / Admin.
        if ($this->receiver_id === $user->id) return true;
        return $this->callLogs()->where('user_id', $user->id)->exists();
    }

    /** Sale đang giữ (owner) hoặc từng book cho khách này, hoặc Admin có perm. */
    public function canRestartBooking(User $user): bool
    {
        if (! $this->isVisibleTo($user)) return false;
        if ($user->hasPermission(self::CF_ROLLBACK_PERM)) return true;
        if ($user->hasPermission('phase.close.checkin')) return true;
        if ($this->owner_id === $user->id) return true;
        return $this->bookingLogs()->where('user_id', $user->id)->exists();
    }

    /** Label ngắn cho arrow-breadcrumb + tab. */
    public function customerFlowLabel(): string
    {
        $label = self::CF_PHASE_LABELS[$this->phase] ?? '?';
        return "Phase {$this->phase} · {$label}";
    }
}
