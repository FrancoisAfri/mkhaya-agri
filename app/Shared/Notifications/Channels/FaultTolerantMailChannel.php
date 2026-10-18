<?php

namespace App\Shared\Notifications\Channels;

use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Drop-in replacement for Laravel's notification MailChannel that never lets an
 * email failure stop the script.
 *
 * Notifications are intentionally best-effort: if the SMTP server is down, over
 * its sending limit, mis-configured, or times out, we log the failure and carry
 * on so the rest of the notification (e.g. the in-app database notification) and
 * the originating request still complete normally.
 *
 * Bound over the default channel in AppServiceProvider, so it applies to every
 * `mail` notification across every module — no per-call try/catch needed.
 */
class FaultTolerantMailChannel extends MailChannel
{
    public function send($notifiable, Notification $notification)
    {
        try {
            return parent::send($notifiable, $notification);
        } catch (\Throwable $e) {
            Log::warning('Notification email skipped (mail send failed)', [
                'notification' => $notification::class,
                'notifiable'   => is_object($notifiable) ? $notifiable::class : gettype($notifiable),
                'notifiable_id' => is_object($notifiable) && method_exists($notifiable, 'getKey') ? $notifiable->getKey() : null,
                'error'        => $e->getMessage(),
            ]);

            // Swallow the failure — email is best-effort, the script must continue.
            return null;
        }
    }
}
