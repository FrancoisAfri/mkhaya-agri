<?php

namespace App\Modules\Agri\Models;

/** One line of a crop's input plan from approved agronomic guidance: so much per hectare, or per tonne expected. */
class CropInputTemplate extends AgriModel
{
    protected $table = 'crop_input_templates';

    public const CATEGORIES = ['seed', 'fertiliser', 'chemical', 'water', 'labour', 'machinery', 'packaging', 'other'];

    protected $casts = ['rate' => 'float', 'approved_at' => \App\Modules\Agri\Casts\FarmDateTime::class];
}
