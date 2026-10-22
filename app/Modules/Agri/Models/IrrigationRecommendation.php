<?php

namespace App\Modules\Agri\Models;

/** When and how much to irrigate a zone, from its water balance; a person turns it into a planned irrigation. */
class IrrigationRecommendation extends FarmOwnedModel
{
    public const OPEN = 'open';
    public const PLANNED = 'planned';
    public const DISMISSED = 'dismissed';
    public const SUPERSEDED = 'superseded';

    protected $table = 'agri_irrigation_recommendations';

    protected $casts = ['recommend_on' => 'date', 'computed_at' => \App\Modules\Agri\Casts\FarmDateTime::class, 'depletion_mm' => 'float', 'raw_mm' => 'float',
        'net_mm' => 'float', 'gross_mm' => 'float', 'volume_m3' => 'float', 'area_ha' => 'float'];

    public function zone(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(IrrigationZone::class, 'zone_id');
    }
}
