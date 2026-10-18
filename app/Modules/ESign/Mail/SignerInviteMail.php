<?php

namespace App\Modules\ESign\Mail;

use App\Modules\ESign\Models\Envelope;
use App\Modules\ESign\Models\Signer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope as MailEnvelope;
use Illuminate\Queue\SerializesModels;

class SignerInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Envelope $envelope,
        public readonly Signer   $signer,
        public readonly string   $portalUrl,
    ) {}

    public function envelope(): MailEnvelope
    {
        return new MailEnvelope(
            subject: "Please Sign: {$this->envelope->title}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.esign.signer-invite',
            with: [
                'envelopeTitle' => $this->envelope->title,
                'signerName'    => $this->signer->name,
                'message'       => $this->envelope->message,
                'portalUrl'     => $this->portalUrl,
                'expiresAt'     => $this->envelope->expires_at?->format('d M Y, H:i'),
            ],
        );
    }
}
