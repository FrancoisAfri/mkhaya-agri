<?php

namespace App\Modules\Tenancy\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells somebody they now also belong to another organisation, and how to get to it. */
class AddedToOrganisation extends Notification
{
    use Queueable;

    public function __construct(public readonly string $organisation, public readonly string $addedBy, public readonly array $roles) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("You have been added to {$this->organisation}")
            ->greeting('Hello')
            ->line("{$this->addedBy} has added you to {$this->organisation}".($this->roles ? ' as '.implode(', ', $this->roles).'.' : '.'))
            ->line('You keep your current organisation. Switch between them from the menu under your name, top right.')
            ->action('Open your organisations', url('/account/organisations'));
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => "Added to {$this->organisation}", 'message' => "{$this->addedBy} added you to {$this->organisation}.", 'url' => '/account/organisations'];
    }
}
