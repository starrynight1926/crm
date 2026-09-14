<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 6.25.C — Broadcast sự kiện khi UPS auto-chia sale cho 1 booking checkin.
 * Sbooking listen kênh `ups.booking.{sbooking_booking_id}` để hiện sale mới không cần F5.
 */
class UpsSaleAssigned implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $leadId,
        public int $saleUserId,
        public string $saleName,
        public ?int $sbookingBookingId = null,
    ) {}

    public function broadcastOn(): array
    {
        $channels = [new Channel('ups.lead.'.$this->leadId)];
        if ($this->sbookingBookingId) {
            $channels[] = new Channel('ups.booking.'.$this->sbookingBookingId);
        }

        return $channels;
    }

    public function broadcastWith(): array
    {
        return [
            'lead_id' => $this->leadId,
            'sale_user_id' => $this->saleUserId,
            'sale_name' => $this->saleName,
            'sbooking_booking_id' => $this->sbookingBookingId,
            'at' => now()->toIso8601String(),
        ];
    }
}
