<?php

namespace App\Modules\Policies\Notifications;

use App\Modules\Policies\Models\PolicyAttestation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AttestationEscalationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected PolicyAttestation $attestation,
        protected string $employeeName
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $policy = $this->attestation->policy;

        return (new MailMessage)
            ->subject("Escalation: Overdue Attestation for \"{$policy->name}\"")
            ->greeting("Hello {$notifiable->name},")
            ->line("**{$this->employeeName}** has not completed their attestation for **{$policy->name}**.")
            ->line("The deadline was **{$this->attestation->deadline?->format('d M Y')}** and the attestation is now overdue.")
            ->action('View Attestation Details', url(route('policies.attestations.dashboard')))
            ->line('Please follow up with this employee to ensure compliance.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'           => 'attestation_escalation',
            'attestation_id' => $this->attestation->id,
            'policy_id'      => $this->attestation->policy_id,
            'policy_name'    => $this->attestation->policy?->name,
            'employee_name'  => $this->employeeName,
            'deadline'       => $this->attestation->deadline?->toDateString(),
            'message'        => "{$this->employeeName} has an overdue attestation for \"{$this->attestation->policy?->name}\".",
        ];
    }
}
