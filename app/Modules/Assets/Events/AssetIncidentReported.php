<?php

namespace App\Modules\Assets\Events;

use App\Modules\Assets\Models\AssetIncident;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AssetIncidentReported
{
    use Dispatchable, SerializesModels;

    public function __construct(public AssetIncident $incident)
    {
    }
}
