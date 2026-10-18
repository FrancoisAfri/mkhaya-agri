<?php

namespace App\Modules\HR\Recruitment\Listeners;

use App\Modules\HR\Recruitment\Events\RequisitionApproved;
use App\Modules\HR\Recruitment\Mail\RequisitionApprovedMail;
use App\Modules\HR\Recruitment\Notifications\RequisitionApprovedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class NotifyHrOnRequisitionApproval implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = 'notifications';

    public bool $afterCommit = true;

    public function handle(RequisitionApproved $event): void
    {
        $requisition = $event->requisition;

        $recipients = \App\Core\User\Models\User::permission('recruitment.requisitions.approve')
            ->whereNotNull('email')
            ->pluck('email');

        if ($recipients->isEmpty()) {
            return;
        }

        foreach ($recipients as $email) {
            \Illuminate\Support\Facades\Mail::to($email)->send(new RequisitionApprovedMail($requisition));
        }

        // Also notify the requisition owner via database notification
        $requisition->requestedBy->notify(new RequisitionApprovedNotification($requisition));
    }
}
