<?php

namespace App\Modules\Disciplinary\Notifications;

use App\Modules\Disciplinary\Models\DisciplinaryCase;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DisciplinaryCaseAssignedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly DisciplinaryCase $case,
        public readonly string $role, // 'investigator' | 'chairperson'
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ref      = $this->case->case_number;
        $roleLabel = ucfirst($this->role);

        return (new MailMessage)
            ->greeting('Hello,')
            ->subject("You Have Been Assigned as {$roleLabel} — {$ref}")
            ->line("You have been assigned as the **{$roleLabel}** for disciplinary case **{$ref}**.")
            ->line("**Employee:** {$this->case->employeeName()}")
            ->line("**Title:** {$this->case->title}")
            ->line("**Incident Date:** " . ($this->case->incident_date?->format('d M Y') ?? '—'))
            ->action('View Case', route('disciplinary.cases.show', $this->case))
            ->line('Please review the case details and proceed accordingly.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'        => "disciplinary_case_{$this->role}_assigned",
            'message'     => "You have been assigned as {$this->role} on case {$this->case->case_number} — {$this->case->title}.",
            'case_id'     => $this->case->id,
            'case_number' => $this->case->case_number,
            'url'         => route('disciplinary.cases.show', $this->case),
        ];
    }
}
