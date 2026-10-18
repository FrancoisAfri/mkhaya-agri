<?php

namespace App\Modules\PerformanceAppraisal\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PaSuccessionPlan extends Model
{
    use LogsActivity;
    protected $table = 'pa_succession_plans';

    protected $fillable = [
        'position_title', 'department', 'incumbent_id', 'cycle_id',
        'is_critical_role', 'status', 'bench_strength', 'notes', 'ai_analysis', 'created_by',
    ];

    protected $casts = [
        'is_critical_role' => 'boolean',
    ];

    public function incumbent(): BelongsTo    { return $this->belongsTo(User::class, 'incumbent_id'); }
    public function creator(): BelongsTo      { return $this->belongsTo(User::class, 'created_by'); }
    public function cycle(): BelongsTo        { return $this->belongsTo(PaCycle::class); }
    public function candidates(): HasMany     { return $this->hasMany(PaSuccessionCandidate::class, 'plan_id'); }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('performance_appraisal')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => match($e) {
                'created' => 'Succession Plan created',
                'updated' => 'Succession Plan updated',
                'deleted' => 'Succession Plan deleted',
                default   => $e,
            });
    }

    public function readyNowCount(): int
    {
        return $this->candidates()->where('readiness_level', 'ready_now')->count();
    }
}
