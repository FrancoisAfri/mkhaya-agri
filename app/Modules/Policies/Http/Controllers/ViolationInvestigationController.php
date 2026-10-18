<?php

namespace App\Modules\Policies\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Policies\Services\Investigation\ViolationInvestigationService;
use App\Modules\Policies\Models\ViolationInvestigation;
use App\Modules\Policies\Models\PolicyViolation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * ViolationInvestigationController
 * 
 * API endpoints for violation investigation management
 */
class ViolationInvestigationController extends Controller
{
    private ViolationInvestigationService $service;

    public function __construct(ViolationInvestigationService $service)
    {
        $this->service = $service;
    }

    /**
     * Create investigation for violation
     * 
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function create(Request $request)
    {
        if (!Gate::allows('create-investigation')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'violation_id' => 'required|integer|exists:policy_violations,id',
            'assigned_to' => 'nullable|integer|exists:users,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        $investigation = $this->service->createInvestigation(
            violationId: $validated['violation_id'],
            assignedToId: $validated['assigned_to'] ?? null,
            initialNotes: $validated['notes'] ?? null
        );

        return response()->json([
            'data' => $investigation,
            'message' => 'Investigation created',
        ], 201);
    }

    /**
     * Assign investigator
     * 
     * @param int $investigationId
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function assign($investigationId, Request $request)
    {
        if (!Gate::allows('assign-investigator')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'notes' => 'nullable|string|max:500',
        ]);

        $investigation = $this->service->assignInvestigator(
            investigationId: $investigationId,
            userId: $validated['user_id'],
            notes: $validated['notes'] ?? null
        );

        return response()->json([
            'data' => $investigation,
            'message' => 'Investigator assigned',
        ]);
    }

    /**
     * Update investigation status
     * 
     * @param int $investigationId
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function updateStatus($investigationId, Request $request)
    {
        $validated = $request->validate([
            'status' => 'required|in:open,in_progress,reviewing,resolved,closed',
        ]);

        $investigation = $this->service->updateStatus(
            investigationId: $investigationId,
            status: $validated['status'],
            updatedBy: auth()->id()
        );

        return response()->json([
            'data' => $investigation,
            'message' => 'Status updated',
        ]);
    }

    /**
     * Add investigation note
     * 
     * @param int $investigationId
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function addNote($investigationId, Request $request)
    {
        $validated = $request->validate([
            'content' => 'required|string|min:5|max:2000',
            'visibility' => 'nullable|in:internal,user,manager',
        ]);

        $note = $this->service->addNote(
            investigationId: $investigationId,
            userId: auth()->id(),
            content: $validated['content'],
            visibility: $validated['visibility'] ?? 'internal'
        );

        return response()->json([
            'data' => $note,
            'message' => 'Note added',
        ], 201);
    }

    /**
     * Add evidence
     * 
     * @param int $investigationId
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function addEvidence($investigationId, Request $request)
    {
        $validated = $request->validate([
            'file' => 'required|file|max:10240',
            'description' => 'required|string|max:500',
            'evidence_type' => 'nullable|string|in:document,audio,video,screenshot,other',
        ]);

        $evidence = $this->service->addEvidence(
            investigationId: $investigationId,
            uploadedBy: auth()->id(),
            file: $validated['file'],
            description: $validated['description'],
            evidenceType: $validated['evidence_type'] ?? null
        );

        return response()->json([
            'data' => $evidence,
            'message' => 'Evidence uploaded',
        ], 201);
    }

    /**
     * Resolve investigation
     * 
     * @param int $investigationId
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function resolve($investigationId, Request $request)
    {
        if (!Gate::allows('resolve-investigation')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'resolution_type' => 'required|in:resolved_satisfactorily,requires_training,requires_action,requires_escalation',
            'resolution_notes' => 'nullable|string|max:2000',
            'findings' => 'nullable|array',
        ]);

        $investigation = $this->service->resolveInvestigation(
            investigationId: $investigationId,
            resolution: $validated['resolution_type'],
            resolutionNotes: $validated['resolution_notes'] ?? null,
            resolvedBy: auth()->id(),
            findings: $validated['findings'] ?? null
        );

        return response()->json([
            'data' => $investigation,
            'message' => 'Investigation resolved',
        ]);
    }

    /**
     * Close investigation
     * 
     * @param int $investigationId
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function close($investigationId, Request $request)
    {
        if (!Gate::allows('close-investigation')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $investigation = $this->service->closeInvestigation(
            investigationId: $investigationId,
            closedBy: auth()->id(),
            reason: $validated['reason'] ?? null
        );

        return response()->json([
            'data' => $investigation,
            'message' => 'Investigation closed',
        ]);
    }

    /**
     * Get investigation details
     * 
     * @param int $investigationId
     * @return \Illuminate\Http\Response
     */
    public function show($investigationId)
    {
        $investigation = $this->service->getDetails($investigationId);

        return response()->json([
            'data' => $investigation,
        ]);
    }

    /**
     * Get investigation notes
     * 
     * @param int $investigationId
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function getNotes($investigationId, Request $request)
    {
        $visibility = $request->query('visibility');

        $notes = $this->service->getNotes($investigationId, $visibility);

        return response()->json([
            'data' => $notes,
            'count' => $notes->count(),
        ]);
    }

    /**
     * Get investigation evidence
     * 
     * @param int $investigationId
     * @return \Illuminate\Http\Response
     */
    public function getEvidence($investigationId)
    {
        $evidence = $this->service->getEvidence($investigationId);

        return response()->json([
            'data' => $evidence,
            'count' => $evidence->count(),
        ]);
    }

    /**
     * Get pending investigations (for investigator)
     * 
     * @return \Illuminate\Http\Response
     */
    public function getPending()
    {
        $investigations = $this->service->getPendingInvestigations(auth()->id());

        return response()->json([
            'data' => $investigations,
            'count' => $investigations->count(),
        ]);
    }

    /**
     * Get overdue investigations
     * 
     * @return \Illuminate\Http\Response
     */
    public function getOverdue()
    {
        if (!Gate::allows('view-overdue-investigations')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $investigations = $this->service->getOverdueInvestigations();

        return response()->json([
            'data' => $investigations,
            'count' => $investigations->count(),
        ]);
    }

    /**
     * Get investigation statistics
     * 
     * @return \Illuminate\Http\Response
     */
    public function getStatistics()
    {
        if (!Gate::allows('view-investigation-statistics')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $stats = $this->service->getStatistics();

        return response()->json([
            'data' => $stats,
        ]);
    }
}
