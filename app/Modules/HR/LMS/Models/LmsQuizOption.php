<?php

namespace App\Modules\HR\LMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsQuizOption extends Model
{
    protected $table = 'lms_quiz_options';

    protected $fillable = [
        'question_id',
        'option_text',
        'is_correct',
        'sort_order',
    ];

    protected $casts = [
        'is_correct'  => 'boolean',
        'sort_order'  => 'integer',
    ];

    public function question(): BelongsTo
    {
        return $this->belongsTo(LmsQuizQuestion::class, 'question_id');
    }
}
