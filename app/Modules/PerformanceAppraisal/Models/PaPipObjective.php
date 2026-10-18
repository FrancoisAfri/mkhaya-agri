<?php

namespace App\Modules\PerformanceAppraisal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PaPipObjective extends Model
{
    use LogsActivity;
    protected $table = 'pa_pip_objectives';
    protected $guarded = [];

    protected $casts = [
        'due_date' => 'date',
        'weight'   => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('performance_appraisal')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => match($e) {
                'created' => 'PIP Objective created',
                'updated' => 'PIP Objective updated',
                'deleted' => 'PIP Objective deleted',
                default   => $e,
            });
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PaPipPlan::class, 'pip_plan_id');
    }
}
