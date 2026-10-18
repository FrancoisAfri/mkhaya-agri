<?php

namespace App\Modules\HR\Recruitment\Actions;

use App\Modules\HR\Recruitment\Enums\ApplicationStatus;
use App\Modules\HR\Recruitment\Models\Application;

/**
 * Thin wrapper around MoveApplicationAction for the specific rejection flow.
 * Reason is mandatory for rejections.
 */
class RejectApplicationAction
{
    public function __construct(private readonly MoveApplicationAction $mover) {}

    public function execute(Application $application, int $userId, string $reason): Application
    {
        return $this->mover->execute(
            application:     $application,
            newStatus:       ApplicationStatus::Rejected,
            userId:          $userId,
            reason:          $reason,
        );
    }
}
