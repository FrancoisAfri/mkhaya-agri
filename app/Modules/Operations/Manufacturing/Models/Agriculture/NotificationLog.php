<?php

namespace App\Modules\Operations\Manufacturing\Models\Agriculture;

/** One notification sent about an incident, on one channel, to one person. */
class NotificationLog extends ProductionModel
{
    protected $table = 'prd_notifications';

    protected $casts = ['sent_at' => 'datetime', 'acknowledged_at' => 'datetime'];
}
