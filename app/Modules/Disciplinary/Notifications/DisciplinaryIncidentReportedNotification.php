<?php

namespace App\Modules\Disciplinary\Notifications;

use App\Modules\Disciplinary\Models\DisciplinaryIncident;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DisciplinaryIncidentReportedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly DisciplinaryIncident $incident,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $number   = $this->incident->incident_number;
        $severity = ucfirst($this->incident->severity_level);
        $title    = $this->incident->title;
        $reporter = $this->incident->is_anonymous ? 'Anonymous' : $this->incident->reporterName();

        return (new MailMessage)
            ->greeting('Hello,')
            ->subject("New Disciplinary Incident Reported — {$number}")
            ->line("A new disciplinary incident has been submitted and requires your attention.")
            ->line("**Incident Number:** {$number}")
            ->line("**Title:** {$title}")
            ->line("**Severity:** {$severity}")
            ->line("**Reported By:** {$reporter}")
            ->line("**Date Reported:** " . $this->incident->created_at?->format('d M Y H:i'))
            ->action('View Incident', route('disciplinary.incidents.show', $this->incident))
            ->line('Please review and assign an investigator if one has not been auto-assigned.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'            => 'disciplinary_incident_reported',
            'message'         => "New incident {$this->incident->incident_number} reported — {$this->incident->title}.",
            'incident_id'     => $this->incident->id,
            'incident_number' => $this->incident->incident_number,
            'severity'        => $this->incident->severity_level,
            'url'             => route('disciplinary.incidents.show', $this->incident),
        ];
    }
}
