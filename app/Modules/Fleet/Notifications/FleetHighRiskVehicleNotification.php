<?php

namespace App\Modules\Fleet\Notifications;

use App\Modules\Fleet\Models\FleetVehicle;
use App\Modules\Fleet\Models\FleetVehiclePrediction;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FleetHighRiskVehicleNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly FleetVehicle $vehicle,
        public readonly FleetVehiclePrediction $prediction,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $topFactor = $this->prediction->top_risk_factors[0]['label'] ?? 'Multiple risk factors';

        return (new MailMessage)
            ->subject("⚠️ High-Risk Vehicle — {$this->vehicle->registration_number} (Score: {$this->prediction->risk_score})")
            ->greeting('Fleet Alert')
            ->line("Vehicle **{$this->vehicle->registration_number}** ({$this->vehicle->make} {$this->vehicle->model}) has a high maintenance risk score of **{$this->prediction->risk_score}/100**.")
            ->line("**Top factor:** {$topFactor}")
            ->line("**Suggested service date:** " . ($this->prediction->predicted_service_date?->format('d M Y') ?? 'As soon as possible'))
            ->action('View Vehicle', route('fleet.vehicles.show', $this->vehicle->id))
            ->line('A draft job card has been created automatically.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'            => 'fleet_high_risk_vehicle',
            'message'         => "Vehicle {$this->vehicle->registration_number} has risk score {$this->prediction->risk_score}/100 — service recommended.",
            'vehicle_id'      => $this->vehicle->id,
            'registration'    => $this->vehicle->registration_number,
            'risk_score'      => $this->prediction->risk_score,
            'predicted_service_date' => $this->prediction->predicted_service_date?->toDateString(),
            'url'             => route('fleet.vehicles.show', $this->vehicle->id),
        ];
    }
}
