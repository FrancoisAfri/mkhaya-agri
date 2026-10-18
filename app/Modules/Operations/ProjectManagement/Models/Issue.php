<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Something that has already happened (§12).
 *
 * Issues, bugs, defects and incidents share this table because they differ by consequence and
 * routing rather than by shape. Splitting them into four registers means a team has to decide which
 * one a problem belongs in before recording it — and the reliable outcome of that is that it gets
 * recorded nowhere.
 *
 * `root_cause`, `corrective_action` and `preventative_action` are the three questions an audit asks
 * and most trackers cannot answer. They are nullable while the issue is open and prompted for at
 * closure.
 */
class Issue extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_issues';

    public const KINDS = ['issue', 'bug', 'defect', 'incident'];

    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    public const STATUSES = ['open', 'in_progress', 'resolved', 'closed'];

    protected $fillable = [
        'uuid', 'company_id', 'project_id', 'code', 'title', 'description', 'kind',
        'severity', 'priority', 'status', 'raised_by', 'assigned_to', 'task_id', 'risk_id',
        'due_date', 'resolved_at', 'resolution', 'root_cause', 'corrective_action',
        'preventative_action', 'cost_impact', 'currency',
    ];

    protected $casts = [
        'due_date'    => 'date',
        'resolved_at' => 'datetime',
        'cost_impact' => 'decimal:2',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /** The risk that came true, when this issue was foreseen. */
    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class, 'risk_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['open', 'in_progress']);
    }

    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && $this->due_date->isPast()
            && in_array($this->status, ['open', 'in_progress'], true);
    }

    /**
     * An incident or a critical issue has to say what caused it and what stops it recurring.
     *
     * The same reasoning as a non-conformance on the snag register: a register full of blank
     * closures is one nobody can stand behind when it is asked for.
     */
    public function needsRootCause(): bool
    {
        return $this->kind === 'incident' || $this->severity === 'critical';
    }
}
