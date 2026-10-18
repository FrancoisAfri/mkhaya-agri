<?php

namespace App\Modules\Policies\Mail;

use App\Modules\Policies\Models\ViolationInvestigation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvestigatorAssignedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        private readonly ViolationInvestigation $investigation,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Investigation Assigned – Violation #{$this->investigation->violation_id}");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.policies.investigator-assigned',
            with: [
                'violationId' => $this->investigation->violation_id,
                'policyId'    => $this->investigation->policy_id,
                'assignedAt'  => $this->investigation->assigned_at?->format('d M Y, H:i') ?? now()->format('d M Y, H:i'),
            ],
        );
    }
}
