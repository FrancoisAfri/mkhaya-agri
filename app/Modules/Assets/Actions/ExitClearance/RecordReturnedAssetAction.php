<?php

namespace App\Modules\Assets\Actions\ExitClearance;

use App\Modules\Assets\Models\AssetExitClearanceItem;
use App\Modules\Assets\Services\AssetExitClearanceService;

class RecordReturnedAssetAction
{
    public function __construct(
        private AssetExitClearanceService $service
    ) {
    }

    public function execute(AssetExitClearanceItem $item, array $data): AssetExitClearanceItem
    {
        return $this->service->recordReturn($item, $data);
    }
}
