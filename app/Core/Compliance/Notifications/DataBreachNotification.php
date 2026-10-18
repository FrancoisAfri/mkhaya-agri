<?php

namespace App\Core\Compliance\Notifications;

use App\Core\Compliance\Models\DataBreachIncident;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DataBreachNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly DataBreachIncident $incident,
    ) {}

    public function via(object $_notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $_notifiable): MailMessage
    {
        $hours    = round($this->incident->hoursUntilDeadline(), 1);
        $deadline = $this->incident->detected_at->addHours(72)->format('d M Y H:i');

        return (new MailMessage)
            ->subject("[URGENT] Personal Information Breach — {$this->incident->title}")
            ->greeting("Data Breach Notification")
            ->line("A personal information breach has been detected that requires your immediate attention.")
            ->line("**Incident:** {$this->incident->title}")
            ->line("**Severity:** " . strtoupper($this->incident->severity))
            ->line("**Detected:** {$this->incident->detected_at->format('d M Y H:i')}")
            ->line("**Records affected:** " . ($this->incident->affected_record_count ?? 'Unknown'))
            ->line("**Description:** {$this->incident->description}")
            ->line("---")
            ->line("Under POPIA §22, you are required to notify the Information Regulator as soon as reasonably possible.")
            ->line("**72-hour notification deadline: {$deadline}** ({$hours}h remaining)")
            ->action('View Incident', url('/compliance/breaches/' . $this->incident->uuid))
            ->line("The Information Regulator of South Africa can be reached at inforeg@justice.gov.za");
    }

    public function toDatabase(object $_notifiable): array
    {
        return [
            'type'        => 'data_breach',
            'title'       => 'Data Breach Reported — Action Required',
            'message'     => "A {$this->incident->severity} severity breach has been reported: {$this->incident->title}",
            'severity'    => $this->incident->severity,
            'incident_id' => $this->incident->uuid,
            'url'         => '/compliance/breaches/' . $this->incident->uuid,
            'deadline'    => $this->incident->detected_at->addHours(72)->toISOString(),
        ];
    }
}
