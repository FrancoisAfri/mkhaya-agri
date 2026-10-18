<?php

namespace App\Modules\HR\Payroll\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class PayrollReportReadyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected string $reportKey,
        protected string $filename,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'       => 'payroll_report_ready',
            'message'    => "Your {$this->reportKey} payroll report is ready and has been emailed to you.",
            'report_key' => $this->reportKey,
            'filename'   => $this->filename,
            'url'        => null,
        ];
    }
}
