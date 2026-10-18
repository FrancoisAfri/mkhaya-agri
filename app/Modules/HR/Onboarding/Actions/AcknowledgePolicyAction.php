<?php

namespace App\Modules\HR\Onboarding\Actions;

use App\Modules\HR\Onboarding\Models\OnboardingPolicyAcknowledgement;
use App\Modules\HR\Onboarding\Models\OnboardingTask;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * Record a policy acknowledgement and close the task in one step.
 *
 * One step on purpose: a task marked done without an acknowledgement row, or a row written against
 * a task still showing as outstanding, are both worse than either outcome alone — the first claims
 * evidence that does not exist, the second hides evidence that does.
 */
class AcknowledgePolicyAction
{
    public function execute(
        OnboardingTask $task,
        int $policyVersionId,
        int $userId,
        ?string $signature = null,
        ?string $ipAddress = null,
    ): OnboardingPolicyAcknowledgement {
        return DB::transaction(function () use ($task, $policyVersionId, $userId, $signature, $ipAddress) {
            $companyId = app(CurrentCompany::class)->id();
            $personId = $task->plan?->hr_person_id;

            abort_if($personId === null, 422, 'This task is not attached to a person.');

            // firstOrCreate rather than create: re-reading a policy is not a second agreement, and
            // the unique index would otherwise turn an honest double-click into an error page.
            $acknowledgement = OnboardingPolicyAcknowledgement::firstOrCreate(
                ['hr_person_id' => $personId, 'policy_version_id' => $policyVersionId],
                [
                    'onboarding_task_id' => $task->id,
                    'acknowledged_by' => $userId,
                    'acknowledged_at' => now(),
                    'signature' => $signature,
                    'ip_address' => $ipAddress,
                    'company_id' => $companyId,
                ]
            );

            $task->update([
                'status' => 'completed',
                'completed_at' => now(),
                'completed_by' => $userId,
            ]);

            return $acknowledgement;
        });
    }
}
