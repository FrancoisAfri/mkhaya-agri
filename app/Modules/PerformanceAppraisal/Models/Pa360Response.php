<?php

namespace App\Modules\PerformanceAppraisal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pa360Response extends Model
{
    protected $table = 'pa_360_responses';

    protected $fillable = [
        'review_id', 'rater_id', 'question_id', 'question_snapshot',
        'category', 'rating_value', 'text_value', 'sort_order',
    ];

    protected $casts = [
        'rating_value' => 'float',
    ];

    public function review(): BelongsTo
    {
        return $this->belongsTo(Pa360Review::class, 'review_id');
    }

    public function rater(): BelongsTo
    {
        return $this->belongsTo(Pa360Rater::class, 'rater_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Pa360Question::class, 'question_id');
    }
}
