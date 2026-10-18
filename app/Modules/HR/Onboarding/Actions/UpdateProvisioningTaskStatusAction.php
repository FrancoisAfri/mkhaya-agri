<?php

namespace App\Modules\HR\Onboarding\Actions;

use App\Modules\HR\Onboarding\Enums\ProvisioningTaskStatus;
use App\Modules\HR\Onboarding\Models\OnboardingProvisioningTask;

class UpdateProvisioningTaskStatusAction
{
    public function execute(OnboardingProvisioningTask $task, string $status): OnboardingProvisioningTask
    {
        $statusEnum = ProvisioningTaskStatus::from($status);

        $task->status = $statusEnum;

        if ($statusEnum === ProvisioningTaskStatus::Completed && $task->completed_at === null) {
            $task->completed_at = now();
        } elseif ($statusEnum !== ProvisioningTaskStatus::Completed) {
            $task->completed_at = null;
        }

        $task->save();

        return $task;
    }
}
