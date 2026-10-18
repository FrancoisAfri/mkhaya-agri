<?php

namespace App\Modules\Policies\Notifications;

use App\Modules\Policies\Models\PolicyAttestation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AttestationReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected PolicyAttestation $attestation,
        protected int $daysUntilDue
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $policy = $this->attestation->policy;
        $deadline = $this->attestation->deadline?->format('d M Y');

        return (new MailMessage)
            ->subject("Action Required: Attest to \"{$policy->name}\"")
            ->greeting("Hello {$notifiable->name},")
            ->line("You have a pending policy attestation for **{$policy->name}**.")
            ->line("Deadline: **{$deadline}** ({$this->daysUntilDue} day(s) remaining).")
            ->action('Review & Attest Now', url(route('policies.attestations.show', $this->attestation->id)))
            ->line('Please complete your attestation before the deadline to remain compliant.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'           => 'attestation_reminder',
            'attestation_id' => $this->attestation->id,
            'policy_id'      => $this->attestation->policy_id,
            'policy_name'    => $this->attestation->policy?->name,
            'deadline'       => $this->attestation->deadline?->toDateString(),
            'days_until_due' => $this->daysUntilDue,
            'message'        => "Please attest to \"{$this->attestation->policy?->name}\" by {$this->attestation->deadline?->format('d M Y')}.",
        ];
    }
}
