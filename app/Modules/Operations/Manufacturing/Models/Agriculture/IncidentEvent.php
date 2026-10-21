<?php

namespace App\Modules\Operations\Manufacturing\Models\Agriculture;

/** One step in an incident's life: opened, repeated, notified, escalated, acknowledged, resolved, closed. */
class IncidentEvent extends ProductionModel
{
    protected $table = 'prd_incident_events';

    protected $casts = ['detail' => 'array', 'at' => 'datetime'];
}
