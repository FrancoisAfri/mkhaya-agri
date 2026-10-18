<?php

namespace App\Modules\PerformanceAppraisal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaTemplateQuestion extends Model
{
    protected $table = 'pa_template_questions';

    protected $fillable = [
        'section_id', 'question', 'description', 'question_type',
        'options', 'is_required', 'weight', 'sort_order',
    ];

    protected $casts = [
        'options'     => 'array',
        'is_required' => 'boolean',
        'weight'      => 'float',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(PaTemplateSection::class, 'section_id');
    }
}
