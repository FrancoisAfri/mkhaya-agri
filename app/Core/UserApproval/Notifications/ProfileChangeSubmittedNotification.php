<?php

namespace App\Core\UserApproval\Notifications;

use App\Core\User\Models\User;
use App\Modules\HR\Hr\Models\HrPeopleChange;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ProfileChangeSubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected User           $employee,
        protected HrPeopleChange $change,
        protected bool           $isManager = false
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $fullName = $this->employee->person?->full_name ?? $this->employee->email;

        if ($this->isManager) {
            return [
                'type'      => 'profile_change_pending_approval',
                'message'   => "{$fullName} has submitted a profile update for your approval.",
                'employee'  => $fullName,
                'change_id' => $this->change->id,
                'url'       => route('approvals.index'),
            ];
        }

        return [
            'type'      => 'profile_change_submitted',
            'message'   => 'Your profile changes have been submitted and are pending approval.',
            'change_id' => $this->change->id,
            'url'       => route('approvals.index'),
        ];
    }
}
