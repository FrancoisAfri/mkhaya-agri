<?php

namespace App\Modules\PerformanceAppraisal\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PaBehaviorSignal extends Model
{
    use LogsActivity;

    protected $table = 'pa_behavior_signals';

    protected $fillable = [
        'employee_id', 'cycle_id', 'signal_type', 'severity',
        'title', 'description', 'data', 'acknowledged_by', 'acknowledged_at',
    ];

    protected $casts = [
        'data'             => 'array',
        'acknowledged_at'  => 'datetime',
    ];

    public function employee(): BelongsTo      { return $this->belongsTo(User::class, 'employee_id'); }
    public function cycle(): BelongsTo         { return $this->belongsTo(PaCycle::class); }
    public function acknowledger(): BelongsTo  { return $this->belongsTo(User::class, 'acknowledged_by'); }

    public function isAcknowledged(): bool
    {
        return $this->acknowledged_at !== null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('performance_appraisal')
            ->logOnly(['employee_id', 'signal_type', 'severity', 'title', 'acknowledged_by', 'acknowledged_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => match($e) {
                'created' => 'Behaviour signal detected',
                'updated' => 'Behaviour signal updated',
                'deleted' => 'Behaviour signal deleted',
                default   => $e,
            });
    }
}
