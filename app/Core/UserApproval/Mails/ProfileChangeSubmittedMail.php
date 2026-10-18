<?php

namespace App\Core\UserApproval\Mails;

use App\Core\User\Models\User;
use App\Modules\HR\Hr\Models\HrPeopleChange;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProfileChangeSubmittedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User           $user,
        public readonly HrPeopleChange $change,
        public readonly bool           $isManager = false
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->isManager
            ? "Profile Update Request — {$this->user->person?->full_name}"
            : 'Your profile changes are pending approval';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.profile.submitted', with: [
            'user'      => $this->user,
            'change'    => $this->change,
            'isManager' => $this->isManager,
            'fullName'  => $this->user->person?->full_name ?? $this->user->email,
        ]);
    }
}
