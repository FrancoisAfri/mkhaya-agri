<?php

namespace App\Modules\PerformanceAppraisal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaPolicyException extends Model
{
    protected $table = 'pa_policy_exceptions';
    protected $guarded = [];

    protected $casts = [
        'valid_until' => 'date',
    ];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(PaPolicy::class, 'policy_id');
    }
}
