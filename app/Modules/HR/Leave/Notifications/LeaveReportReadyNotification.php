<?php

namespace App\Modules\HR\Leave\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class LeaveReportReadyNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected string $reportKey,
        protected string $filename
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'       => 'leave_report_ready',
            'message'    => "Your {$this->reportKey} leave report is ready and has been emailed to you.",
            'report_key' => $this->reportKey,
            'filename'   => $this->filename,
            'url'        => null,
        ];
    }
}
