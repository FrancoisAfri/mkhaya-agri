<?php

namespace App\Modules\Agri\Models;

/** Requests made to the satellite provider by one organisation on one day, against its budget. */
class SatelliteUsage extends AgriModel
{
    protected $table = 'agri_satellite_usage';

    protected $casts = ['used_on' => 'date', 'requests' => 'integer'];
}
