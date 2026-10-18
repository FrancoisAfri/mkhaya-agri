<?php

namespace App\Modules\HR\Onboarding\Actions;

use App\Modules\HR\Onboarding\Enums\OnboardingStatus;
use App\Modules\HR\Onboarding\Enums\OnboardingTaskStatus;
use App\Modules\HR\Onboarding\Models\OnboardingTask;
use App\Modules\HR\PreBoarding\Enums\HrPersonStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompleteOnboardingTaskAction
{
    public function execute(OnboardingTask $task, int $userId, bool $skip = false): OnboardingTask
    {
        if ($task->status->isTerminal()) {
            throw ValidationException::withMessages([
                'task' => 'This task has already been completed or skipped.',
            ]);
        }

        return DB::transaction(function () use ($task, $userId, $skip): OnboardingTask {
            $newStatus = $skip ? OnboardingTaskStatus::Skipped : OnboardingTaskStatus::Completed;

            $task->update([
                'status'       => $newStatus->value,
                'completed_at' => now(),
                'completed_by' => $userId,
            ]);

            $plan = $task->plan;

            if ($plan->isFullyComplete()) {
                $plan->update([
                    'status'       => OnboardingStatus::Completed->value,
                    'completed_at' => now(),
                ]);

                $plan->hrPerson->update(['status' => HrPersonStatus::Active->value]);
            }

            return $task->fresh();
        });
    }
}
