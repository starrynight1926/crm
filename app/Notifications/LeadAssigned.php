<?php

namespace App\Notifications;

use App\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Thông báo "có lead mới về" — lưu database (chuông navbar) + broadcast Reverb (realtime).
 */
class LeadAssigned extends Notification
{
    use Queueable;

    public function __construct(public Lead $lead)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'lead_id' => $this->lead->id,
            'code' => $this->lead->code,
            'name' => $this->lead->name,
            'region' => $this->lead->region,
            'message' => "Bạn vừa nhận lead mới: {$this->lead->name}" . ($this->lead->code ? " ({$this->lead->code})" : ''),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
