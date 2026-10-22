<?php

namespace App\Modules\Agri\Models;

/** A drop in a vegetation index that is expected — after a cut, mowing or harvest — and so raises no flag. */
class ImageryDropRule extends AgriModel
{
    protected $table = 'agri_imagery_drop_rules';

    protected $casts = ['activity_types' => 'array', 'after_harvest' => 'boolean', 'is_active' => 'boolean', 'within_days' => 'integer'];
}
