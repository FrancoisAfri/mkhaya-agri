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
 * Hours booked against work (EPIC-031).
 *
 * Only approved hours reach the TimeSink and count as verified evidence — logged hours are a claim
 * and are derived at lower confidence (PM-ADR-010). Keeping the two apart is what stops an
 * unreviewed timesheet from moving a cost report.
 */
class TimeEntry extends Model
{
    use AuditsChanges, BelongsToCompany, HasPublicUuid, SoftDeletes;

    public const STATUSES = ['draft', 'submitted', 'approved', 'rejected'];

    /** Nobody works more than this in a day, and a bigger number is a typo or a fiddle. */
    public const MAX_HOURS_PER_DAY = 24.0;

    protected $table = 'pm_time_entries';

    protected $fillable = [
        'uuid', 'company_id', 'project_id', 'task_id', 'member_id', 'worked_on', 'hours', 'note',
        'status', 'approved_by', 'approved_at', 'rejection_reason', 'sink_reference',
        'rate_per_hour', 'cost_amount', 'currency', 'created_by',
    ];

    protected $casts = [
        'worked_on'     => 'date',
        'hours'         => 'decimal:2',
        'approved_at'   => 'datetime',
        'rate_per_hour' => 'decimal:2',
        'cost_amount'   => 'decimal:2',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(ProjectMember::class, 'member_id');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    public function scopeAwaitingApproval(Builder $query): Builder
    {
        return $query->where('status', 'submitted');
    }

    /** Once approved, an entry is evidence; editing it would be rewriting what was signed off. */
    public function isLocked(): bool
    {
        return $this->status === 'approved';
    }
}
