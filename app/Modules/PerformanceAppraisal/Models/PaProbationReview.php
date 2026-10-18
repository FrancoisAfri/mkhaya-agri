<?php

namespace App\Modules\PerformanceAppraisal\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PaProbationReview extends Model
{
    use LogsActivity;
    protected $table = 'pa_probation_reviews';

    protected $fillable = [
        'employee_id', 'manager_id', 'cycle_id', 'probation_type',
        'start_date', 'end_date', 'status', 'recommendation',
        'risk_score', 'risk_level', 'success_probability',
        'ai_prediction', 'ai_summary', 'notes', 'created_by',
    ];

    protected $casts = [
        'start_date'          => 'date',
        'end_date'            => 'date',
        'success_probability' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('performance_appraisal')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => match($e) {
                'created' => 'Probation Review created',
                'updated' => 'Probation Review updated',
                'deleted' => 'Probation Review deleted',
                default   => $e,
            });
    }

    public function employee(): BelongsTo  { return $this->belongsTo(User::class, 'employee_id'); }
    public function manager(): BelongsTo   { return $this->belongsTo(User::class, 'manager_id'); }
    public function creator(): BelongsTo   { return $this->belongsTo(User::class, 'created_by'); }
    public function cycle(): BelongsTo     { return $this->belongsTo(PaCycle::class); }

    public function objectives(): HasMany  { return $this->hasMany(PaProbationObjective::class, 'review_id'); }
    public function checkins(): HasMany    { return $this->hasMany(PaProbationCheckin::class, 'review_id'); }
    public function feedback(): HasMany    { return $this->hasMany(PaProbationFeedback::class, 'review_id'); }
    public function riskScores(): HasMany  { return $this->hasMany(PaProbationRiskScore::class, 'review_id'); }

    public function latestRiskScore(): ?PaProbationRiskScore
    {
        return $this->riskScores()->latest()->first();
    }

    public function objectivesCompletionRate(): float
    {
        $objectives = $this->objectives;
        if ($objectives->isEmpty()) return 0;

        $totalWeight = $objectives->sum('weight') ?: 100;
        $metWeight   = $objectives->where('status', 'met')->sum('weight');

        return round(($metWeight / $totalWeight) * 100, 1);
    }

    public function daysRemaining(): int
    {
        return max(0, (int) now()->diffInDays($this->end_date, false));
    }

    public function percentElapsed(): float
    {
        $total   = max(1, $this->start_date->diffInDays($this->end_date));
        $elapsed = $this->start_date->diffInDays(now());
        return min(100, round(($elapsed / $total) * 100, 1));
    }
}
