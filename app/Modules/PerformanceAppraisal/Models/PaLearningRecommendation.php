<?php

namespace App\Modules\PerformanceAppraisal\Models;

use Illuminate\Database\Eloquent\Model;

class PaLearningRecommendation extends Model
{
    protected $table = 'pa_learning_recommendations';
    protected $guarded = [];

    protected $casts = [
        'ai_generated'    => 'boolean',
        'estimated_hours' => 'integer',
    ];
}
