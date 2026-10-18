<?php

namespace App\Modules\Assets\Actions\ExitClearance;

use App\Modules\Assets\Models\AssetExitClearance;
use App\Modules\Assets\Services\AssetExitClearanceService;

class GenerateExitClearanceAction
{
    public function __construct(
        private AssetExitClearanceService $service
    ) {
    }

    public function execute(int $employeeId, \Carbon\Carbon $terminationDate): AssetExitClearance
    {
        return $this->service->generateClearance($employeeId, $terminationDate);
    }
}
