<?php

namespace App\Modules\PerformanceAppraisal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PaPipPlan extends Model
{
    use LogsActivity;
    protected $table = 'pa_pip_plans';
    protected $guarded = [];

    protected $casts = [
        'start_date'      => 'date',
        'target_end_date' => 'date',
        'actual_end_date' => 'date',
    ];

    public function objectives(): HasMany
    {
        return $this->hasMany(PaPipObjective::class, 'pip_plan_id');
    }

    public function progressLogs(): HasMany
    {
        return $this->hasMany(PaPipProgressLog::class, 'pip_plan_id')->orderByDesc('logged_at');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(PaPipReview::class, 'pip_plan_id')->orderBy('scheduled_date');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('performance_appraisal')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => match($e) {
                'created' => 'PIP Plan created',
                'updated' => 'PIP Plan updated',
                'deleted' => 'PIP Plan deleted',
                default   => $e,
            });
    }

    public function overallProgress(): int
    {
        $objectives = $this->objectives;
        if ($objectives->isEmpty()) return 0;

        $totalWeight = $objectives->sum('weight') ?: 100;
        $met         = $objectives->where('status', 'met')->sum('weight');
        $inProgress  = $objectives->where('status', 'in_progress')->sum('weight') * 0.5;

        return (int) min(100, round((($met + $inProgress) / $totalWeight) * 100));
    }

    public function daysRemaining(): int
    {
        return max(0, (int) now()->diffInDays($this->target_end_date, false));
    }
}
