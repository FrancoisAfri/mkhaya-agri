<?php

namespace App\Modules\Fleet\Notifications;

use App\Modules\Fleet\Models\FleetFuelAnomaly;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FleetAnomalyDetectedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly FleetFuelAnomaly $anomaly,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $vehicle  = $this->anomaly->vehicle?->registration_number ?? "Vehicle #{$this->anomaly->vehicle_id}";
        $severity = strtoupper($this->anomaly->severity);

        return (new MailMessage)
            ->subject("[{$severity}] Fleet Anomaly — {$vehicle}")
            ->greeting('Fleet Anomaly Alert')
            ->line("A **{$this->anomaly->severity}** anomaly was detected for vehicle **{$vehicle}**.")
            ->line("**Type:** " . ucwords(str_replace('_', ' ', $this->anomaly->anomaly_type)))
            ->line("**Detail:** {$this->anomaly->description}")
            ->when($this->anomaly->expected_value && $this->anomaly->actual_value, fn($m) =>
                $m->line("Expected: {$this->anomaly->expected_value} | Actual: {$this->anomaly->actual_value}")
            )
            ->action('Review Anomaly', route('fleet.ai.index'))
            ->line('Please investigate and mark the anomaly as reviewed.');
    }

    public function toArray(object $notifiable): array
    {
        $vehicle = $this->anomaly->vehicle?->registration_number ?? "Vehicle #{$this->anomaly->vehicle_id}";

        return [
            'type'        => 'fleet_anomaly_detected',
            'message'     => "[{$this->anomaly->severity}] Anomaly on {$vehicle}: {$this->anomaly->description}",
            'anomaly_id'  => $this->anomaly->id,
            'vehicle_id'  => $this->anomaly->vehicle_id,
            'severity'    => $this->anomaly->severity,
            'anomaly_type'=> $this->anomaly->anomaly_type,
            'url'         => route('fleet.ai.index'),
        ];
    }
}
