<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class LmsQuestionResponse extends Model
{
    protected $table = 'lms_question_responses';

    protected $fillable = [
        'attempt_id', 'question_id', 'response_text', 'response_json',
        'file_path', 'file_disk', 'file_name', 'is_correct', 'points_awarded',
        'reviewed_by', 'reviewed_at', 'reviewer_notes',
    ];

    protected $casts = [
        'response_json' => 'array',
        'is_correct'    => 'boolean',
        'points_awarded'=> 'float',
        'reviewed_at'   => 'datetime',
    ];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(LmsQuestionnaireAttempt::class, 'attempt_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(LmsQuestion::class, 'question_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function getFileUrlAttribute(): ?string
    {
        if (!$this->file_path || !$this->file_disk) {
            return null;
        }
        return Storage::disk($this->file_disk)->url($this->file_path);
    }
}
