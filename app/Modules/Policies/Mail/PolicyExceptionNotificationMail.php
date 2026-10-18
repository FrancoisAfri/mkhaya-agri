<?php

namespace App\Modules\Policies\Mail;

use App\Core\User\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PolicyExceptionNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        private readonly User  $user,
        private readonly array $data,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Policy Exception Update');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.policies.exception-notification',
            with: [
                'recipientName' => $this->user->name,
                'message'       => $this->data['message'] ?? 'Your policy exception has been updated.',
                'expiryDate'    => $this->data['expiry_date'] ?? null,
            ],
        );
    }
}
