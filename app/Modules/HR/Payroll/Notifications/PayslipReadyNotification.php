<?php

namespace App\Modules\HR\Payroll\Notifications;

use App\Modules\HR\Payroll\Models\Payslip;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** In-app notification that an employee's payslip is ready (emailed separately). */
class PayslipReadyNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly Payslip $payslip, private readonly string $period)
    {
    }

    /** @return array<int,string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'        => 'payslip_ready',
            'title'       => 'Payslip available',
            'message'     => "Your payslip for {$this->period} is ready. Open the emailed copy with your payslip key.",
            'payslip_uuid' => $this->payslip->uuid,
            'period'      => $this->period,
            'net_pay'     => (float) $this->payslip->net_pay,
        ];
    }
}
