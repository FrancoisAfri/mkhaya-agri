<?php

namespace App\Modules\PerformanceAppraisal\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PaCoachingSession extends Model
{
    use LogsActivity;

    protected $table = 'pa_coaching_sessions';

    protected $fillable = [
        'employee_id', 'coach_id', 'cycle_id', 'title',
        'session_date', 'duration_minutes', 'objectives', 'outcomes', 'next_steps',
        'status', 'created_by',
    ];

    protected $casts = [
        'session_date' => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function coach(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coach_id');
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PaCycle::class, 'cycle_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('performance_appraisal')
            ->logOnly(['employee_id', 'coach_id', 'title', 'session_date', 'status', 'outcomes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => match($e) {
                'created' => 'Coaching session scheduled',
                'updated' => 'Coaching session updated',
                'deleted' => 'Coaching session deleted',
                default   => $e,
            });
    }
}
