<?php

namespace App\Modules\HR\Recruitment\Mail;

use App\Modules\HR\Recruitment\Models\Shortlist;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ShortlistReadyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        private readonly Shortlist $shortlist,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Shortlist Ready for Review – ' . ($this->shortlist->job?->title ?? "Job #{$this->shortlist->job_id}"));
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.recruitment.shortlist-ready',
            with: [
                'shortlistId'    => $this->shortlist->id,
                'jobTitle'       => $this->shortlist->job?->title ?? "Job #{$this->shortlist->job_id}",
                'candidateCount' => $this->shortlist->items()->count(),
            ],
        );
    }
}
