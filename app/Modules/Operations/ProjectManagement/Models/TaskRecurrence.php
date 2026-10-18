<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The rule behind "inspect the scaffold, every Monday" (PM-1122).
 *
 * The recurrence hangs off a template task that is never worked on: occurrences are copied from it
 * and are ordinary tasks in every other respect, so completing one needs no special case in the
 * ledger, the rollup or the schedule.
 */
class TaskRecurrence extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    /** Materialise at most this far ahead, whatever the rule says. */
    public const MAX_HORIZON_DAYS = 365;

    protected $table = 'pm_task_recurrences';

    protected $fillable = [
        'uuid', 'company_id', 'project_id', 'template_task_id', 'rrule', 'timezone',
        'starts_at', 'ends_at', 'horizon_days', 'last_generated_at', 'last_occurrence_at',
        'generated_count', 'is_active', 'created_by',
    ];

    protected $casts = [
        'starts_at'          => 'datetime',
        'ends_at'            => 'datetime',
        'last_generated_at'  => 'datetime',
        'last_occurrence_at' => 'datetime',
        'horizon_days'       => 'integer',
        'generated_count'    => 'integer',
        'is_active'          => 'boolean',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'template_task_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }
}
