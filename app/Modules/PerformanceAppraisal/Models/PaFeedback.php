<?php

namespace App\Modules\PerformanceAppraisal\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PaFeedback extends Model
{
    use LogsActivity;
    protected $table = 'pa_feedback';

    protected $fillable = [
        'cycle_id', 'employee_id', 'given_by', 'feedback_type',
        'content', 'rating', 'is_anonymous', 'is_draft', 'acknowledged_at',
    ];

    protected $casts = [
        'rating'          => 'float',
        'is_anonymous'    => 'boolean',
        'is_draft'        => 'boolean',
        'acknowledged_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'given_by');
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PaCycle::class, 'cycle_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('performance_appraisal')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => match($e) {
                'created' => 'Performance Feedback submitted',
                'updated' => 'Performance Feedback updated',
                'deleted' => 'Performance Feedback deleted',
                default   => $e,
            });
    }

    public function getAuthorNameAttribute(): string
    {
        if ($this->is_anonymous) return 'Anonymous';

        return $this->author?->email ?? 'Unknown';
    }
}
