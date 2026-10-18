<?php

namespace App\Modules\Disciplinary\Notifications;

use App\Modules\Disciplinary\Models\DisciplinaryIncident;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DisciplinaryIncidentAssignedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly DisciplinaryIncident $incident,
        public readonly bool $autoAssigned = false,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $number = $this->incident->incident_number;
        $how    = $this->autoAssigned ? 'automatically' : 'manually';

        return (new MailMessage)
            ->greeting('Hello,')
            ->subject("You Have Been Assigned as Investigator — {$number}")
            ->line("You have been {$how} assigned as the **Investigator** for incident **{$number}**.")
            ->line("**Title:** {$this->incident->title}")
            ->line("**Severity:** " . ucfirst($this->incident->severity_level))
            ->line("**Incident Date:** " . ($this->incident->incident_date?->format('d M Y H:i') ?? '—'))
            ->action('View Incident', route('disciplinary.incidents.show', $this->incident))
            ->line('Please review and begin your investigation.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'            => 'disciplinary_incident_investigator_assigned',
            'message'         => "You have been assigned as investigator on incident {$this->incident->incident_number} — {$this->incident->title}.",
            'incident_id'     => $this->incident->id,
            'incident_number' => $this->incident->incident_number,
            'url'             => route('disciplinary.incidents.show', $this->incident),
        ];
    }
}
