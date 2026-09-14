<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 6.25.C — Broadcast khi sale đổi trạng thái bận / rảnh (checkin sbooking hoặc scrm UPS).
 */
class UpsBusyChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $userId,
        public string $userName,
        public bool $isBusy,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('ups.presence')];
    }

    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->userId,
            'user_name' => $this->userName,
            'is_busy' => $this->isBusy,
            'at' => now()->toIso8601String(),
        ];
    }
}
