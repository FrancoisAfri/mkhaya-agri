<?php

namespace App\Modules\Operations\WarehouseManagement\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** A background export finished, or could not be built. */
class ReportReadyNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly string $run, public readonly string $title, public readonly int $rows, public readonly bool $ok) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'wms_report_ready',
            'title' => $this->ok ? "{$this->title} is ready" : "{$this->title} could not be built",
            'message' => $this->ok ? "{$this->rows} row(s). Download it from Warehouse reports." : 'Open Warehouse reports to see why and run it again.',
            'url' => '/warehouse/reports',
        ];
    }
}
