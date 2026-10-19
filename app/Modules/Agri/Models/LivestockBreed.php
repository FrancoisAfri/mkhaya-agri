<?php

namespace App\Modules\Agri\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LivestockBreed extends AgriModel
{
    protected $table = 'livestock_breeds';

    protected $casts = ['is_active' => 'boolean'];

    public function species(): BelongsTo
    {
        return $this->belongsTo(LivestockSpecies::class, 'species_id');
    }
}
