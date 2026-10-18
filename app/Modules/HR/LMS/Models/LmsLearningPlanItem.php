<?php

namespace App\Modules\HR\LMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsLearningPlanItem extends Model
{
    protected $table = 'lms_learning_plan_items';

    public const TYPES    = ['course', 'assessment', 'revision', 'reading', 'practice'];
    public const STATUSES = ['pending', 'in_progress', 'completed', 'skipped'];

    protected $fillable = [
        'learning_plan_id',
        'course_id',
        'questionnaire_id',
        'item_type',
        'title',
        'description',
        'reason',
        'priority',
        'sort_order',
        'due_date',
        'status',
        'completed_at',
    ];

    protected $casts = [
        'due_date'     => 'date',
        'completed_at' => 'datetime',
        'priority'     => 'integer',
        'sort_order'   => 'integer',
    ];

    public function learningPlan(): BelongsTo
    {
        return $this->belongsTo(LmsLearningPlan::class, 'learning_plan_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function questionnaire(): BelongsTo
    {
        return $this->belongsTo(LmsQuestionnaire::class, 'questionnaire_id');
    }
}
