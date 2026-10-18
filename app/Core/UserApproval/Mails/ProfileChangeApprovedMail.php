<?php

namespace App\Core\UserApproval\Mails;

use App\Core\User\Models\User;
use App\Modules\HR\Hr\Models\HrPeopleChange;
use App\Modules\HR\Hr\Models\HRPerson;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProfileChangeApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly HRPerson       $person,
        public readonly HrPeopleChange $change,
        public readonly User           $approver
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your profile has been updated');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.profile.approve', with: [
            'person'   => $this->person,
            'approver' => $this->approver,
            'fullName' => $this->person->full_name,
        ]);
    }
}
