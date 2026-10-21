<?php

namespace App\Modules\Agri\Models;

/** A soil sample's laboratory results, where it was taken and the report. */
class SoilTest extends FarmOwnedModel
{
    protected $table = 'soil_tests';

    protected $hidden = ['report_data'];

    protected $casts = ['sampled_on' => 'date', 'other_results' => 'array'];

    public function field(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(FarmField::class, 'field_id');
    }
}
