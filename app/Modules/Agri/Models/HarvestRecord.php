<?php

namespace App\Modules\Agri\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HarvestRecord extends FarmOwnedModel
{
    public const DESTINATIONS = ['store', 'sold', 'own_use', 'loss'];

    protected $table = 'harvest_records';

    protected $casts = [
        'harvested_on' => 'date', 'quantity' => 'decimal:3', 'moisture_pct' => 'decimal:2',
        'area_harvested' => 'decimal:4', 'unit_value' => 'decimal:4',
        'rejected_quantity' => 'float', 'spoiled_quantity' => 'float', 'field_loss_quantity' => 'float',
    ];

    public function cropPlan(): BelongsTo
    {
        return $this->belongsTo(CropPlan::class);
    }
}
