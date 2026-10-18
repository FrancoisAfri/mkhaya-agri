<?php

namespace App\Modules\HR\Recruitment\Mail;

use App\Modules\HR\Recruitment\Models\Requisition;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RequisitionApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        private readonly Requisition $requisition,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Requisition #{$this->requisition->id} Approved");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.recruitment.requisition-approved',
            with: [
                'requisitionId' => $this->requisition->id,
                'positionTitle' => $this->requisition->title      ?? 'N/A',
                'department'    => $this->requisition->department  ?? 'N/A',
                'headcount'     => $this->requisition->headcount   ?? 1,
            ],
        );
    }
}
