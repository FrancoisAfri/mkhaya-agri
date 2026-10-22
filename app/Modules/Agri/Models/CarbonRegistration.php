<?php

namespace App\Modules\Agri\Models;

/** A carbon project's listing with a registry (Verra VCS, Gold Standard…), tracked by hand — no registry has a submission API. */
class CarbonRegistration extends FarmOwnedModel
{
    protected $table = 'agri_carbon_registrations';

    protected $casts = ['status_on' => 'date', 'crediting_starts_on' => 'date', 'crediting_ends_on' => 'date', 'buffer_pct' => 'float'];
}
