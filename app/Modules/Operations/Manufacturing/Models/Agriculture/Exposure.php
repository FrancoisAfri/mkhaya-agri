<?php

namespace App\Modules\Operations\Manufacturing\Models\Agriculture;

/** A farm within reach of a reported outbreak: a reason to review biosecurity, never a diagnosis. */
class Exposure extends ProductionModel
{
    protected $table = 'prd_exposures';

    protected $casts = ['assessed_at' => 'datetime', 'distance_km' => 'float'];

    public function outbreak(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Outbreak::class, 'outbreak_id'); }

    public function farm(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(\App\Modules\Agri\Models\Farm::class)->withoutGlobalScopes(); }
}
