<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Notification chung — payload đầy đủ do NotificationDispatcher chuẩn hoá.
 * Lưu database (chuông) + broadcast (Reverb realtime).
 */
class GenericEvent extends Notification
{
    use Queueable;

    public function __construct(public string $eventKey, public array $payload)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        return ['event' => $this->eventKey] + $this->payload;
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
