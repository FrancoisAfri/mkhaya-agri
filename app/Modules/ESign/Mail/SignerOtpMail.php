<?php

namespace App\Modules\ESign\Mail;

use App\Modules\ESign\Models\Signer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SignerOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Signer $signer,
        public readonly string $rawCode,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Document Signing OTP');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.esign.signer-otp',
            with: [
                'signerName' => $this->signer->name,
                'otp'        => $this->rawCode,
                'expiresIn'  => '15 minutes',
            ],
        );
    }
}
