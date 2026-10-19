<?php

namespace App\Modules\Agri\Models;

class CropSeason extends AgriModel
{
    public const OPEN = 'open';
    public const CLOSED = 'closed';

    protected $table = 'crop_seasons';

    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date'];
}
