<?php

namespace App\Modules\Policies\Services\Investigation;

use App\Modules\Policies\Models\PolicyViolation;
use App\Modules\Policies\Models\ViolationInvestigation;
use App\Modules\Policies\Models\InvestigationNote;
use App\Modules\Policies\Models\InvestigationEvidence;
use App\Core\User\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * ViolationInvestigationService
 * 
 * Manage investigation lifecycle:
 * - Create investigations for violations
 * - Assign investigators
 * - Add investigation notes (findings)
 * - Attach evidence files
 * - Record resolution
 * - Track investigation status
 */
class ViolationInvestigationService
{
    /**
     * Create investigation for violation
     * 
     * @param int $violationId
     * @param int|null $assignedToId
     * @param string|null $initialNotes
     * @return ViolationInvestigation
     */
    public function createInvestigation(
        int $violationId,
        ?int $assignedToId = null,
        ?string $initialNotes = null
    ): ViolationInvestigation {
        $violation = PolicyViolation::findOrFail($violationId);

        $investigation = ViolationInvestigation::create([
            'policy_violation_id' => $violationId,
            'assigned_to' => $assignedToId,
            'status' => 'open',
            'severity_level' => $violation->severity ?? 'medium',
            'investigation_started_at' => now(),
        ]);

        if ($initialNotes) {
            $this->addNote($investigation->id, $assignedToId ?? 0, $initialNotes, 'internal');
        }

        return $investigation;
    }

    /**
     * Assign investigator to investigation
     * 
     * @param int $investigationId
     * @param int $userId
     * @param string|null $notes
     * @return ViolationInvestigation
     */
    public function assignInvestigator(
        int $investigationId,
        int $userId,
        ?string $notes = null
    ): ViolationInvestigation {
        $investigation = ViolationInvestigation::findOrFail($investigationId);

        // Verify user exists
        User::findOrFail($userId);

        $investigation->update([
            'assigned_to' => $userId,
            'assigned_at' => now(),
        ]);

        if ($notes) {
            $this->addNote(
                $investigationId,
                $userId,
                "Assigned investigator: {$notes}",
                'internal'
            );
        }

        // Notify investigator
        $this->notifyInvestigatorAssigned($investigation);

        return $investigation;
    }

    /**
     * Update investigation status
     * 
     * @param int $investigationId
     * @param string $status
     * @param int|null $updatedBy
     * @return ViolationInvestigation
     */
    public function updateStatus(
        int $investigationId,
        string $status,
        ?int $updatedBy = null
    ): ViolationInvestigation {
        $investigation = ViolationInvestigation::findOrFail($investigationId);

        $oldStatus = $investigation->status;
        $investigation->update(['status' => $status]);

        if ($updatedBy) {
            $this->addNote(
                $investigationId,
                $updatedBy,
                "Status changed from {$oldStatus} to {$status}",
                'internal'
            );
        }

        return $investigation;
    }

    /**
     * Add investigation note
     * 
     * @param int $investigationId
     * @param int $userId
     * @param string $content
     * @param string $visibility
     * @return InvestigationNote
     */
    public function addNote(
        int $investigationId,
        int $userId,
        string $content,
        string $visibility = 'internal'
    ): InvestigationNote {
        $investigation = ViolationInvestigation::findOrFail($investigationId);

        $note = InvestigationNote::create([
            'policy_violation_investigation_id' => $investigationId,
            'added_by' => $userId,
            'content' => $content,
            'visibility' => $visibility,
            'created_at' => now(),
        ]);

        // Update investigation status if still open
        if ($investigation->status === 'open') {
            $investigation->update(['status' => 'in_progress']);
        }

        return $note;
    }

    /**
     * Add investigation evidence (file)
     * 
     * @param int $investigationId
     * @param int $uploadedBy
     * @param mixed $file
     * @param string $description
     * @param string|null $evidenceType
     * @return InvestigationEvidence
     */
    public function addEvidence(
        int $investigationId,
        int $uploadedBy,
        $file,
        string $description,
        ?string $evidenceType = null
    ): InvestigationEvidence {
        $investigation = ViolationInvestigation::findOrFail($investigationId);

        // Store file
        $path = "investigations/{$investigationId}";
        $fileName = time() . '_' . $file->getClientOriginalName();
        $storedPath = $file->storeAs($path, $fileName, 'private');

        $evidence = InvestigationEvidence::create([
            'policy_violation_investigation_id' => $investigationId,
            'file_path' => $storedPath,
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'uploaded_by' => $uploadedBy,
            'description' => $description,
            'evidence_type' => $evidenceType ?? 'document',
        ]);

        // Log in investigation
        $this->addNote(
            $investigationId,
            $uploadedBy,
            "Evidence added: {$file->getClientOriginalName()}",
            'internal'
        );

        return $evidence;
    }

    /**
     * Resolve investigation
     * 
     * @param int $investigationId
     * @param string $resolution
     * @param string|null $resolutionNotes
     * @param int|null $resolvedBy
     * @param array|null $findings
     * @return ViolationInvestigation
     */
    public function resolveInvestigation(
        int $investigationId,
        string $resolution,
        ?string $resolutionNotes = null,
        ?int $resolvedBy = null,
        ?array $findings = null
    ): ViolationInvestigation {
        $investigation = ViolationInvestigation::findOrFail($investigationId);

        $investigation->update([
            'status' => 'resolved',
            'resolution_type' => $resolution,
            'resolution_notes' => $resolutionNotes,
            'findings' => $findings,
            'investigation_ended_at' => now(),
            'resolved_by' => $resolvedBy,
        ]);

        if ($resolvedBy) {
            $this->addNote(
                $investigationId,
                $resolvedBy,
                "Investigation resolved: {$resolution}\n\n{$resolutionNotes}",
                'internal'
            );
        }

        return $investigation;
    }

    /**
     * Close investigation
     * 
     * @param int $investigationId
     * @param int|null $closedBy
     * @param string|null $reason
     * @return ViolationInvestigation
     */
    public function closeInvestigation(
        int $investigationId,
        ?int $closedBy = null,
        ?string $reason = null
    ): ViolationInvestigation {
        $investigation = ViolationInvestigation::findOrFail($investigationId);

        $investigation->update([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => $closedBy,
        ]);

        if ($closedBy && $reason) {
            $this->addNote(
                $investigationId,
                $closedBy,
                "Investigation closed: {$reason}",
                'internal'
            );
        }

        return $investigation;
    }

    /**
     * Get investigation details
     * 
     * @param int $investigationId
     * @return ViolationInvestigation|null
     */
    public function getDetails(int $investigationId): ?ViolationInvestigation
    {
        return ViolationInvestigation::with([
            'violation',
            'investigator',
            'notes',
            'evidence',
        ])->findOrFail($investigationId);
    }

    /**
     * Get investigation notes with visibility control
     * 
     * @param int $investigationId
     * @param string|null $visibility
     * @return mixed
     */
    public function getNotes(int $investigationId, ?string $visibility = null)
    {
        $query = InvestigationNote::where('policy_violation_investigation_id', $investigationId);

        if ($visibility) {
            $query->where('visibility', $visibility);
        }

        return $query->orderBy('created_at')->with('author')->get();
    }

    /**
     * Get investigation evidence
     * 
     * @param int $investigationId
     * @return mixed
     */
    public function getEvidence(int $investigationId)
    {
        return InvestigationEvidence::where('policy_violation_investigation_id', $investigationId)
            ->orderByDesc('created_at')
            ->with('uploadedByUser')
            ->get();
    }

    /**
     * Get pending investigations
     * 
     * @param int|null $assignedToId
     * @return mixed
     */
    public function getPendingInvestigations(?int $assignedToId = null)
    {
        $query = ViolationInvestigation::whereIn('status', ['open', 'in_progress']);

        if ($assignedToId) {
            $query->where('assigned_to', $assignedToId);
        }

        return $query->with('violation', 'investigator')
            ->orderByDesc('investigation_started_at')
            ->get();
    }

    /**
     * Get overdue investigations (open > 30 days)
     * 
     * @return mixed
     */
    public function getOverdueInvestigations()
    {
        return ViolationInvestigation::whereIn('status', ['open', 'in_progress'])
            ->where('investigation_started_at', '<', Carbon::now()->subDays(30))
            ->with('violation', 'investigator')
            ->orderBy('investigation_started_at')
            ->get();
    }

    /**
     * Get investigation statistics
     * 
     * @return array
     */
    public function getStatistics(): array
    {
        return [
            'total_investigations' => ViolationInvestigation::count(),
            'open' => ViolationInvestigation::where('status', 'open')->count(),
            'in_progress' => ViolationInvestigation::where('status', 'in_progress')->count(),
            'resolved' => ViolationInvestigation::where('status', 'resolved')->count(),
            'closed' => ViolationInvestigation::where('status', 'closed')->count(),
            'avg_investigation_duration' => $this->calculateAverageDuration(),
            'resolution_breakdown' => $this->getResolutionBreakdown(),
        ];
    }

    /**
     * Calculate average investigation duration
     * 
     * @return int
     */
    private function calculateAverageDuration(): int
    {
        $closed = ViolationInvestigation::where('status', 'closed')
            ->whereNotNull('investigation_ended_at')
            ->get();

        if ($closed->isEmpty()) {
            return 0;
        }

        $totalDays = 0;
        foreach ($closed as $investigation) {
            $totalDays += $investigation->investigation_started_at->diffInDays(
                $investigation->investigation_ended_at
            );
        }

        return (int) ($totalDays / $closed->count());
    }

    /**
     * Get resolution type breakdown
     * 
     * @return array
     */
    private function getResolutionBreakdown(): array
    {
        return ViolationInvestigation::where('status', 'resolved')
            ->groupBy('resolution_type')
            ->selectRaw('resolution_type, count(*) as count')
            ->pluck('count', 'resolution_type')
            ->toArray();
    }

    /**
     * Notify investigator of assignment
     * 
     * @param ViolationInvestigation $investigation
     */
    private function notifyInvestigatorAssigned(ViolationInvestigation $investigation): void
    {
        if (!$investigation->assigned_to) {
            return;
        }

        $investigator = \App\Core\User\Models\User::find($investigation->assigned_to);

        if (!$investigator?->email) {
            return;
        }

        \Illuminate\Support\Facades\Mail::to($investigator->email)
            ->send(new \App\Modules\Policies\Mail\InvestigatorAssignedMail($investigation));
    }
}
