<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One task, frozen at the moment the baseline was taken (PM-1233).
 *
 * No public uuid and no soft delete: these rows are not addressed from outside and are never
 * edited — a baseline that could be corrected afterwards would not be a baseline.
 */
class BaselineTask extends Model
{
    use BelongsToCompany;

    protected $table = 'pm_baseline_tasks';

    protected $fillable = [
        'company_id', 'baseline_id', 'task_id', 'task_code', 'name',
        'planned_start', 'planned_end', 'duration_minutes', 'is_critical', 'is_milestone', 'budget_amount',
    ];

    protected $casts = [
        'planned_start'    => 'datetime',
        'planned_end'      => 'datetime',
        'duration_minutes' => 'integer',
        'is_critical'      => 'boolean',
        'is_milestone'     => 'boolean',
        'budget_amount'    => 'decimal:2',
    ];

    public function baseline(): BelongsTo
    {
        return $this->belongsTo(Baseline::class, 'baseline_id');
    }
}
