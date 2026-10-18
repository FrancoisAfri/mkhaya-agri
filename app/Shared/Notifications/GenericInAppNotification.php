<?php

namespace App\Shared\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Lightweight, in-app-only notification used to mirror outgoing emails into the
 * notification bell. Delivers via the `database` channel only (no mail) so it
 * renders in the dropdown without re-sending an email.
 *
 * The `data` keys (type/title/message/url) match what HandleInertiaRequests
 * reads when building the bell payload.
 */
class GenericInAppNotification extends Notification
{
    public function __construct(
        private string $title,
        private string $message = '',
        private ?string $url = null,
        private string $type = 'email',
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'    => $this->type,
            'title'   => $this->title,
            'message' => $this->message,
            'url'     => $this->url,
        ];
    }
}
