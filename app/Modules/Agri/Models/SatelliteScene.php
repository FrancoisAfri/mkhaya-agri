<?php

namespace App\Modules\Agri\Models;

/** One acquisition looked at for a field: stored, too cloudy or without data — never asked for twice. */
class SatelliteScene extends FarmOwnedModel
{
    public const STORED = 'stored';
    public const TOO_CLOUDY = 'too_cloudy';
    public const NO_DATA = 'no_data';

    protected $table = 'agri_satellite_scenes';

    protected $casts = ['acquired_on' => 'date', 'cloud_pct' => 'float', 'bounds' => 'array', 'has_image' => 'boolean'];
}
