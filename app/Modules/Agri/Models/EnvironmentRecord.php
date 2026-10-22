<?php

namespace App\Modules\Agri\Models;

/** Water, energy, soil, waste, container disposal, rehabilitation, biodiversity — and carbon projects, only ever verified with evidence (§11.3). */
class EnvironmentRecord extends FarmOwnedModel
{
    // water_quality: a laboratory test of irrigation or post-harvest water (certification schemes ask for one a year).
    public const KINDS = ['water', 'water_quality', 'energy', 'fuel', 'soil', 'erosion', 'waste', 'container_disposal', 'rehabilitation', 'biodiversity', 'carbon_project'];

    protected $table = 'agri_environment_records';

    protected $hidden = ['evidence_data'];

    protected $casts = ['recorded_on' => 'date', 'quantity' => 'float', 'details' => 'array'];
}
