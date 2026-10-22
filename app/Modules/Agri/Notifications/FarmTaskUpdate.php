<?php

namespace App\Modules\Agri\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A farm task given to you, waiting for your sign-off, or signed off / sent back. */
class FarmTaskUpdate extends Notification
{
    use Queueable;

    public const ASSIGNED = 'assigned';
    public const SUBMITTED = 'submitted';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    public function __construct(
        public readonly string $event, public readonly string $title, public readonly string $farm,
        public readonly ?string $due = null, public readonly ?string $note = null, public readonly ?string $by = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        [$subject, $line] = match ($this->event) {
            self::ASSIGNED => ["New farm task: {$this->title}", "You have a new task on {$this->farm}".($this->due ? ", due {$this->due}." : '.')],
            self::SUBMITTED => ["Waiting for your sign-off: {$this->title}", "{$this->by} has finished \"{$this->title}\" on {$this->farm} and it needs sign-off."],
            self::APPROVED => ["Signed off: {$this->title}", "\"{$this->title}\" on {$this->farm} was signed off by {$this->by}."],
            default => ["Sent back: {$this->title}", "\"{$this->title}\" on {$this->farm} was sent back by {$this->by}."],
        };
        $mail = (new MailMessage)->subject($subject)->line($line);
        if ($this->note) {
            $mail->line('Note: '.$this->note);
        }

        return \App\Modules\Agri\Support\AgriMail::brand($mail->action($this->event === self::SUBMITTED ? 'Review it' : 'Open my work', url($this->event === self::SUBMITTED ? '/agri/tasks?view=review' : '/agri/my-work')),
            $notifiable->company_id ?? null);
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => ucfirst($this->event).': '.$this->title, 'message' => $this->farm, 'url' => '/agri/tasks'];
    }
}
