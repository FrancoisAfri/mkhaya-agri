<?php

namespace App\Modules\Fleet\Notifications;

use App\Modules\Fleet\Models\FleetBooking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FleetBookingStatusNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly FleetBooking $booking,
        public readonly string $status,          // approved | approved_driver | approved_passenger | collected | rejected | cancelled | returned | inspection_difference | turned_back
        public readonly ?string $reason = null,
        public readonly array $context = [],
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ref  = $this->booking->reference;
        $mail = (new MailMessage)->greeting('Hello,');

        return match ($this->status) {
            'approved' => $mail
                ->subject("Fleet Booking Approved — {$ref}")
                ->line("Your fleet booking **{$ref}** has been fully approved.")
                ->line("**Vehicle:** {$this->vehicleLabel()}")
                ->line("**Departure:** {$this->booking->departure_at?->format('d M Y H:i')}")
                ->action('View My Dashboard', route('fleet.my-dashboard')),

            'approved_driver' => $mail
                ->subject("Fleet Booking Confirmed — You Are the Driver — {$ref}")
                ->line("Booking **{$ref}** has been approved and you are assigned as the driver.")
                ->line("**Vehicle:** {$this->vehicleLabel()}")
                ->line("**Departure:** {$this->booking->departure_at?->format('d M Y H:i')}")
                ->line("**Expected Return:** {$this->booking->expected_return_at?->format('d M Y H:i')}")
                ->action('View My Dashboard', route('fleet.my-dashboard')),

            'approved_passenger' => $mail
                ->subject("Fleet Booking Confirmed — You Are a Passenger — {$ref}")
                ->line("A fleet booking **{$ref}** has been confirmed and you are listed as a passenger.")
                ->line("**Vehicle:** {$this->vehicleLabel()}")
                ->line("**Departure:** {$this->booking->departure_at?->format('d M Y H:i')}")
                ->line("**Expected Return:** {$this->booking->expected_return_at?->format('d M Y H:i')}")
                ->action('View My Dashboard', route('fleet.my-dashboard')),

            'collected' => $mail
                ->subject("Vehicle Collected — Approaching Security Gate — {$ref}")
                ->line("Booking **{$ref}** has been collected by the driver and is on its way to the security gate.")
                ->line("**Vehicle:** {$this->vehicleLabel()}")
                ->line("**Driver:** " . ($this->booking->driver?->user?->person?->first_name . ' ' . $this->booking->driver?->user?->person?->surname ?? 'N/A'))
                ->line("**Departure:** {$this->booking->departure_at?->format('d M Y H:i')}")
                ->line("Please be ready to conduct the pre-trip security inspection.")
                ->action('Open Security Check', route('fleet.security-check')),

            'rejected' => $mail
                ->subject("Fleet Booking Rejected — {$ref}")
                ->line("Your fleet booking **{$ref}** was rejected.")
                ->line($this->reason ? "**Reason:** {$this->reason}" : '')
                ->action('View Bookings', route('fleet.bookings.index')),

            'cancelled' => $mail
                ->subject("Fleet Booking Cancelled — {$ref}")
                ->line("Fleet booking **{$ref}** has been cancelled.")
                ->line($this->reason ? "**Reason:** {$this->reason}" : '')
                ->action('View Bookings', route('fleet.bookings.index')),

            'returned' => $mail
                ->subject("Vehicle Returned — {$ref}")
                ->line("The vehicle for booking **{$ref}** has been returned.")
                ->line("**Vehicle:** {$this->vehicleLabel()}")
                ->line("**KM Travelled:** " . ($this->booking->km_travelled ?? 'N/A'))
                ->action('View Booking', route('fleet.bookings.index')),

            'inspection_difference' => $this->buildInspectionDiffMail($mail, $ref),

            'turned_back' => $mail
                ->subject("Vehicle Turned Back at Gate — {$ref}")
                ->line("Booking **{$ref}** was turned back at the security gate.")
                ->line("**Vehicle:** {$this->vehicleLabel()}")
                ->line($this->reason ? "**Reason:** {$this->reason}" : '')
                ->line("Please collect the keys and review the failed inspection items before re-dispatching.")
                ->action('View Bookings', route('fleet.bookings.index')),

            default => $mail->subject("Booking Update — {$ref}")->line("Booking {$ref} status changed to {$this->status}."),
        };
    }

    public function toArray(object $notifiable): array
    {
        $messages = [
            'approved'            => "Your booking {$this->booking->reference} has been approved.",
            'approved_driver'     => "Booking {$this->booking->reference} confirmed — you are the assigned driver.",
            'approved_passenger'  => "Booking {$this->booking->reference} confirmed — you are listed as a passenger.",
            'collected'           => "Vehicle for booking {$this->booking->reference} has been collected — approaching the security gate for check-out inspection.",
            'rejected'            => "Your booking {$this->booking->reference} was rejected." . ($this->reason ? " Reason: {$this->reason}" : ''),
            'cancelled'             => "Booking {$this->booking->reference} has been cancelled." . ($this->reason ? " Reason: {$this->reason}" : ''),
            'returned'              => "Vehicle for booking {$this->booking->reference} has been returned.",
            'inspection_difference' => "Inspection difference detected for booking {$this->booking->reference}. Please review the pre/post trip report.",
            'turned_back'           => "Booking {$this->booking->reference} was turned back at the security gate. Please collect the keys and investigate.",
        ];

        return [
            'type'       => "fleet_booking_{$this->status}",
            'message'    => $messages[$this->status] ?? "Booking {$this->booking->reference} updated.",
            'booking_id' => $this->booking->id,
            'reference'  => $this->booking->reference,
            'url'        => route('fleet.bookings.index'),
        ];
    }

    private function vehicleLabel(): string
    {
        return trim(
            ($this->booking->vehicle_make_snapshot ?? '') . ' ' .
            ($this->booking->vehicle_model_snapshot ?? '') . ' ' .
            ($this->booking->vehicle_reg_snapshot ? "({$this->booking->vehicle_reg_snapshot})" : '')
        ) ?: $this->booking->vehicle_id;
    }

    private function buildInspectionDiffMail(MailMessage $mail, string $ref): MailMessage
    {
        $diff     = $this->context['diff'] ?? [];
        $vehicle  = $this->vehicleLabel();
        $driver   = $this->context['driver'] ?? 'Unknown';
        $checkOut = $this->context['check_out_at'] ?? '—';
        $checkIn  = $this->context['check_in_at'] ?? '—';

        $mail->subject("⚠️ Inspection Difference Detected — {$ref}")
             ->line("A **post-trip inspection difference** was found for booking **{$ref}**.")
             ->line("**Vehicle:** {$vehicle}")
             ->line("**Driver:** {$driver}")
             ->line("**Check-Out:** {$checkOut} | **Check-In:** {$checkIn}")
             ->line('---');

        // Odometer
        if (isset($diff['odometer'])) {
            $o = $diff['odometer'];
            $mail->line("**Odometer:** Check-Out {$o['check_out']} km → Check-In {$o['check_in']} km ({$o['km']} km travelled)");
        }

        // Fuel level change
        if (isset($diff['fuel_level'])) {
            $f = $diff['fuel_level'];
            $mail->line("**Fuel Level:** {$f['check_out']} at departure → {$f['check_in']} on return *(fuel may have been used or not refuelled)*");
        }

        // Condition downgrade
        if (isset($diff['condition'])) {
            $c = $diff['condition'];
            $mail->line("**Overall Condition Downgraded:** {$c['check_out']} → **{$c['check_in']}**");
        }

        // Equipment failures
        if (!empty($diff['failed_equipment'])) {
            $items = implode(', ', $diff['failed_equipment']);
            $mail->line("**Equipment — OK at check-out but FAILED at check-in:** {$items}");
        }

        // New damage
        if (!empty($diff['new_damage'])) {
            $areas = implode(', ', $diff['new_damage']);
            $mail->line("**New Damage Reported:** {$areas}");
        }

        // Notes from post-trip
        if (!empty($this->context['post_trip_notes'])) {
            $mail->line("**Inspector Notes:** {$this->context['post_trip_notes']}");
        }

        return $mail
            ->line('---')
            ->line('Please review the full inspection report in the booking detail and take the necessary action.')
            ->action('View Booking', route('fleet.bookings.index'));
    }
}
