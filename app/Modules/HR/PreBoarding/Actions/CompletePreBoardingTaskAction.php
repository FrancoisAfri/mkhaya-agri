<?php

namespace App\Modules\HR\PreBoarding\Actions;

use App\Modules\HR\PreBoarding\Enums\PreBoardingStatus;
use App\Modules\HR\PreBoarding\Enums\PreBoardingTaskStatus;
use App\Modules\HR\PreBoarding\Models\PreBoardingTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompletePreBoardingTaskAction
{
    public function execute(PreBoardingTask $task, int $userId, bool $skip = false): PreBoardingTask
    {
        if ($task->status->isTerminal()) {
            throw ValidationException::withMessages([
                'task' => 'This task has already been completed or skipped.',
            ]);
        }

        return DB::transaction(function () use ($task, $userId, $skip): PreBoardingTask {
            $newStatus = $skip ? PreBoardingTaskStatus::Skipped : PreBoardingTaskStatus::Completed;

            $task->update([
                'status'       => $newStatus->value,
                'completed_at' => now(),
                'completed_by' => $userId,
            ]);

            $plan = $task->plan;

            if ($plan->isFullyComplete()) {
                $plan->update([
                    'status'       => PreBoardingStatus::Completed->value,
                    'completed_at' => now(),
                ]);
            } elseif ($plan->status === PreBoardingStatus::Pending) {
                $plan->update(['status' => PreBoardingStatus::InProgress->value]);
            }

            return $task->fresh();
        });
    }
}
