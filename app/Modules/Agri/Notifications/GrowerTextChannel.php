<?php

namespace App\Modules\Agri\Notifications;

use App\Modules\Agri\Services\GrowerPortal;
use Illuminate\Notifications\Notification;

/** A grower notification as a WhatsApp or SMS text to the grower's mobile. */
class GrowerTextChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if ($notification instanceof GrowerPortalMessage) {
            app(GrowerPortal::class)->deliverText($notifiable, $notification->channel, $notification->toText($notifiable));
        }
    }
}
