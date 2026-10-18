<?php

namespace App\Modules\Assets\Listeners;

use App\Modules\Assets\Events\AssetExitClearanceGenerated;
use App\Modules\Assets\Services\AssetExitClearanceService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * M5 — Employee Termination Handler
 * 
 * Listens to HR module's EmployeeTerminated event.
 * Auto-generates exit clearance checklist.
 */
class HandleEmployeeTermination implements ShouldQueue
{
    public function __construct(
        private AssetExitClearanceService $exitClearanceService
    ) {
    }

    public function handle($event): void
    {
        // Assuming HR module fires: App\Modules\HR\Events\EmployeeTerminated
        // with properties: $event->employee_id, $event->termination_date

        $clearance = $this->exitClearanceService->generateClearance(
            $event->employee_id,
            $event->termination_date
        );

        event(new AssetExitClearanceGenerated($clearance));
    }
}
