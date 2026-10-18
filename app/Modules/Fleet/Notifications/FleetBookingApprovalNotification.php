<?php

namespace App\Modules\Fleet\Notifications;

use App\Modules\Fleet\Models\FleetBooking;
use App\Modules\Fleet\Models\FleetBookingApproval;
use App\Modules\Fleet\Models\FleetBookingApprovalToken;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class FleetBookingApprovalNotification extends Notification
{
    use Queueable;

    private ?FleetBookingApprovalToken $approveToken = null;
    private ?FleetBookingApprovalToken $rejectToken  = null;

    public function __construct(
        public readonly FleetBooking $booking,
        public readonly FleetBookingApproval $approval,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->ensureTokens();

        $approveUrl = route('fleet.approvals.token.approve', $this->approveToken->token);
        $rejectUrl  = route('fleet.approvals.token.reject',  $this->rejectToken->token);

        $vehicle = trim(
            ($this->booking->vehicle_make_snapshot ?? '') . ' ' .
            ($this->booking->vehicle_model_snapshot ?? '') . ' ' .
            ($this->booking->vehicle_reg_snapshot ? "({$this->booking->vehicle_reg_snapshot})" : '')
        );

        return (new MailMessage)
            ->subject("Fleet Booking Approval — {$this->booking->reference} (Step {$this->approval->step_number})")
            ->greeting('Hello,')
            ->line('A fleet booking request requires your approval.')
            ->line("**Reference:** {$this->booking->reference}")
            ->line("**Vehicle:** {$vehicle}")
            ->line("**Purpose:** {$this->booking->purpose}")
            ->line("**Departure:** {$this->booking->departure_at?->format('d M Y H:i')}")
            ->line("**Expected Return:** {$this->booking->expected_return_at?->format('d M Y H:i')}")
            ->action('Approve Booking', $approveUrl)
            ->line("[Reject this booking]({$rejectUrl})")
            ->line('These links expire in 7 days.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'       => 'fleet_booking_approval',
            'message'    => "Fleet booking {$this->booking->reference} requires your approval (Step {$this->approval->step_number}).",
            'booking_id' => $this->booking->id,
            'reference'  => $this->booking->reference,
            'url'        => route('fleet.bookings.index'),
        ];
    }

    private function ensureTokens(): void
    {
        if ($this->approveToken) return;

        $this->approveToken = FleetBookingApprovalToken::create([
            'booking_id'  => $this->booking->id,
            'approval_id' => $this->approval->id,
            'token'       => Str::random(64),
            'action'      => 'approved',
            'expires_at'  => now()->addDays(7),
        ]);

        $this->rejectToken = FleetBookingApprovalToken::create([
            'booking_id'  => $this->booking->id,
            'approval_id' => $this->approval->id,
            'token'       => Str::random(64),
            'action'      => 'rejected',
            'expires_at'  => now()->addDays(7),
        ]);
    }
}
