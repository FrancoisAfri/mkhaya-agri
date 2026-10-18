<?php

namespace App\Core\User\Trait;

use Illuminate\Support\Facades\Log;

/**
 * Wraps ->notify() so that a failed mail transport never surfaces as a 500.
 * The database channel is always first in via(), so it is already persisted
 * by the time the mailer runs.  If the mailer throws (rate-limit, bad config,
 * etc.) we log a warning and carry on silently.
 */
trait SafeNotifies
{
    protected function safeNotify(object $notifiable, mixed $notification): void
    {
        try {
            $notifiable->notify($notification);
        } catch (\Throwable $e) {
            Log::warning('Notification mail delivery failed — database record was saved.', [
                'notifiable' => method_exists($notifiable, 'getKey') ? $notifiable->getKey() : null,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notify each item in a collection/iterable, swallowing mail errors per item.
     */
    protected function safeNotifyAll(iterable $notifiables, mixed $notification): void
    {
        foreach ($notifiables as $notifiable) {
            $this->safeNotify($notifiable, $notification);
        }
    }
}
