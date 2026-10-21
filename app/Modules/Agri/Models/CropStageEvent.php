<?php

namespace App\Modules\Agri\Models;

/** A growth stage reached by a crop, when it was seen. */
class CropStageEvent extends FarmOwnedModel
{
    protected $table = 'crop_stage_events';

    protected $casts = ['observed_on' => 'date'];
}
