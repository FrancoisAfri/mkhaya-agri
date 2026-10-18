<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsPaTrainingRecommendation extends Model
{
    protected $table = 'lms_pa_training_recommendations';

    protected $fillable = [
        'employee_id', 'cycle_id', 'snapshot_id', 'course_id',
        'gap_area', 'kpi_title', 'score_pct', 'recommendation_reason',
        'status', 'priority', 'created_by', 'enrolled_at', 'completed_at',
    ];

    protected $casts = [
        'score_pct'    => 'float',
        'enrolled_at'  => 'datetime',
        'completed_at' => 'datetime',
    ];

    public const STATUS_OPTIONS = [
        'pending'   => 'Pending',
        'accepted'  => 'Accepted',
        'enrolled'  => 'Enrolled',
        'completed' => 'Completed',
        'dismissed' => 'Dismissed',
    ];

    public const PRIORITY_OPTIONS = [
        'low'      => 'Low',
        'medium'   => 'Medium',
        'high'     => 'High',
        'critical' => 'Critical',
    ];

    public function employee(): BelongsTo { return $this->belongsTo(User::class, 'employee_id'); }
    public function course(): BelongsTo   { return $this->belongsTo(LmsCourse::class, 'course_id'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    public function statusColor(): string
    {
        return match ($this->status) {
            'completed' => 'success',
            'enrolled'  => 'info',
            'accepted'  => 'primary',
            'dismissed' => 'default',
            default     => 'warning',
        };
    }

    public function priorityColor(): string
    {
        return match ($this->priority) {
            'critical' => 'error',
            'high'     => 'warning',
            'medium'   => 'info',
            default    => 'default',
        };
    }
}
