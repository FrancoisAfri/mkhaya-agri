<?php

namespace App\Modules\Agri\Models;

/** FAO-56 crop coefficients and root-zone figures for one crop, the organisation's to edit. */
class CropCoefficient extends AgriModel
{
    protected $table = 'agri_crop_coefficients';

    protected $casts = ['kc_ini' => 'float', 'kc_mid' => 'float', 'kc_end' => 'float', 'root_depth_m' => 'float', 'depletion_p' => 'float',
        'stage_ini_pct' => 'integer', 'stage_dev_pct' => 'integer', 'stage_mid_pct' => 'integer', 'stage_late_pct' => 'integer'];

    public function cropType(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CropType::class);
    }
}
