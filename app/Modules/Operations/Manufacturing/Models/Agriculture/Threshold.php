<?php

namespace App\Modules\Operations\Manufacturing\Models\Agriculture;

/** The range a measurement must stay inside, for a production type or one unit. */
class Threshold extends ProductionModel
{
    protected $table = 'prd_thresholds';

    protected $casts = ['is_active' => 'boolean', 'min_value' => 'float', 'max_value' => 'float'];

    public function unit(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Unit::class, 'unit_id'); }
}
