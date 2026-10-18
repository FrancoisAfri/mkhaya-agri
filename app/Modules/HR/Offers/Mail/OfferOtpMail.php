<?php

namespace App\Modules\HR\Offers\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OfferOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        private readonly string $otp,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Offer Signing Code');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.offers.offer-otp', with: ['otp' => $this->otp]);
    }
}
