<?php

namespace App\Modules\Agri\Models;

/** A registry-ready package (zip) built for a carbon project, kept in the database. */
class CarbonExport extends FarmOwnedModel
{
    protected $table = 'agri_carbon_exports';

    protected $hidden = ['data'];

    protected $casts = ['contents' => 'array', 'size' => 'integer'];
}
