<?php

namespace App\Modules\Finance\Accounting\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Emails a Finance document (quote / invoice / statement) with the PDF attached.
 */
class FinanceDocumentMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        private readonly string $subjectLine,
        private readonly string $greetingName,
        private readonly string $bodyHtml,
        private readonly string $pdf,
        private readonly string $filename,
    ) {
    }

    public function build(): self
    {
        return $this->subject($this->subjectLine)
            ->html(
                "<p>Hi {$this->greetingName},</p>"
                . $this->bodyHtml
                . '<p>Kind regards</p>'
            )
            ->attachData($this->pdf, $this->filename, ['mime' => 'application/pdf']);
    }
}
