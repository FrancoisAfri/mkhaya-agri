<?php

namespace App\Modules\HR\Recruitment\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ApprovalRequestedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $entityType,
        public readonly int|string $entityId,
        public readonly string $entityLabel,
        public readonly string $stageName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Approval required: {$this->entityLabel} ({$this->stageName})",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.recruitment.approval-requested',
            with: [
                'entityType'  => $this->entityType,
                'entityId'    => $this->entityId,
                'entityLabel' => $this->entityLabel,
                'stageName'   => $this->stageName,
            ],
        );
    }
}
