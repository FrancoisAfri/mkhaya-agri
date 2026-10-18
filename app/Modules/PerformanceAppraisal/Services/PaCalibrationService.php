<?php

namespace App\Modules\PerformanceAppraisal\Services;

use App\Modules\PerformanceAppraisal\Models\PaCalibrationSession;
use App\Modules\PerformanceAppraisal\Models\PaCalibrationParticipant;
use App\Modules\PerformanceAppraisal\Models\PaApprovalRequest;
use App\Modules\PerformanceAppraisal\Models\PaApprovalAction;
use App\Modules\PerformanceAppraisal\Models\PaApprovalWorkflow;

class PaCalibrationService
{
    /** Finalize all participants in a session — lock scores */
    public function finalizeSession(PaCalibrationSession $session): void
    {
        $session->participants()
            ->whereNotNull('calibrated_score')
            ->update(['finalized' => true]);

        $session->update(['status' => 'completed']);
    }

    /** Get calibration analytics summary for a session */
    public function sessionSummary(PaCalibrationSession $session): array
    {
        $participants = $session->participants()->whereNotNull('calibrated_score')->get();

        $adjustments = $participants->map(fn($p) => $p->calibrated_score - ($p->original_score ?? $p->calibrated_score));

        return [
            'participant_count'   => $participants->count(),
            'avg_original'        => round((float) $participants->avg('original_score'), 2),
            'avg_calibrated'      => round((float) $participants->avg('calibrated_score'), 2),
            'avg_adjustment'      => round((float) $adjustments->avg(), 2),
            'raised_count'        => $adjustments->filter(fn($a) => $a > 0)->count(),
            'lowered_count'       => $adjustments->filter(fn($a) => $a < 0)->count(),
            'unchanged_count'     => $adjustments->filter(fn($a) => $a == 0)->count(),
        ];
    }

    // ── Approval workflow engine ──────────────────────────────────────────────

    public function submitRequest(
        int $workflowId,
        string $resourceType,
        int $resourceId,
        string $title,
        int $submittedBy
    ): PaApprovalRequest {
        $workflow   = PaApprovalWorkflow::with('steps')->findOrFail($workflowId);
        $totalSteps = $workflow->steps->count();

        return PaApprovalRequest::create([
            'workflow_id'   => $workflowId,
            'resource_type' => $resourceType,
            'resource_id'   => $resourceId,
            'title'         => $title,
            'submitted_by'  => $submittedBy,
            'current_step'  => 1,
            'total_steps'   => $totalSteps,
            'status'        => $totalSteps > 0 ? 'in_progress' : 'approved',
        ]);
    }

    public function processAction(
        PaApprovalRequest $request,
        int $actionBy,
        string $action, // approved / rejected / recalled
        ?string $notes = null
    ): PaApprovalRequest {
        PaApprovalAction::create([
            'request_id'  => $request->id,
            'step_number' => $request->current_step,
            'action_by'   => $actionBy,
            'action'      => $action,
            'notes'       => $notes,
            'actioned_at' => now(),
        ]);

        if ($action === 'rejected') {
            $request->update(['status' => 'rejected', 'final_notes' => $notes]);
            return $request;
        }

        if ($action === 'recalled') {
            $request->update(['status' => 'recalled', 'final_notes' => $notes]);
            return $request;
        }

        // approved — advance step or mark done
        $nextStep = $request->current_step + 1;

        if ($nextStep > $request->total_steps) {
            $request->update(['status' => 'approved', 'current_step' => $nextStep - 1, 'final_notes' => $notes]);
        } else {
            $request->update(['current_step' => $nextStep]);
        }

        return $request->fresh();
    }
}
