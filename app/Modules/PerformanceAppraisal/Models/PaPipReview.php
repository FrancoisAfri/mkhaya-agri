<?php

namespace App\Modules\PerformanceAppraisal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PaPipReview extends Model
{
    use LogsActivity;
    protected $table = 'pa_pip_reviews';
    protected $guarded = [];

    protected $casts = [
        'scheduled_date' => 'date',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('performance_appraisal')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => match($e) {
                'created' => 'PIP Review created',
                'updated' => 'PIP Review updated',
                'deleted' => 'PIP Review deleted',
                default   => $e,
            });
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PaPipPlan::class, 'pip_plan_id');
    }
}
