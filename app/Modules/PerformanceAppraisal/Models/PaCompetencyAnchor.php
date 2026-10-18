<?php

namespace App\Modules\PerformanceAppraisal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaCompetencyAnchor extends Model
{
    protected $table = 'pa_competency_anchors';

    protected $fillable = ['competency_id', 'level', 'label', 'behavior'];

    protected $casts = ['level' => 'integer'];

    public function competency(): BelongsTo
    {
        return $this->belongsTo(PaCompetency::class, 'competency_id');
    }
}
