<?php

namespace App\Modules\Agri\Models;

/** A field's (or zone's) polygon as registered with a provider that keeps its own (AgroMonitoring). */
class SatellitePolygon extends FarmOwnedModel
{
    protected $table = 'agri_satellite_polygons';
}
