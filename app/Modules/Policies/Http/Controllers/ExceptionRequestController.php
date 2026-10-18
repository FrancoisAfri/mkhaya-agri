<?php

namespace App\Modules\Policies\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Policies\Services\Exception\ExceptionRequestService;
use App\Modules\Policies\Models\PolicyException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * ExceptionRequestController
 * 
 * API endpoints for policy exception management
 */
class ExceptionRequestController extends Controller
{
    private ExceptionRequestService $service;

    public function __construct(ExceptionRequestService $service)
    {
        $this->service = $service;
    }

    /**
     * Get pending exceptions for user
     * 
     * @return \Illuminate\Http\Response
     */
    public function getUserPending()
    {
        $userId = auth()->id();

        $exceptions = $this->service->getUserPendingExceptions($userId);

        return response()->json([
            'data' => $exceptions,
            'count' => $exceptions->count(),
        ]);
    }

    /**
     * Get active exceptions for user
     * 
     * @return \Illuminate\Http\Response
     */
    public function getUserActive()
    {
        $userId = auth()->id();

        $exceptions = $this->service->getUserActiveExceptions($userId);

        return response()->json([
            'data' => $exceptions,
            'count' => $exceptions->count(),
        ]);
    }

    /**
     * Create exception request
     * 
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function create(Request $request)
    {
        $validated = $request->validate([
            'policy_id' => 'required|integer|exists:policies,id',
            'rule_id' => 'required|integer|exists:policy_rules,id',
            'reason' => 'required|string|min:10|max:1000',
            'start_date' => 'required|date|after:today',
            'end_date' => 'nullable|date|after:start_date',
            'evidence' => 'nullable|array',
        ]);

        $exception = $this->service->createRequest(
            userId: auth()->id(),
            policyId: $validated['policy_id'],
            ruleId: $validated['rule_id'],
            reason: $validated['reason'],
            startDate: $validated['start_date'],
            endDate: $validated['end_date'] ?? null,
            evidence: $validated['evidence'] ?? null
        );

        return response()->json([
            'data' => $exception,
            'message' => 'Exception request created successfully',
        ], 201);
    }

    /**
     * Submit exception for approval
     * 
     * @param int $exceptionId
     * @return \Illuminate\Http\Response
     */
    public function submit($exceptionId)
    {
        $exception = PolicyException::findOrFail($exceptionId);

        // Verify user owns this exception
        if ($exception->user_id !== auth()->id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $exception = $this->service->submitForApproval($exceptionId);

        return response()->json([
            'data' => $exception,
            'message' => 'Exception submitted for approval',
        ]);
    }

    /**
     * Approve exception (manager only)
     * 
     * @param int $exceptionId
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function approve($exceptionId, Request $request)
    {
        if (!Gate::allows('approve-exception')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);

        $exception = $this->service->approve(
            exceptionId: $exceptionId,
            approverId: auth()->id(),
            notes: $validated['notes'] ?? null
        );

        return response()->json([
            'data' => $exception,
            'message' => 'Exception approved',
        ]);
    }

    /**
     * Reject exception
     * 
     * @param int $exceptionId
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function reject($exceptionId, Request $request)
    {
        if (!Gate::allows('reject-exception')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $exception = $this->service->reject(
            exceptionId: $exceptionId,
            rejectorId: auth()->id(),
            reason: $validated['reason']
        );

        return response()->json([
            'data' => $exception,
            'message' => 'Exception rejected',
        ]);
    }

    /**
     * Escalate exception to policy owner
     * 
     * @param int $exceptionId
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function escalate($exceptionId, Request $request)
    {
        if (!Gate::allows('escalate-exception')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $exception = $this->service->escalate(
            exceptionId: $exceptionId,
            reason: $validated['reason'] ?? null
        );

        return response()->json([
            'data' => $exception,
            'message' => 'Exception escalated to policy owner',
        ]);
    }

    /**
     * Check if valid exception exists
     * 
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function checkValid(Request $request)
    {
        $validated = $request->validate([
            'policy_id' => 'required|integer',
            'rule_id' => 'required|integer',
        ]);

        $hasValidException = $this->service->hasValidException(
            userId: auth()->id(),
            policyId: $validated['policy_id'],
            ruleId: $validated['rule_id']
        );

        return response()->json([
            'has_valid_exception' => $hasValidException,
        ]);
    }

    /**
     * Get exception details
     * 
     * @param int $exceptionId
     * @return \Illuminate\Http\Response
     */
    public function show($exceptionId)
    {
        $exception = PolicyException::with('policy', 'rule', 'approver', 'user')
            ->findOrFail($exceptionId);

        return response()->json([
            'data' => $exception,
        ]);
    }
}
