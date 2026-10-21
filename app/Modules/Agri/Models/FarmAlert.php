<?php

namespace App\Modules\Agri\Models;


class FarmAlert extends FarmOwnedModel
{
    public const OPEN = 'open';
    public const ACKNOWLEDGED = 'acknowledged';
    public const RESOLVED = 'resolved';

    public const KINDS = ['low_stock', 'service_due', 'document_expiry', 'task_overdue', 'withdrawal', 'observation', 'harvest_due', 'budget_overrun', 'health_due',
        'machine_fault', 'warranty', 'cold_store', 'quarantine', 'recall', 'delivery_due', 'roster_gap', 'roster_draft', 'timesheets', 'piecework', 'corrections',
        'shift_swap', 'payroll', 'cash', 'integration'];

    protected $table = 'farm_alerts';

    protected $casts = ['due_on' => 'date', 'acknowledged_at' => \App\Modules\Agri\Casts\FarmDateTime::class, 'resolved_at' => \App\Modules\Agri\Casts\FarmDateTime::class, 'last_seen_at' => \App\Modules\Agri\Casts\FarmDateTime::class];

    public function getActivitylogOptions(): \Spatie\Activitylog\LogOptions
    {
        // Alerts are refreshed by every scan; only a person's acknowledgement is worth an audit line.
        return parent::getActivitylogOptions()->logOnly(['status', 'acknowledged_by']);
    }
}
