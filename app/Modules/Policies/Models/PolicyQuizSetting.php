<?php

namespace App\Modules\Policies\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyQuizSetting extends Model
{
    protected $table = 'policy_quiz_settings';

    protected $fillable = [
        'policy_id',
        'pass_mark',
        'max_attempts',
        'time_limit_minutes',
        'randomize_questions',
        'show_correct_answers',
    ];

    protected $casts = [
        'pass_mark'           => 'integer',
        'max_attempts'        => 'integer',
        'time_limit_minutes'  => 'integer',
        'randomize_questions' => 'boolean',
        'show_correct_answers'=> 'boolean',
    ];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }
}
