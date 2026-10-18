<?php

namespace App\Modules\HR\Recruitment\Listeners;

use App\Core\User\Models\User;
use App\Modules\HR\Recruitment\Events\ApprovalRequested;
use App\Modules\HR\Recruitment\Mail\ApprovalRequestedMail;
use App\Modules\HR\Recruitment\Models\ApprovalDecision;
use App\Modules\HR\Recruitment\Models\ApprovalStage;
use App\Modules\HR\Recruitment\Models\ApprovalWorkflow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotifyApproverOnPendingDecision implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = 'notifications';

    public bool $afterCommit = true;

    public function handle(ApprovalRequested $event): void
    {
        $workflow = ApprovalWorkflow::where('entity_type', $event->entityType)
            ->where('is_active', true)
            ->with('stages')
            ->first();

        if (!$workflow) {
            Log::info("ApprovalRequested: no active workflow for entity_type={$event->entityType}");
            return;
        }

        $stage = $this->nextPendingStage($workflow, $event->entityType, $event->entity->getKey());

        if (!$stage) {
            return;
        }

        $recipients = $this->resolveRecipients($stage);

        if ($recipients->isEmpty()) {
            Log::warning("ApprovalRequested: no recipients for stage {$stage->id} (role={$stage->approver_role})");
            return;
        }

        $label = $event->entity->title
            ?? $event->entity->name
            ?? "{$event->entityType} #{$event->entity->getKey()}";

        foreach ($recipients as $email) {
            Mail::to($email)->send(new ApprovalRequestedMail(
                entityType: $event->entityType,
                entityId: $event->entity->getKey(),
                entityLabel: (string) $label,
                stageName: $stage->name,
            ));
        }
    }

    private function nextPendingStage(ApprovalWorkflow $workflow, string $entityType, int|string $entityId): ?ApprovalStage
    {
        $decidedStageIds = ApprovalDecision::where('workflow_id', $workflow->id)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('decision', 'capproved')
            ->pluck('stage_id');

        return $workflow->stages()
            ->whereNotIn('id', $decidedStageIds)
            ->orderBy('stage_number')
            ->first();
    }

    private function resolveRecipients(ApprovalStage $stage): \Illuminate\Support\Collection
    {
        if ($stage->approver_user_id) {
            return User::whereKey($stage->approver_user_id)
                ->whereNotNull('email')
                ->pluck('email');
        }

        if ($stage->approver_role) {
            return User::role($stage->approver_role)
                ->whereNotNull('email')
                ->pluck('email');
        }

        return collect();
    }
}
