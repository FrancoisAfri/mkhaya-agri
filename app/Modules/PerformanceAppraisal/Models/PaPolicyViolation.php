<?php

namespace App\Modules\PerformanceAppraisal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaPolicyViolation extends Model
{
    protected $table = 'pa_policy_violations';
    protected $guarded = [];

    protected $casts = [
        'violation_data' => 'array',
        'resolved_at'    => 'datetime',
    ];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(PaPolicy::class, 'policy_id');
    }

    public function escalations(): HasMany
    {
        return $this->hasMany(PaEscalation::class, 'violation_id');
    }
}
