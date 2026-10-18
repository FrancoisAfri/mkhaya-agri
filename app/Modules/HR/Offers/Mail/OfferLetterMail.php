<?php

namespace App\Modules\HR\Offers\Mail;

use App\Modules\HR\Offers\Models\Offer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OfferLetterMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Offer  $offer,
        public readonly string $portalUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your Offer Letter — {$this->offer->job?->title}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.offers.offer-letter',
            with: [
                'candidateName' => $this->offer->candidate?->full_name ?? 'Candidate',
                'jobTitle'      => $this->offer->job?->title ?? 'Position',
                'portalUrl'     => $this->portalUrl,
                'expiresAt'     => $this->offer->expires_at?->format('d M Y, H:i'),
            ],
        );
    }
}
