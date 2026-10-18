<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A timebox with a goal (§24).
 *
 * `committed_points` is captured when the sprint *starts* and never recomputed. That single
 * decision is what makes a burn-down honest: recomputing it would let work added on Wednesday
 * quietly raise the denominator, and every sprint would finish looking on target.
 */
class Sprint extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_sprints';

    public const STATUSES = ['planned', 'active', 'closed'];

    protected $fillable = [
        'uuid', 'company_id', 'project_id', 'name', 'number', 'goal', 'starts_on', 'ends_on',
        'status', 'committed_points', 'completed_points', 'started_at', 'closed_at', 'retrospective',
    ];

    protected $casts = [
        'starts_on'        => 'date',
        'ends_on'          => 'date',
        'number'           => 'integer',
        'committed_points' => 'decimal:2',
        'completed_points' => 'decimal:2',
        'started_at'       => 'datetime',
        'closed_at'        => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function stories(): HasMany
    {
        return $this->hasMany(Task::class, 'sprint_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /** Working days elapsed against the box, for a burn-down that does not count weekends. */
    public function dayOf(): ?int
    {
        if ($this->status !== 'active') {
            return null;
        }

        return (int) $this->starts_on->diffInWeekdays(min(now(), $this->ends_on)) + 1;
    }

    public function totalDays(): int
    {
        return max(1, (int) $this->starts_on->diffInWeekdays($this->ends_on) + 1);
    }
}
