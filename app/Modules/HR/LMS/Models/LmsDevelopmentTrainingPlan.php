<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsDevelopmentTrainingPlan extends Model
{
    protected $table = 'lms_development_training_plans';

    protected $fillable = [
        'pa_plan_id', 'employee_id', 'manager_id', 'cycle_id',
        'title', 'status', 'overall_progress', 'lms_activities',
        'notes', 'target_completion_date', 'created_by',
    ];

    protected $casts = [
        'lms_activities'       => 'array',
        'overall_progress'     => 'integer',
        'target_completion_date' => 'date',
    ];

    public function employee(): BelongsTo  { return $this->belongsTo(User::class, 'employee_id'); }
    public function manager(): BelongsTo   { return $this->belongsTo(User::class, 'manager_id'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    public function statusColor(): string
    {
        return match ($this->status) {
            'completed' => 'success',
            'active'    => 'info',
            'paused'    => 'warning',
            default     => 'default',
        };
    }
}
