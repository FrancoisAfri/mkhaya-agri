<?php

namespace App\Core\Auth\Notifications;

use App\Core\User\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class TwoFactorDeliveryFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected User $affectedUser,
        protected string $reason
    ) {}

    public function via(object $_notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $_notifiable): array
    {
        $person = $this->affectedUser->person;
        $fullName = trim(($person?->first_name ?? '').' '.($person?->surname ?? ''));
        $label = $fullName !== '' ? $fullName : $this->affectedUser->email;

        return [
            'type' => '2fa_delivery_failed',
            'message' => "2FA OTP could not be delivered for user \"{$label}\" ({$this->affectedUser->email}). Reason: {$this->reason}. Please ensure the user has a valid phone number or reachable email address.",
            'user_id' => $this->affectedUser->id,
            'user_email' => $this->affectedUser->email,
            'url' => route('settings.user-access.list'),
        ];
    }
}
