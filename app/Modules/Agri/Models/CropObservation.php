<?php

namespace App\Modules\Agri\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CropObservation extends FarmOwnedModel
{
    public const KINDS = ['pest', 'disease', 'weed', 'crop_damage', 'plant_health', 'growth_stage', 'nutrient', 'water_stress', 'weather_damage', 'other'];

    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    public const STATUSES = ['open', 'actioned', 'closed'];

    protected $table = 'crop_observations';

    protected $casts = ['observed_on' => 'date', 'action_required' => 'boolean', 'follow_up_on' => 'date', 'ai_suggestion' => 'array',
        'reviewed_at' => \App\Modules\Agri\Casts\FarmDateTime::class, 'escalated_at' => \App\Modules\Agri\Casts\FarmDateTime::class, 'closed_at' => \App\Modules\Agri\Casts\FarmDateTime::class, 'affected_area_ha' => 'float', 'incidence_pct' => 'float'];

    public function photos(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ObservationPhoto::class, 'observation_id');
    }

    public function cropPlan(): BelongsTo
    {
        return $this->belongsTo(CropPlan::class);
    }
}
