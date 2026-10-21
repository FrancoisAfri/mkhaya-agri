<?php

namespace App\Modules\Agri\Models;

class IrrigationException extends FarmOwnedModel
{
    protected $table = 'irrigation_exceptions';

    public const KINDS = ['leak', 'fault', 'over_applied', 'under_applied', 'no_water', 'other'];

    protected $casts = ['resolved_at' => \App\Modules\Agri\Casts\FarmDateTime::class];
}
