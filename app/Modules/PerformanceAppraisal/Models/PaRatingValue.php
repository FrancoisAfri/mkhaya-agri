<?php

namespace App\Modules\PerformanceAppraisal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaRatingValue extends Model
{
    protected $table = 'pa_rating_values';

    protected $fillable = [
        'rating_scale_id', 'label', 'value', 'description', 'color', 'sort_order',
    ];

    protected $casts = [
        'value' => 'float',
    ];

    public function scale(): BelongsTo
    {
        return $this->belongsTo(PaRatingScale::class, 'rating_scale_id');
    }
}
