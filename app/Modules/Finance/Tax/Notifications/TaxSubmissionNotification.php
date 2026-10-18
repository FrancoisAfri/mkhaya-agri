<?php

namespace App\Modules\Finance\Tax\Notifications;

use App\Modules\Finance\Tax\Models\TaxReturn;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * TAX-SUB-008: a submission failed, was rejected, or is still unconfirmed. Names the return and what
 * to do; carries no figures (§19: no unnecessary confidential information in notifications).
 */
class TaxSubmissionNotification extends Notification
{
    public function __construct(public readonly TaxReturn $return, public readonly string $kind)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->subject())->line($this->sentence())
            ->action('Open the return', route('tax.returns.show', $this->return->uuid));
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'tax_submission_' . $this->kind, 'message' => $this->sentence(), 'severity' => $this->kind === 'rejected' ? 'critical' : 'warning',
            'return_id' => $this->return->uuid, 'url' => route('tax.returns.show', $this->return->uuid)];
    }

    private function subject(): string
    {
        return match ($this->kind) {
            'rejected' => "SARS rejected {$this->return->reference}",
            'unknown' => "Submission outcome unknown: {$this->return->reference}",
            default => "Submission not yet confirmed: {$this->return->reference}",
        };
    }

    private function sentence(): string
    {
        return match ($this->kind) {
            'rejected' => "SARS rejected {$this->return->reference}. Correct it and prepare a new version; the rejected one is kept as the record.",
            'unknown' => "The outcome of submitting {$this->return->reference} is unknown. Check eFiling before trying again so it is not filed twice.",
            default => "{$this->return->reference} was sent but SARS has not confirmed it. Check eFiling and record the reference or the outcome.",
        };
    }
}
