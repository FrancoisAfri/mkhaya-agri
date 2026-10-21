<?php

namespace App\Modules\Operations\Manufacturing\Models\Agriculture;

/** A reported disease event from an official or trusted source. Reported near a farm is never the farm being infected. */
class Outbreak extends ProductionModel
{
    protected $table = 'prd_outbreaks';

    protected $casts = ['history' => 'array', 'reported_on' => 'date', 'latitude' => 'float', 'longitude' => 'float', 'radius_km' => 'float'];

    public function source(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Source::class, 'source_id'); }

    public function exposures(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(Exposure::class, 'outbreak_id'); }
}
