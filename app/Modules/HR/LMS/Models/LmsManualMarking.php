<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsManualMarking extends Model
{
    protected $table = 'lms_manual_marking';

    protected $fillable = [
        'response_id', 'attempt_id', 'question_id', 'questionnaire_id', 'user_id',
        'assigned_to', 'status', 'max_points', 'points_awarded',
        'marker_notes', 'marked_by', 'marked_at',
    ];

    protected $casts = [
        'points_awarded' => 'float',
        'marked_at'      => 'datetime',
    ];

    public function response(): BelongsTo
    {
        return $this->belongsTo(LmsQuestionResponse::class, 'response_id');
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(LmsQuestionnaireAttempt::class, 'attempt_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(LmsQuestion::class, 'question_id');
    }

    public function questionnaire(): BelongsTo
    {
        return $this->belongsTo(LmsQuestionnaire::class, 'questionnaire_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }
}
