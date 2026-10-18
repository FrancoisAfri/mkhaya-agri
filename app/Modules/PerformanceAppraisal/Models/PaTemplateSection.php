<?php

namespace App\Modules\PerformanceAppraisal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaTemplateSection extends Model
{
    protected $table = 'pa_template_sections';

    protected $fillable = [
        'template_id', 'name', 'description', 'weight', 'sort_order',
    ];

    protected $casts = [
        'weight' => 'float',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(PaTemplate::class, 'template_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(PaTemplateQuestion::class, 'section_id')->orderBy('sort_order');
    }
}
