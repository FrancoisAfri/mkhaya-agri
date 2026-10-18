<?php

namespace App\Modules\HR\Recruitment\Actions;

use App\Modules\HR\Recruitment\Enums\ApplicationStatus;
use App\Modules\HR\Recruitment\Models\Application;

/**
 * Candidate-initiated withdrawal. Delegates to MoveApplicationAction
 * so the same transition guard and audit trail applies.
 */
class WithdrawApplicationAction
{
    public function __construct(private readonly MoveApplicationAction $mover) {}

    public function execute(Application $application, int $userId): Application
    {
        return $this->mover->execute(
            application: $application,
            newStatus:   ApplicationStatus::Withdrawn,
            userId:      $userId,
        );
    }
}
