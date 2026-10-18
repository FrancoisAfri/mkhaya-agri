<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\AuditsChanges;
use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A defect, punch-list item or non-conformance (EPIC-100).
 *
 * One table for all three because they are the same shape and differ only in consequence: a snag
 * is a paint scuff, an NCR is a formal quality failure that has to be closed out with evidence
 * before anybody signs anything. Splitting them into three tables would triple the screens and the
 * reports for no gain.
 */
class Snag extends Model
{
    use AuditsChanges, BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_snags';

    protected $fillable = [
        'uuid', 'company_id', 'project_id', 'task_id', 'reference', 'kind', 'title', 'description',
        'location', 'severity', 'status', 'raised_by', 'assigned_member_id', 'contractor_ref_id',
        'due_date', 'closed_at', 'closed_by', 'closure_note', 'photo_before_id', 'photo_after_id',
    ];

    protected $casts = ['due_date' => 'date', 'closed_at' => 'datetime'];

    public const KINDS = ['snag', 'defect', 'ncr'];

    public const SEVERITIES = ['minor', 'major', 'critical'];

    public const STATUSES = ['open', 'in_progress', 'ready', 'closed', 'rejected'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function assignedMember(): BelongsTo
    {
        return $this->belongsTo(ProjectMember::class, 'assigned_member_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['closed', 'rejected']);
    }

    /**
     * An NCR cannot be closed on somebody's word.
     *
     * The whole point of raising one is that a quality failure is closed out with evidence, and a
     * register full of NCRs closed with a blank note is a register nobody can rely on at handover.
     */
    public function needsEvidenceToClose(): bool
    {
        return $this->kind === 'ncr' || $this->severity === 'critical';
    }
}
