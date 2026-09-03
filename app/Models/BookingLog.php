<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'lead_id', 'user_id', 'type', 'status', 'scheduled_at',
    'facility_id', 'sb_phong_id', 'doctor_id', 'sb_bac_si_id', 'sb_ho_tro_id', 'sb_dich_vu_id', 'sb_khung_gio_id', 'scheduled_end_at', 'service_id', 'note',
    'so_lieu_trinh', 'so_luong_lo', 'dung_tich_lo', 'ket_hop_medical',
    'co_tu_van', 'co_kham_cls',
    'sbooking_booking_id', 'sbooking_booking_ma', 'sync_status', 'sync_error', 'synced_at',
    'past_consultant_user_ids',
])]
class BookingLog extends Model
{
    public const STATUS_DA_XAC_NHAN = 'da_xac_nhan';
    public const STATUS_CHO_XAC_NHAN = 'cho_xac_nhan';
    public const STATUS_HUY_DOI_LICH = 'huy_doi_lich';

    public const STATUSES = [
        self::STATUS_DA_XAC_NHAN  => 'Đã xác nhận',
        self::STATUS_CHO_XAC_NHAN => 'Chờ xác nhận',
        self::STATUS_HUY_DOI_LICH => 'Hủy - Đổi lịch',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'scheduled_end_at' => 'datetime',
        'synced_at' => 'datetime',
        'sbooking_booking_id' => 'integer',
        'ket_hop_medical' => 'boolean',
        'co_tu_van' => 'boolean',
        'co_kham_cls' => 'boolean',
        'past_consultant_user_ids' => 'array',
    ];

    /**
     * Sync consultants + track past CV1 khi bị thay: user cũ vẫn thấy lead (readonly).
     * $syncData: [user_id => ['position' => N], ...]
     */
    public function syncConsultantsTracked(array $syncData): void
    {
        $oldCv1Id = $this->consultants()->wherePivot('position', 1)->value('users.id');
        $newCv1Id = null;
        foreach ($syncData as $uid => $meta) {
            if (($meta['position'] ?? null) === 1) { $newCv1Id = (int) $uid; break; }
        }
        $this->consultants()->sync($syncData);
        if ($oldCv1Id && $newCv1Id && (int) $oldCv1Id !== (int) $newCv1Id) {
            $past = $this->past_consultant_user_ids ?? [];
            if (! in_array((int) $oldCv1Id, $past, true)) {
                $past[] = (int) $oldCv1Id;
                $this->update(['past_consultant_user_ids' => $past]);
            }
        }
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class, 'doctor_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    /**
     * Phase 4 rework 2026-08-01: CV per-booking (n người).
     * position = thứ tự; position=1 = CV chính, dùng để xác định Sale phụ trách lead.
     */
    public function consultants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'booking_log_consultants')
            ->withPivot('position')
            ->withTimestamps()
            ->orderBy('booking_log_consultants.position');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /**
     * 2026-08-04 (Task 2): booking chuyển sync_status='done' (Admin cơ sở đóng buổi khám)
     * → free CV1 khỏi `daily_attendance.is_busy`. Sale giữ nguyên bucket (A/B/C/OFF/MKT)
     * và có thể nhận số mới ở lượt round-robin kế tiếp.
     */
    protected static function booted(): void
    {
        static::updated(function (self $log) {
            if (! $log->wasChanged('sync_status')) return;
            if ($log->sync_status !== 'done') return;

            $cv1 = $log->consultants()->wherePivot('position', 1)->first();
            if (! $cv1) return;

            app(\App\Services\Ups\UpsDispatcher::class)->markFree($cv1->id);
        });

        // B1d (2026-08-14) — tạo booking mới cho lead MKT → gia hạn recall lên 30 ngày.
        static::created(function (self $log) {
            $log->lead?->bumpMktRecallOnBooking();
        });

        // B6 (2026-08-14) — Ownership transfer khi tạo booking:
        // Áp dụng cho nguồn MKT, BA, BDM, BOD, WI (per spec 2026-08-14).
        // Sale bị thu hồi nhưng vẫn tạo booking → ownership chuyển về người tạo.
        static::created(function (self $log) {
            $lead = $log->lead;
            if (! $lead || ! $log->user_id) return;
            if (! in_array($lead->source_group, [
                Lead::SOURCE_MKT, Lead::SOURCE_BA,
                Lead::SOURCE_BDM, Lead::SOURCE_BOD, Lead::SOURCE_WI,
            ], true)) return;
            if ($lead->owner_id === $log->user_id) return;
            $lead->update([
                'owner_id' => $log->user_id,
                'pool_level' => Lead::POOL_PERSONAL,
                'assigned_at' => now(),
            ]);
        });
    }

    /**
     * Sync 1 chiều: booking_log status → leads.booking_status (compat với code cũ).
     * Gọi sau khi save log.
     */
    public static function syncLeadBookingStatus(int $leadId): void
    {
        $latest = self::where('lead_id', $leadId)->latest('created_at')->first();
        if (! $latest) return;
        $map = [
            self::STATUS_DA_XAC_NHAN  => Lead::BOOKING_BOOKED,
            self::STATUS_CHO_XAC_NHAN => Lead::BOOKING_NOT_BOOKED,
            self::STATUS_HUY_DOI_LICH => Lead::BOOKING_RESCHEDULED,
        ];
        $newStatus = $map[$latest->status] ?? null;
        if ($newStatus) {
            Lead::where('id', $leadId)->update(['booking_status' => $newStatus]);
            // Fix Wave 1 #5-UI (2026-07-31): booked → auto-close phase 4 để indicator xanh lá.
            if ($newStatus === Lead::BOOKING_BOOKED) {
                $lead = Lead::find($leadId);
                if ($lead && ! $lead->isBulkOpen()
                    && ! \App\Models\LeadPhaseClosure::where('lead_id', $leadId)->where('phase', Lead::CF_PHASE_BOOKING)->exists()) {
                    \App\Models\LeadPhaseClosure::create([
                        'lead_id' => $leadId, 'phase' => Lead::CF_PHASE_BOOKING,
                        'closed_by' => $latest->user_id, 'closed_at' => now(),
                        'note' => 'Auto-close khi booked',
                    ]);
                    if ((int) $lead->phase === Lead::CF_PHASE_BOOKING) {
                        $lead->update(['phase' => min(Lead::CF_PHASE_BOOKING + 1, 4)]);
                    }
                }
            }
        }
    }
}
