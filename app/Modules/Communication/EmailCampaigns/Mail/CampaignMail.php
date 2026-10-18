<?php

namespace App\Modules\Communication\EmailCampaigns\Mail;

use App\Modules\Communication\EmailCampaigns\Models\CampaignRecipient;
use App\Modules\Communication\EmailCampaigns\Models\EmailCampaign;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CampaignMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly EmailCampaign    $campaign,
        public readonly CampaignRecipient $recipient,
    ) {}

    public function envelope(): Envelope
    {
        $envelope = new Envelope(
            from:    new Address($this->campaign->from_email, $this->campaign->from_name),
            subject: $this->campaign->subject,
        );

        if ($this->campaign->reply_to) {
            $envelope->replyTo = [new Address($this->campaign->reply_to)];
        }

        return $envelope;
    }

    public function content(): Content
    {
        return new Content(view: 'emails.campaign');
    }

    public function attachments(): array
    {
        return [];
    }
}
