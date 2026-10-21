<?php

namespace App\Modules\Operations\Manufacturing\Models\Agriculture;

/** Something in production that needs a person: an alarm, an interruption, an exposure, a failed command. */
class Incident extends ProductionModel
{
    protected $table = 'prd_incidents';

    protected $casts = ['evidence' => 'array', 'opened_at' => 'datetime', 'last_seen_at' => 'datetime', 'acknowledged_at' => 'datetime', 'next_escalation_at' => 'datetime', 'resolved_at' => 'datetime', 'closed_at' => 'datetime', 'observed_value' => 'float'];

    public const SEVERITIES = ['info', 'advisory', 'warning', 'critical', 'emergency'];
    public const OPEN_STATUSES = ['open', 'acknowledged', 'in_progress'];

    public function events(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(IncidentEvent::class, 'incident_id')->orderBy('at')->orderBy('id'); }

    public function notifications(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(NotificationLog::class, 'incident_id')->orderBy('sent_at'); }

    public function unit(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Unit::class, 'unit_id'); }

    public function farm(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(\App\Modules\Agri\Models\Farm::class)->withoutGlobalScopes(); }

    public function isOpen(): bool { return in_array($this->status, self::OPEN_STATUSES, true); }

    public function rank(): int { return (int) array_search($this->severity, self::SEVERITIES, true); }
}
