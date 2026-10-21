<?php

namespace App\Modules\Operations\Manufacturing\Models\Agriculture;

/** A photo looked at by the vision model: what it saw and how sure it is. A prompt to look, never a diagnosis. */
class PhotoAnalysis extends ProductionModel
{
    protected $table = 'prd_photo_analyses';

    protected $hidden = ['image_data'];

    protected $casts = ['findings' => 'array'];
}
