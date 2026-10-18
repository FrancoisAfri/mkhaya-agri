<?php

namespace App\Modules\Fleet\Notifications;

use App\Modules\Fleet\Models\FleetVehicleReminder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FleetVehicleReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly FleetVehicleReminder $reminder,
        public readonly string $vehicleReg,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->greeting('Fleet Reminder')
            ->subject("Reminder: {$this->reminder->name} — {$this->vehicleReg}")
            ->line("A reminder has been set for vehicle **{$this->vehicleReg}**.")
            ->line("**{$this->reminder->name}**")
            ->when($this->reminder->description, fn($m) => $m->line($this->reminder->description))
            ->when($this->reminder->start_date, fn($m) => $m->line("**Start:** {$this->reminder->start_date->format('d M Y')}"))
            ->when($this->reminder->end_date,   fn($m) => $m->line("**End:** {$this->reminder->end_date->format('d M Y')}"))
            ->action('View Vehicle', route('fleet.vehicles.show', $this->reminder->vehicle?->uuid ?? $this->reminder->vehicle_id));

        return $mail;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'        => 'fleet_vehicle_reminder',
            'message'     => "Reminder set for {$this->vehicleReg}: {$this->reminder->name}",
            'reminder_id' => $this->reminder->id,
            'vehicle_reg' => $this->vehicleReg,
            'start_date'  => $this->reminder->start_date?->format('d M Y'),
            'end_date'    => $this->reminder->end_date?->format('d M Y'),
            'url'         => route('fleet.vehicles.show', $this->reminder->vehicle?->uuid ?? $this->reminder->vehicle_id),
        ];
    }
}
