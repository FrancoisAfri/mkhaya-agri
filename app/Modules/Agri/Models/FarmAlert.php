<?php

namespace App\Modules\Agri\Models;


class FarmAlert extends FarmOwnedModel
{
    public const OPEN = 'open';
    public const ACKNOWLEDGED = 'acknowledged';
    public const RESOLVED = 'resolved';

    public const KINDS = ['low_stock', 'service_due', 'document_expiry', 'task_overdue', 'withdrawal', 'observation', 'harvest_due', 'budget_overrun', 'health_due'];

    protected $table = 'farm_alerts';

    protected $casts = ['due_on' => 'date', 'acknowledged_at' => 'datetime', 'resolved_at' => 'datetime', 'last_seen_at' => 'datetime'];

    public function getActivitylogOptions(): \Spatie\Activitylog\LogOptions
    {
        // Alerts are refreshed by every scan; only a person's acknowledgement is worth an audit line.
        return parent::getActivitylogOptions()->logOnly(['status', 'acknowledged_by']);
    }
}
