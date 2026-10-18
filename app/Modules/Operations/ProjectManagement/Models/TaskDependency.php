<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\AuditsChanges;
use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A precedence link between two tasks — the input to the critical-path pass.
 *
 *   fs  finish-to-start   successor starts after predecessor finishes (the common case)
 *   ss  start-to-start    successor starts after predecessor starts
 *   ff  finish-to-finish  successor finishes after predecessor finishes
 *   sf  start-to-finish   successor finishes after predecessor starts (rare, but real)
 *
 * lag_minutes shifts the constraint; a negative value is a lead ("start 2 days before the
 * predecessor finishes"). Self-links are rejected by a CHECK constraint; longer cycles are detected
 * by the scheduling engine, which names the offending path instead of looping.
 */
class TaskDependency extends Model
{
    use AuditsChanges, BelongsToCompany, HasPublicUuid, LogsActivity, SoftDeletes;

    protected $table = 'pm_task_dependencies';

    protected $fillable = [
        'uuid', 'company_id', 'project_id', 'predecessor_id', 'successor_id',
        'dep_type', 'lag_minutes', 'created_by',
    ];

    protected $casts = ['lag_minutes' => 'integer'];

    public const TYPES = ['fs', 'ss', 'ff', 'sf'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Project Management')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'predecessor_id');
    }

    public function successor(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'successor_id');
    }
}
