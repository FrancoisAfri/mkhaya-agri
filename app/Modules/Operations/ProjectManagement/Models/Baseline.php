<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\AuditsChanges;
use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The schedule as it was agreed (PM-1233).
 *
 * Audited, because re-baselining is how a late project is made to look on time. Who did it, when,
 * and the reason they gave are the parts that matter later.
 */
class Baseline extends Model
{
    use AuditsChanges, BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_baselines';

    protected $fillable = [
        'uuid', 'company_id', 'project_id', 'name', 'reason', 'captured_at', 'captured_by',
        'planned_start', 'planned_finish', 'task_count', 'budget_amount', 'currency', 'is_current',
    ];

    protected $casts = [
        'captured_at'    => 'datetime',
        'planned_start'  => 'datetime',
        'planned_finish' => 'datetime',
        'is_current'     => 'boolean',
        'task_count'     => 'integer',
        'budget_amount'  => 'decimal:2',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(BaselineTask::class, 'baseline_id');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_current', true);
    }
}
