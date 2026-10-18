<?php

namespace App\Modules\HR\LMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsQuizAttempt extends Model
{
    protected $table = 'lms_quiz_attempts';

    protected $fillable = [
        'enrollment_id',
        'score',
        'passed',
        'answers',
        'attempted_at',
    ];

    protected $casts = [
        'score'        => 'integer',
        'passed'       => 'boolean',
        'answers'      => 'array',
        'attempted_at' => 'datetime',
    ];

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(LmsEnrollment::class, 'enrollment_id');
    }
}
