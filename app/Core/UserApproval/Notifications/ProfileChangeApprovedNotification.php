<?php

namespace App\Core\UserApproval\Notifications;

use App\Core\User\Models\User;
use App\Modules\HR\Hr\Models\HrPeopleChange;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ProfileChangeApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected HrPeopleChange $change,
        protected User           $approver
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $approverName = $this->approver->person?->full_name ?? $this->approver->email;

        return [
            'type'         => 'profile_change_approved',
            'message'      => "Your profile update has been approved by {$approverName}.",
            'change_id'    => $this->change->id,
            'approved_by'  => $approverName,
            'url'          => '/profile',
        ];
    }
}
