<?php

namespace App\Modules\Finance\Tax\Notifications;

use App\Modules\Finance\Tax\Models\TaxComplianceDeadline;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A tax deadline is approaching, or has passed (§14). Carries no amounts or employee data — only
 * what is due, for which business and when (§19 events: no unnecessary confidential information).
 */
class TaxDeadlineNotification extends Notification
{
    public function __construct(public readonly TaxComplianceDeadline $deadline, public readonly string $kind, public readonly int $days)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject())
            ->line($this->sentence())
            ->action('Open the compliance calendar', route('tax.calendar.index'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'tax_deadline_' . $this->kind,
            'message' => $this->sentence(),
            'severity' => $this->kind === 'escalation' ? 'critical' : ($this->days <= 3 ? 'warning' : 'info'),
            'deadline_id' => $this->deadline->uuid,
            'url' => route('tax.calendar.index'),
        ];
    }

    private function subject(): string
    {
        return $this->kind === 'escalation'
            ? "Overdue: {$this->deadline->title}"
            : "Due in {$this->days} day(s): {$this->deadline->title}";
    }

    private function sentence(): string
    {
        $due = $this->deadline->due_date->format('j M Y');

        return $this->kind === 'escalation'
            ? "{$this->deadline->title} was due on {$due} and is not marked done. You are the escalation contact."
            : "{$this->deadline->title} is due on {$due}.";
    }
}
