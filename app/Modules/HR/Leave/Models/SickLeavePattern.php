<?php

namespace App\Modules\HR\Leave\Models;

use App\Modules\HR\Hr\Models\HRPerson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SickLeavePattern extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('SickLeavePattern')
            ->logOnly(['employee_id', 'pattern_type', 'flagged_for_review',
                       'reviewed_by', 'reviewed_at', 'review_outcome'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => match($e) {
                'created' => "Sick leave pattern #{$this->id} ({$this->pattern_type}) detected for employee #{$this->employee_id}",
                'updated' => $this->isDirty('review_outcome')
                    ? "Sick leave pattern #{$this->id} reviewed: {$this->review_outcome}"
                    : "Sick leave pattern #{$this->id} updated",
                'deleted' => "Sick leave pattern #{$this->id} deleted",
                default   => "Sick leave pattern #{$this->id}: {$e}",
            });
    }

    protected $table = 'sick_leave_patterns';

    protected $fillable = [
        'employee_id', 'leave_request_id', 'pattern_type',
        'detection_date', 'pattern_start', 'pattern_end', 'occurrence_count',
        'rolling_window_start', 'rolling_window_end', 'total_sick_hours_in_window',
        'flagged_for_review', 'reviewed_by', 'reviewed_at', 'review_outcome',
        'ai_confidence_score',
    ];

    protected $casts = [
        'detection_date'            => 'date',
        'pattern_start'             => 'date',
        'pattern_end'               => 'date',
        'rolling_window_start'      => 'date',
        'rolling_window_end'        => 'date',
        'total_sick_hours_in_window' => 'decimal:2',
        'ai_confidence_score'       => 'decimal:2',
        'flagged_for_review'        => 'boolean',
        'reviewed_at'               => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HRPerson::class, 'employee_id');
    }
}
