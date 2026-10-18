<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * What the evidence adds up to, cached per (subject, metric).
 *
 * Never the system of record — it can be thrown away and recomputed from the ledger at any time.
 * Its value is the drill-down: `evidence_uuids` lists exactly the events behind the number, so
 * "why does this task say 62%?" is answerable without recomputation.
 *
 * `method_version` records which algorithm produced the value. When derivation logic changes, old
 * rows are not silently reinterpreted — they are recomputed and the version moves with them.
 *
 * An override lets a person state a different number, but never anonymously: who, why and when are
 * required, and the underlying evidence stays visible beneath the override.
 */
class DerivedValue extends Model
{
    use BelongsToCompany, HasPublicUuid, LogsActivity, SoftDeletes;

    protected $table = 'pm_derived_values';

    protected $fillable = [
        'uuid', 'company_id', 'subject_type', 'subject_id', 'subject_uuid', 'metric',
        'value_numeric', 'value_text', 'currency', 'confidence', 'method_version',
        'evidence_uuids', 'evidence_count', 'computed_at',
        'is_overridden', 'override_by', 'override_reason', 'overridden_at',
    ];

    protected $casts = [
        'evidence_uuids' => 'array',
        'evidence_count' => 'integer',
        'computed_at'    => 'datetime',
        'overridden_at'  => 'datetime',
        'is_overridden'  => 'boolean',
        'value_numeric'  => 'decimal:4',
        'confidence'     => 'decimal:2',
    ];

    /** Metrics derived in Phase 0. Cost/hours land with the timesheet and expense epics. */
    public const METRICS = [
        'progress_pct',
        'actual_cost',
        'actual_hours',
        'qty_installed',
        'last_activity_at',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Project Management')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
