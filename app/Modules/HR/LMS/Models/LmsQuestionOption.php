<?php

namespace App\Modules\HR\LMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsQuestionOption extends Model
{
    protected $table = 'lms_question_options';

    protected $fillable = [
        'question_id', 'option_text', 'is_correct', 'rating_value', 'sort_order',
    ];

    protected $casts = [
        'is_correct'   => 'boolean',
        'rating_value' => 'integer',
        'sort_order'   => 'integer',
    ];

    public function question(): BelongsTo
    {
        return $this->belongsTo(LmsQuestion::class, 'question_id');
    }
}
