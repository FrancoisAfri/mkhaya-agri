<?php

namespace App\Modules\BI\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A scheduled BI dashboard, with the headline figures in the body and the data attached. */
class BIDashboardMail extends Mailable
{
    use Queueable;

    public function __construct(public string $title, public string $period, public ?string $company, public array $lines, public array $file, public string $link) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->title} · {$this->period}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.bi.dashboard');
    }

    public function attachments(): array
    {
        return [Attachment::fromData(fn () => file_get_contents($this->file['path']), $this->file['name'])->withMime($this->file['mime'])];
    }
}
