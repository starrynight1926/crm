<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'lead_id', 'user_id', 'type', 'status', 'scheduled_at',
    'doctor_id', 'service_id', 'note',
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
    ];

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

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
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
                        $lead->update(['phase' => min(Lead::CF_PHASE_BOOKING + 1, 5)]);
                    }
                }
            }
        }
    }
}
