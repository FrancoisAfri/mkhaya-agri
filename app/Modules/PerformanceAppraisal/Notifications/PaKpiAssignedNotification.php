<?php

namespace App\Modules\PerformanceAppraisal\Notifications;

use App\Modules\PerformanceAppraisal\Models\PaEmployeeKpi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaKpiAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly PaEmployeeKpi $kpi) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->greeting('Hello,')
            ->subject('New KPI Assigned: ' . $this->kpi->title)
            ->line("A new KPI has been assigned to you: \"{$this->kpi->title}\".")
            ->when($this->kpi->target_value !== null, fn($m) => $m->line("Target: {$this->kpi->target_value} {$this->kpi->unit}"))
            ->line("Weighting: {$this->kpi->weighting}%")
            ->action('View My Dashboard', route('performance.my-dashboard'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'    => 'pa_kpi_assigned',
            'message' => "A new KPI has been assigned to you: \"{$this->kpi->title}\" (weight: {$this->kpi->weighting}%).",
            'kpi_id'  => $this->kpi->id,
            'url'     => route('performance.my-dashboard'),
        ];
    }
}
