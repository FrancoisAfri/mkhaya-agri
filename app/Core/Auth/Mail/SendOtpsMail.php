<?php

namespace App\Core\Auth\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SendOtpsMail extends Mailable
{
    use SerializesModels;

    /**
     * Number of times the job may be attempted before it is marked failed.
     */
    public int $tries = 3;

    /**
     * Seconds to wait before retrying after a failure.
     *
     * @var array<int>
     */
    public array $backoff = [10, 30, 60];

    /**
     * The job will be abandoned if it has not completed within this many seconds.
     */
    public int $timeout = 30;

    public function __construct(
        protected readonly string $otp,
        protected readonly string $userName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address('info@mkhayahub.co.za', 'Mkhaya Hub'),
            subject: 'Your One-Time Password',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.otp.otp',
            with: [
                'otp' => $this->otp,
                'userName' => $this->userName,
                'expiresIn' => 10,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
