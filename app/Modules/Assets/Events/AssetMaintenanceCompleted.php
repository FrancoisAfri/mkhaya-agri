<?php

namespace App\Modules\Assets\Events;

use App\Modules\Assets\Models\AssetMaintenance;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AssetMaintenanceCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(public AssetMaintenance $maintenance)
    {
    }
}
