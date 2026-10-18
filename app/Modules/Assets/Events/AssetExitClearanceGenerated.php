<?php

namespace App\Modules\Assets\Events;

use App\Modules\Assets\Models\AssetExitClearance;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AssetExitClearanceGenerated
{
    use Dispatchable, SerializesModels;

    public function __construct(public AssetExitClearance $clearance)
    {
    }
}
