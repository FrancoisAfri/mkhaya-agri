<?php

namespace App\Modules\Agri\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CropObservation extends FarmOwnedModel
{
    public const KINDS = ['pest', 'disease', 'weed', 'growth_stage', 'nutrient', 'water_stress', 'weather_damage', 'other'];

    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    public const STATUSES = ['open', 'actioned', 'closed'];

    protected $table = 'crop_observations';

    protected $casts = ['observed_on' => 'date', 'action_required' => 'boolean'];

    public function cropPlan(): BelongsTo
    {
        return $this->belongsTo(CropPlan::class);
    }
}
