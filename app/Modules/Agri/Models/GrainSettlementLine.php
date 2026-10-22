<?php

namespace App\Modules\Agri\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One delivery on a buyer's statement, and the load ticket of ours it matched. */
class GrainSettlementLine extends AgriModel
{
    protected $table = 'agri_grain_settlement_lines';

    protected $casts = ['delivered_on' => 'date', 'gross_kg' => 'float', 'tare_kg' => 'float', 'net_kg' => 'float', 'moisture_pct' => 'float',
        'foreign_matter_pct' => 'float', 'deductions_kg' => 'float', 'settled_kg' => 'float', 'price_per_t' => 'float', 'amount' => 'float',
        'expected_amount' => 'float', 'differences' => 'array'];

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(GrainSettlement::class, 'settlement_id');
    }
}
