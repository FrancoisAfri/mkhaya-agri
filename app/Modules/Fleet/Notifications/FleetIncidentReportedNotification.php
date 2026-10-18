<?php

namespace App\Modules\Fleet\Notifications;

use App\Modules\Fleet\Models\FleetBooking;
use App\Modules\Fleet\Models\FleetBookingIncident;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FleetIncidentReportedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly FleetBookingIncident $incident,
        public readonly FleetBooking $booking,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $vehicle  = $this->booking->vehicle_reg_snapshot ?? "Vehicle #{$this->booking->vehicle_id}";
        $severity = strtoupper($this->incident->severity);
        $type     = ucwords(str_replace('_', ' ', $this->incident->incident_type));

        return (new MailMessage)
            ->subject("[{$severity}] Fleet Incident — {$type} on {$vehicle}")
            ->greeting('Incident Report')
            ->line("A **{$severity}** incident has been reported for vehicle **{$vehicle}** (Booking: {$this->booking->reference}).")
            ->line("**Type:** {$type}")
            ->line("**Description:** {$this->incident->description}")
            ->line("**Time:** {$this->incident->incident_at?->format('d M Y H:i')}")
            ->when($this->incident->location, fn($m) => $m->line("**Location:** {$this->incident->location}"))
            ->when($this->incident->police_case_number, fn($m) => $m->line("**Police Case:** {$this->incident->police_case_number}"))
            ->action('View Booking', route('fleet.bookings.index'))
            ->line('Please review and take appropriate action.');
    }

    public function toArray(object $notifiable): array
    {
        $vehicle = $this->booking->vehicle_reg_snapshot ?? "Vehicle #{$this->booking->vehicle_id}";
        $type    = ucwords(str_replace('_', ' ', $this->incident->incident_type));

        return [
            'type'        => 'fleet_incident_reported',
            'message'     => "[{$this->incident->severity}] {$type} reported on {$vehicle} — {$this->booking->reference}.",
            'incident_id' => $this->incident->id,
            'booking_id'  => $this->booking->id,
            'reference'   => $this->booking->reference,
            'severity'    => $this->incident->severity,
            'url'         => route('fleet.bookings.index'),
        ];
    }
}
