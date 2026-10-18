<?php

namespace App\Modules\Operations\ProjectManagement\Mail;

use App\Modules\Operations\ProjectManagement\Models\MemberInvite;
use App\Modules\Operations\ProjectManagement\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The invitation itself.
 *
 * The accept URL is passed in rather than built here: it carries the one-time token, which exists
 * only in memory at the moment the invitation is issued, and a mailable that could rebuild it
 * would need the plain value stored somewhere.
 */
class PortalInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly MemberInvite $invite,
        public readonly ?Project $project,
        public readonly string $acceptUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        $name = $this->project?->name ?? config('app.name');

        return new Envelope(subject: "You have been invited to {$name}");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.project-management.portal-invite',
            with: [
                'projectName' => $this->project?->name,
                'projectCode' => $this->project?->code,
                'acceptUrl'   => $this->acceptUrl,
                'expiresAt'   => $this->invite->expires_at->format('d M Y'),
                // "Client" or "Contractor" — what they are being invited *as*, so the recipient
                // can tell at a glance whether the invitation is the one they were expecting.
                'roleLabel'   => ucfirst(str_replace('_', ' ', $this->invite->role_key)),
            ],
        );
    }
}
