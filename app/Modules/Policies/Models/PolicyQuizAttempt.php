<?php

namespace App\Modules\Policies\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyQuizAttempt extends Model
{
    protected $table = 'policy_quiz_attempts';

    protected $fillable = [
        'policy_id',
        'user_id',
        'attestation_id',
        'attempt_number',
        'total_questions',
        'correct_answers_count',
        'score',
        'passed',
        'answers',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'answers'              => 'array',
        'passed'               => 'boolean',
        'score'                => 'integer',
        'attempt_number'       => 'integer',
        'total_questions'      => 'integer',
        'correct_answers_count'=> 'integer',
        'started_at'           => 'datetime',
        'completed_at'         => 'datetime',
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attestation(): BelongsTo
    {
        return $this->belongsTo(PolicyAttestation::class);
    }
}
