<?php

namespace App\Core\UserApproval\Notifications;

use App\Core\User\Models\User;
use App\Modules\HR\Hr\Models\HrPeopleChange;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ProfileChangeRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected HrPeopleChange $change,
        protected User           $approver,
        protected string         $reason = ''
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $approverName = $this->approver->person?->full_name ?? $this->approver->email;
        $msg = $this->reason
            ? "Your profile update was declined by {$approverName}: {$this->reason}"
            : "Your profile update was declined by {$approverName}.";

        return [
            'type'        => 'profile_change_rejected',
            'message'     => $msg,
            'change_id'   => $this->change->id,
            'rejected_by' => $approverName,
            'reason'      => $this->reason,
            'url'         => '/profile',
        ];
    }
}
