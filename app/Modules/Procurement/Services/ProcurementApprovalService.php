<?php

namespace App\Modules\Procurement\Services;

use App\Modules\Budget\Services\BudgetService;
use App\Modules\Procurement\Models\ProcurementApproval;
use App\Modules\Procurement\Models\ProcurementApprovalComment;
use App\Modules\Procurement\Models\ProcurementEscalation;
use App\Modules\Procurement\Models\PurchaseRequest;
use Illuminate\Support\Facades\DB;

/**
 * Procurement approval workflow. On final approval the request's budget is
 * RESERVED (BudgetService::reserveBudget) — funds are held, not yet committed.
 * Enforces "employees cannot approve their own requests".
 */
class ProcurementApprovalService
{
    public function __construct(private readonly BudgetService $budgets) {}

    public function start(PurchaseRequest $request, array $chain, ?int $userId = null): ProcurementApproval
    {
        $approval = ProcurementApproval::create([
            'source_type' => PurchaseRequest::class,
            'source_id'   => $request->id,
            'status'      => 'pending',
            'current_step' => 1,
            'total_steps' => count($chain),
            'started_by'  => $userId,
        ]);

        foreach ($chain as $step) {
            $approval->steps()->create([
                'step'   => $step['step'],
                'name'   => $step['name'],
                'role'   => $step['role'] ?? null,
                'status' => 'pending',
            ]);
        }

        return $approval;
    }

    public function approveStep(ProcurementApproval $approval, int $userId, ?string $comment = null): array
    {
        $request = PurchaseRequest::find($approval->source_id);

        // Employees cannot approve their own requests.
        if ($request && (int) $request->requester_id === $userId) {
            return ['ok' => false, 'message' => 'You cannot approve your own request.'];
        }
        if ($approval->status !== 'pending') {
            return ['ok' => false, 'message' => 'Approval already completed.'];
        }

        // Segregation of duties: the acting user must hold the CURRENT step's role.
        // Previously any user with the generic approve permission could clear every
        // step and single-handedly complete a multi-level chain.
        $currentStep = $approval->steps()->where('step', $approval->current_step)->first();
        if ($currentStep && $currentStep->role) {
            $actor = \App\Core\User\Models\User::find($userId);
            if (! $actor || ! $actor->hasRole($currentStep->role)) {
                return ['ok' => false, 'message' => "This step requires the '{$currentStep->role}' role to approve."];
            }
        }

        return DB::transaction(function () use ($approval, $userId, $comment, $request) {
            $step = $approval->steps()->where('step', $approval->current_step)->first();
            if ($step) {
                $step->update(['status' => 'approved', 'approver_id' => $userId, 'comment' => $comment, 'acted_at' => now()]);
            }
            if ($comment) {
                ProcurementApprovalComment::create(['procurement_approval_id' => $approval->id, 'step_id' => $step?->id, 'user_id' => $userId, 'comment' => $comment]);
            }

            if ($approval->current_step >= $approval->total_steps) {
                $approval->update(['status' => 'approved', 'completed_at' => now()]);
                $this->finalize($request, $userId);

                return ['ok' => true, 'message' => 'Request fully approved — budget reserved.', 'final' => true];
            }

            $approval->increment('current_step');

            return ['ok' => true, 'message' => 'Step approved — routed to the next approver.', 'final' => false];
        });
    }

    public function rejectStep(ProcurementApproval $approval, int $userId, ?string $comment = null): array
    {
        $request = PurchaseRequest::find($approval->source_id);
        if ($request && (int) $request->requester_id === $userId) {
            return ['ok' => false, 'message' => 'You cannot action your own request.'];
        }

        $step = $approval->steps()->where('step', $approval->current_step)->first();
        $step?->update(['status' => 'rejected', 'approver_id' => $userId, 'comment' => $comment, 'acted_at' => now()]);
        $approval->update(['status' => 'rejected', 'completed_at' => now()]);

        if ($request) {
            $request->update(['status' => 'rejected']);
            $this->history($request, 'rejected', $request->status, 'rejected', $userId, $comment);
        }

        return ['ok' => true, 'message' => 'Request rejected.'];
    }

    public function escalate(ProcurementApproval $approval, ?string $reason = null, ?int $escalateTo = null): ProcurementEscalation
    {
        $step = $approval->steps()->where('step', $approval->current_step)->first();

        return ProcurementEscalation::create([
            'procurement_approval_id' => $approval->id,
            'step_id' => $step?->id,
            'reason'  => $reason,
            'escalated_to' => $escalateTo,
            'status'  => 'open',
        ]);
    }

    /**
     * The workflow engine's decision on a requisition it routed (Workflow & Automation). Idempotent.
     * Approval reserves the budget exactly as the last step of the procurement chain does.
     */
    public function finaliseByWorkflow(PurchaseRequest $request, string $outcome, ?int $actorId, ?string $comment): void
    {
        $request = PurchaseRequest::query()->lockForUpdate()->findOrFail($request->id);
        $target = ['approved' => 'approved', 'rejected' => 'rejected', 'cancelled' => 'cancelled'][$outcome] ?? null;
        if (! $target) {
            throw new \InvalidArgumentException('Unknown workflow outcome.');
        }
        if ($request->status === $target || ($target === 'approved' && $request->status === 'converted')) {
            return;
        }
        if ($request->status !== 'pending_approval') {
            throw new \RuntimeException("Purchase request {$request->request_number} is {$request->status}; the workflow outcome cannot be applied.");
        }
        if ($target === 'approved') {
            $this->finalize($request, (int) ($actorId ?? $request->requester_id));

            return;
        }
        $request->update(['status' => $target]);
        $this->history($request, $target, 'pending_approval', $target, $actorId, trim(ucfirst($target) . ' through the workflow. ' . ($comment ?? '')));
    }

    /** On full approval: reserve the budget and mark the request approved. */
    private function finalize(?PurchaseRequest $request, int $userId): void
    {
        if (! $request) {
            return;
        }

        $reservation = null;
        $availabilityNote = '';
        if ($request->budget) {
            // Re-validate availability at APPROVAL time (not just at submit) — other
            // reservations may have exhausted the budget in between, so this catches a
            // silent over-reservation and flags it for override review.
            $check = $this->budgets->validateBudgetAvailability($request->budget, (float) $request->estimated_total);
            if (! ($check['ok'] ?? true)) {
                $availabilityNote = sprintf(' ⚠ Budget short by %.2f at approval — flagged for override review.', (float) ($check['shortfall'] ?? 0));
            }

            $reservation = $this->budgets->reserveBudget(
                $request->budget, (float) $request->estimated_total,
                'purchase_request', $request->id, $userId
            );
        }

        $from = $request->status;
        $request->update([
            'status' => 'approved',
            'budget_reservation_id' => $reservation?->id,
            'reserved_amount' => $reservation ? (float) $request->estimated_total : 0,
        ]);

        $this->history($request, 'approved', $from, 'approved', $userId,
            ($reservation ? 'Budget reserved on approval.' : 'Approved (no budget linked).') . $availabilityNote);
    }

    private function history(PurchaseRequest $request, string $action, ?string $from, ?string $to, ?int $userId, ?string $note): void
    {
        $request->history()->create([
            'action' => $action, 'from_status' => $from, 'to_status' => $to,
            'performed_by' => $userId, 'note' => $note,
        ]);
    }
}
