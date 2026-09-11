<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 2026-08-04 — Broadcast khi scrm nhận callback từ sbooking cập nhật BookingLog / Lead.
 * Blade lead-form listen kênh `lead.{leadId}` → gọi $wire.$refresh() để reflect ngay,
 * không cần F5. Fix delay "duyệt bên sbooking → scrm chưa hiện".
 */
class BookingStatusSynced implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $leadId,
        public ?int $sbookingBookingId = null,
        public ?string $type = null,
        public ?string $newStatus = null,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('lead.'.$this->leadId)];
    }

    public function broadcastWith(): array
    {
        return [
            'lead_id' => $this->leadId,
            'sbooking_booking_id' => $this->sbookingBookingId,
            'type' => $this->type,
            'new_status' => $this->newStatus,
            'at' => now()->toIso8601String(),
        ];
    }
}
