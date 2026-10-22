<?php

namespace App\Modules\Agri\Models;

/** Grower portal: A message from the company to one grower, or to every grower of a farm. */
class GrowerNotice extends AgriModel
{
    protected $table = 'agri_grower_notices';

    protected $casts = [];
}
