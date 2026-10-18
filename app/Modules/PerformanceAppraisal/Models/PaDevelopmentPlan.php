<?php

namespace App\Modules\PerformanceAppraisal\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PaDevelopmentPlan extends Model
{
    use LogsActivity;
    protected $table = 'pa_development_plans';

    protected $fillable = [
        'employee_id', 'manager_id', 'cycle_id', 'title', 'career_goal',
        'strengths', 'development_areas', 'status', 'overall_progress',
        'ai_recommendations', 'manager_notes', 'target_completion_date', 'created_by',
    ];

    protected $casts = [
        'target_completion_date' => 'date',
    ];

    public function employee(): BelongsTo     { return $this->belongsTo(User::class, 'employee_id'); }
    public function manager(): BelongsTo      { return $this->belongsTo(User::class, 'manager_id'); }
    public function creator(): BelongsTo      { return $this->belongsTo(User::class, 'created_by'); }
    public function cycle(): BelongsTo        { return $this->belongsTo(PaCycle::class); }
    public function activities(): HasMany     { return $this->hasMany(PaDevelopmentActivity::class, 'plan_id')->orderBy('sort_order'); }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('performance_appraisal')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => match($e) {
                'created' => 'Development Plan created',
                'updated' => 'Development Plan updated',
                'deleted' => 'Development Plan deleted',
                default   => $e,
            });
    }

    public function computeProgress(): int
    {
        $activities = $this->activities;
        if ($activities->isEmpty()) return 0;
        $completed = $activities->where('status', 'completed')->count();
        return (int) round(($completed / $activities->count()) * 100);
    }
}
