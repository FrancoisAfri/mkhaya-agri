<?php

namespace App\Modules\HR\Contracts\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContractOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $rawOtp,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Contract Signing OTP');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.contracts.contract-otp',
            with: [
                'otp'       => $this->rawOtp,
                'expiresIn' => '10 minutes',
            ],
        );
    }
}
