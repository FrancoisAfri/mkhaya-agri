<?php

namespace App\Modules\PerformanceAppraisal\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PaCheckin extends Model
{
    use LogsActivity;
    protected $table = 'pa_checkins';

    protected $fillable = [
        'employee_id', 'manager_id', 'cycle_id', 'title',
        'scheduled_at', 'completed_at', 'duration_minutes', 'location',
        'agenda', 'notes', 'action_items', 'status', 'created_by',
        'recurrence', 'private_manager_notes', 'mood',
    ];

    protected $casts = [
        'scheduled_at'   => 'datetime',
        'completed_at'   => 'datetime',
        'action_items'   => 'array',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('performance_appraisal')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => match($e) {
                'created' => 'Check-in scheduled',
                'updated' => 'Check-in updated',
                'deleted' => 'Check-in deleted',
                default   => $e,
            });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PaCycle::class, 'cycle_id');
    }

    public function talkingPoints(): HasMany
    {
        return $this->hasMany(PaCheckinTalkingPoint::class, 'checkin_id')->orderBy('sort_order');
    }

    public function actionItemRecords(): HasMany
    {
        return $this->hasMany(PaCheckinActionItem::class, 'checkin_id')->orderBy('due_date');
    }
}
