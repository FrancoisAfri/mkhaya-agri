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

class ProfileChangeRejectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly HRPerson       $person,
        public readonly HrPeopleChange $change,
        public readonly User           $approver,
        public readonly string         $reason = ''
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your profile update request has been declined');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.profile.rejected', with: [
            'person'   => $this->person,
            'approver' => $this->approver,
            'reason'   => $this->reason,
            'fullName' => $this->person->full_name,
        ]);
    }
}
