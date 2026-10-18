<?php

namespace App\Modules\HR\Recruitment\Actions;

use App\Modules\HR\Recruitment\Enums\ApplicationStatus;
use App\Modules\HR\Recruitment\Models\Application;
use App\Modules\HR\Recruitment\Models\PipelineStage;
use App\Modules\Policies\Traits\PolicyValidationTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MoveApplicationAction
{
    use PolicyValidationTrait;

    /**
     * Move an application to a new status, enforcing allowed pipeline transitions.
     * This is the only path that may write to application.status.
     */
    public function execute(
        Application     $application,
        ApplicationStatus $newStatus,
        int             $userId,
        ?int            $pipelineStageId = null,
        ?string         $reason = null,
    ): Application {
        $currentStatus = $application->status;

        if (!$currentStatus->canTransitionTo($newStatus)) {
            throw ValidationException::withMessages([
                'status' => "Cannot move application from {$currentStatus->label()} to {$newStatus->label()}.",
            ]);
        }

        if ($pipelineStageId !== null) {
            $stage = PipelineStage::where('id', $pipelineStageId)
                ->where('job_id', $application->job_id)
                ->first();

            if (!$stage) {
                throw ValidationException::withMessages([
                    'pipeline_stage_id' => 'Pipeline stage does not belong to this job.',
                ]);
            }
        }

        $this->validatePolicyAction(
            module: 'Recruitment',
            action: 'application.moved',
            payload: [
                'application_id'  => $application->id,
                'job_id'          => $application->job_id,
                'from_status'     => $currentStatus->value,
                'to_status'       => $newStatus->value,
                'user_id'         => $userId,
            ],
            userId: $userId,
        );

        return DB::transaction(function () use ($application, $newStatus, $userId, $pipelineStageId, $reason, $currentStatus) {
            $updates = [
                'status'         => $newStatus->value,
                'stage_moved_at' => now(),
            ];

            if ($pipelineStageId !== null) {
                $updates['pipeline_stage_id'] = $pipelineStageId;
            }

            if ($newStatus === ApplicationStatus::Rejected) {
                $updates['rejected_at']     = now();
                $updates['rejected_reason'] = $reason;
            }

            if ($newStatus === ApplicationStatus::Withdrawn) {
                $updates['withdrawn_at'] = now();
            }

            // status and the *_at columns are intentionally guarded on the model
            // (not fillable) so they can never be mass-assigned from HTTP input.
            // This trusted action is the sole authorised writer, so it force-fills.
            $application->forceFill($updates)->save();

            $this->dispatchPolicyEvent(
                module:      'Recruitment',
                action:      'application.moved',
                subjectId:   $application->id,
                subjectType: Application::class,
                payload:     [
                    'from_status' => $currentStatus->value,
                    'to_status'   => $newStatus->value,
                    'reason'      => $reason,
                    'moved_by'    => $userId,
                ],
                userId: $userId,
            );

            return $application->fresh();
        });
    }
}
