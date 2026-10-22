<?php

namespace App\Modules\Agri\Models;

/** Grower portal: A field visit or inspection planned with a grower. */
class GrowerVisit extends AgriModel
{
    protected $table = 'agri_grower_visits';

    protected $casts = ['planned_on' => 'date'];
}
