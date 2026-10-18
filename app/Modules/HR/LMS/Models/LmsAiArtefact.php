<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsAiArtefact extends Model
{
    protected $table = 'lms_ai_artefacts';

    public const TYPES = [
        'summary'             => 'Lesson Summary',
        'study_guide'         => 'Study Guide',
        'practice_quiz'       => 'Practice Quiz',
        'policy_explanation'  => 'Policy Explanation',
        'compliance_report'   => 'Compliance Report',
    ];

    protected $fillable = [
        'user_id', 'session_id', 'artefact_type', 'context_type', 'context_id',
        'title', 'content', 'structured_data', 'tokens_used',
    ];

    protected $casts = [
        'structured_data' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->artefact_type] ?? $this->artefact_type;
    }
}
