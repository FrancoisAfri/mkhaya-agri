<?php

namespace App\Modules\HR\Recruitment\Mail;

use App\Modules\HR\Recruitment\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ApplicationConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        private readonly Application $application,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Application Received – ' . ($this->application->job?->title ?? 'Position'));
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.recruitment.application-confirmation',
            with: [
                'candidateName' => $this->application->candidate?->full_name ?? 'Applicant',
                'jobTitle'      => $this->application->job?->title ?? 'the position',
            ],
        );
    }
}
