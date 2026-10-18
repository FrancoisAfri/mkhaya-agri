<?php

namespace App\Modules\HR\LMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsQuestionSkillMapping extends Model
{
    protected $table = 'question_skill_mapping';

    protected $fillable = [
        'question_id',
        'skill_id',
    ];

    public function question(): BelongsTo
    {
        return $this->belongsTo(LmsQuestion::class, 'question_id');
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(LmsSkill::class, 'skill_id');
    }
}
