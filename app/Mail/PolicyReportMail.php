<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PolicyReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public readonly string $reportName;
    public readonly string $reportTitle;
    public readonly string $cadence;
    /** @var array<int, array{path: string, name: string}> */
    public readonly array $reportFiles;

    public function __construct(
        string $reportName,
        string $reportTitle,
        string $cadence,
        array  $attachments = [],
    ) {
        $this->reportName  = $reportName;
        $this->reportTitle = $reportTitle;
        $this->cadence     = $cadence;
        $this->reportFiles = $attachments;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[Reports] {$this->reportName} — " . now()->format('d M Y'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.policy_report',
        );
    }

    public function attachments(): array
    {
        return array_map(
            fn($a) => Attachment::fromPath($a['path'])->as($a['name']),
            $this->reportFiles,
        );
    }
}
