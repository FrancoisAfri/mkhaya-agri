<?php

namespace App\Modules\HR\Contracts\Mail;

use App\Modules\HR\Contracts\Models\Contract;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContractSigningMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Contract $contract,
        public readonly string   $portalUrl,
        public readonly string   $signatoryType,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Please Sign Your Contract — {$this->contract->job?->title}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.contracts.contract-signing',
            with: [
                'jobTitle'      => $this->contract->job?->title ?? 'Position',
                'signatoryType' => $this->signatoryType,
                'portalUrl'     => $this->portalUrl,
                'expiresAt'     => $this->contract->expires_at?->format('d M Y, H:i'),
            ],
        );
    }
}
