<?php

namespace App\Modules\Finance\Shared\Services;

use App\Modules\Finance\Shared\Models\FinanceApprovalRequest;
use App\Modules\Finance\Shared\Models\FinanceApprovalStep;
use Illuminate\Database\Eloquent\Model;

class ApprovalWorkflowService
{
    private const LEVEL_ORDER = ['line_manager', 'hr', 'finance', 'executive'];

    public function submitForApproval(
        string $module,
        Model  $entity,
        int    $requestedBy,
        string $startLevel = 'line_manager',
        string $priority   = 'normal',
        array  $metadata   = []
    ): FinanceApprovalRequest {
        return FinanceApprovalRequest::firstOrCreate(
            ['module' => $module, 'entity_type' => get_class($entity), 'entity_id' => $entity->id, 'status' => 'pending'],
            [
                'current_level' => $startLevel,
                'requested_by'  => $requestedBy,
                'priority'      => $priority,
                'metadata'      => $metadata,
            ]
        );
    }

    public function approveFinanceRequest(
        FinanceApprovalRequest $request,
        int    $approverId,
        string $comments = null
    ): FinanceApprovalRequest {
        $this->recordStep($request, $approverId, 'approved', $comments);

        $nextLevel = $this->nextLevel($request->current_level, $request->metadata['requires_executive'] ?? false);

        if ($nextLevel) {
            $request->update(['current_level' => $nextLevel]);
        } else {
            $request->update(['status' => 'approved']);
        }

        return $request->fresh();
    }

    public function rejectFinanceRequest(
        FinanceApprovalRequest $request,
        int    $approverId,
        string $comments
    ): FinanceApprovalRequest {
        $this->recordStep($request, $approverId, 'rejected', $comments);
        $request->update(['status' => 'rejected']);
        return $request->fresh();
    }

    public function requestMoreInformation(
        FinanceApprovalRequest $request,
        int    $approverId,
        string $comments
    ): FinanceApprovalRequest {
        $this->recordStep($request, $approverId, 'info_requested', $comments);
        $request->update(['status' => 'info_requested']);
        return $request->fresh();
    }

    public function escalateHighRiskRequest(
        FinanceApprovalRequest $request,
        int    $approverId,
        string $comments = null
    ): FinanceApprovalRequest {
        $this->recordStep($request, $approverId, 'escalated', $comments);
        $request->update(['current_level' => 'executive', 'status' => 'escalated']);
        return $request->fresh();
    }

    public function getApprovalStatus(string $module, string $entityType, int $entityId): ?FinanceApprovalRequest
    {
        return FinanceApprovalRequest::where('module', $module)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->with('steps.approver')
            ->latest()
            ->first();
    }

    private function recordStep(FinanceApprovalRequest $request, int $approverId, string $action, ?string $comments): void
    {
        FinanceApprovalStep::create([
            'approval_request_id' => $request->id,
            'approver_id'         => $approverId,
            'level'               => $request->current_level,
            'action'              => $action,
            'comments'            => $comments,
            'acted_at'            => now(),
        ]);
    }

    private function nextLevel(string $currentLevel, bool $requiresExecutive): ?string
    {
        $order = $requiresExecutive
            ? self::LEVEL_ORDER
            : array_filter(self::LEVEL_ORDER, fn($l) => $l !== 'executive');

        $order  = array_values($order);
        $index  = array_search($currentLevel, $order);

        return isset($order[$index + 1]) ? $order[$index + 1] : null;
    }
}
