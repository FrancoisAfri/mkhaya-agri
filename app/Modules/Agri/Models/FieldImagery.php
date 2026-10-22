<?php

namespace App\Modules\Agri\Models;

/** A vegetation index for a field from one capture, with its change from the last (§16B). */
class FieldImagery extends FarmOwnedModel
{
    public const INDICES = ['ndvi', 'ndre', 'evi', 'savi', 'ndwi'];

    protected $table = 'agri_field_imagery';

    protected $hidden = ['image_data'];

    protected $casts = ['captured_on' => 'date', 'mean' => 'float', 'min' => 'float', 'max' => 'float', 'cloud_pct' => 'float', 'cells' => 'array', 'change_pct' => 'float'];

    /** The rendered picture is kept once, on the capture — not again in every audit entry. */
    public function getActivitylogOptions(): \Spatie\Activitylog\LogOptions
    {
        return parent::getActivitylogOptions()->logExcept(['updated_at', 'created_at', 'data', 'image_data']);
    }
}
