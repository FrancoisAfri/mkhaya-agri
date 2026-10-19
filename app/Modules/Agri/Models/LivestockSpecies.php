<?php

namespace App\Modules\Agri\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class LivestockSpecies extends AgriModel
{
    public const TRACKING = ['individual', 'group'];

    protected $table = 'livestock_species';

    protected $casts = ['is_active' => 'boolean'];

    public function breeds(): HasMany
    {
        return $this->hasMany(LivestockBreed::class, 'species_id');
    }
}
