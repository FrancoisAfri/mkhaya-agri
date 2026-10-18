<?php

namespace App\Core\ManagerUser\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class UserWelcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected string $name,
        protected string $email
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'    => 'user_account_created',
            'message' => "Welcome, {$this->name}! Your account has been created. Log in with {$this->email}.",
            'url'     => '/dashboard',
        ];
    }
}
