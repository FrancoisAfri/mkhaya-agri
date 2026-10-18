<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\AuditsChanges;
use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Who is on a task, and for how much of their time (EPIC-030).
 *
 * `allocation_pct` is the difference between "Thabo is on this" and "Thabo is on this full time".
 * A resource report built on the first reads every assignment as a whole person and over-commits
 * everyone on site.
 */
class TaskAssignment extends Model
{
    use AuditsChanges, BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_task_assignments';

    protected $fillable = [
        'uuid', 'company_id', 'project_id', 'task_id', 'member_id', 'role',
        'allocation_pct', 'is_lead', 'assigned_at', 'assigned_by',
    ];

    protected $casts = [
        'allocation_pct' => 'integer',
        'is_lead'        => 'boolean',
        'assigned_at'    => 'datetime',
    ];

    /**
     * The project the assignment sits on.
     *
     * Denormalised onto the row rather than reached through the task, because every report and
     * every scope filters by project and joining through pm_tasks to get there would be a second
     * join on the hottest query in the module.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(ProjectMember::class, 'member_id');
    }
}
