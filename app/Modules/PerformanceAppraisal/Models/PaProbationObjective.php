<?php

namespace App\Modules\PerformanceAppraisal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaProbationObjective extends Model
{
    protected $table = 'pa_probation_objectives';

    protected $fillable = [
        'review_id', 'title', 'description', 'weight',
        'target_value', 'achieved_value', 'unit', 'status', 'manager_notes',
    ];

    protected $casts = [
        'target_value'   => 'decimal:2',
        'achieved_value' => 'decimal:2',
    ];

    public function review(): BelongsTo
    {
        return $this->belongsTo(PaProbationReview::class, 'review_id');
    }

    public function achievementPct(): ?float
    {
        if (!$this->target_value || $this->target_value == 0) return null;
        return min(100, round(($this->achieved_value / $this->target_value) * 100, 1));
    }
}
