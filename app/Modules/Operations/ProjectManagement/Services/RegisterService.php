<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Models\ApprovalRequest;
use App\Modules\Operations\ProjectManagement\Models\ChangeRequest;
use App\Modules\Operations\ProjectManagement\Models\Issue;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\Risk;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Risks, issues and change requests (§11, §12, §13).
 *
 * One service for three registers because the *operations* are shared — numbering, evidence, the
 * transition rules — even though the records are not. Splitting it into three would mean three
 * copies of `nextCode()` drifting apart, and the first symptom of that is duplicate reference
 * numbers on a document somebody has already sent to a client.
 *
 * ── Why numbering is done in a transaction with a lock ───────────────────────
 *
 * `R-001` is quoted in minutes, emails and contract correspondence, so two risks sharing a number
 * is not a cosmetic problem. The partial unique index on (company, project, code) is the real
 * guard; this takes the lock so the common case is a clean number rather than a caught violation.
 */
class RegisterService
{
    public function __construct(
        private readonly InternalEmitter $emitter,
        private readonly ApprovalService $approvals,
        private readonly AuditLogger $audit,
    ) {
    }

    // ── Risks ───────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $data */
    public function createRisk(Project $project, array $data): Risk
    {
        return DB::transaction(function () use ($project, $data) {
            $risk = Risk::create([
                ...$data,
                'project_id' => $project->id,
                'code'       => $this->nextCode(Risk::class, $project, 'R'),
                'created_by' => Auth::id(),
            ]);

            // A high risk is a fact about the project, not just a row in a register. It goes on the
            // timeline so a status report assembled from evidence carries it without anybody
            // remembering to mention it.
            if ($risk->isHigh()) {
                $this->emitter->emit($project, 'issue.raised', [
                    'subject_type' => 'risk',
                    'subject_id'   => $risk->uuid,
                    'source_ref'   => 'risk:' . $risk->uuid,
                    'payload'      => ['summary' => $risk->code . ' · ' . $risk->title],
                ]);
            }

            return $risk;
        });
    }

    /** @param array<string,mixed> $data */
    public function updateRisk(Risk $risk, array $data): Risk
    {
        $risk->update($data);

        return $risk->refresh();
    }

    public function closeRisk(Risk $risk, string $note): Risk
    {
        if (trim($note) === '') {
            // Same reasoning as a rejected approval: "closed" with no explanation is a row nobody
            // can defend when the risk turns up again next quarter.
            throw new RuntimeException(__('project_management.registers.close_needs_note'));
        }

        $risk->update(['status' => 'closed', 'closed_at' => now(), 'closure_note' => $note]);

        return $risk->refresh();
    }

    /**
     * The risk came true.
     *
     * The risk is marked `realised` rather than deleted, and the new issue points back at it. That
     * link is the only durable evidence that somebody saw this coming and wrote it down — which is
     * exactly the question asked after the fact, and exactly the record that a register which
     * silently converts rows cannot produce.
     */
    public function promoteRisk(Risk $risk, array $data = []): Issue
    {
        return DB::transaction(function () use ($risk, $data) {
            $project = $risk->project;

            $issue = $this->createIssue($project, [
                'title'       => $data['title'] ?? $risk->title,
                'description' => $data['description'] ?? $risk->description,
                'kind'        => $data['kind'] ?? 'issue',
                'severity'    => $data['severity'] ?? ($risk->impact >= 4 ? 'critical' : 'high'),
                'priority'    => $data['priority'] ?? 'high',
                'cost_impact' => $risk->cost_impact,
                'currency'    => $risk->currency,
                'risk_id'     => $risk->id,
            ]);

            $risk->update([
                'status'       => 'realised',
                'closed_at'    => now(),
                'closure_note' => __('project_management.registers.realised_as', ['code' => $issue->code]),
            ]);

            $this->audit->recordAction($risk, 'risk.realised', ['issue' => $issue->code]);

            return $issue;
        });
    }

    // ── Issues ──────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $data */
    public function createIssue(Project $project, array $data): Issue
    {
        return DB::transaction(function () use ($project, $data) {
            $issue = Issue::create([
                ...$data,
                'project_id' => $project->id,
                'code'       => $this->nextCode(Issue::class, $project, 'I'),
                'raised_by'  => $data['raised_by'] ?? Auth::id(),
            ]);

            $this->emitter->emit($project, 'issue.raised', [
                'subject_type' => 'issue',
                'subject_id'   => $issue->uuid,
                'source_ref'   => 'issue:' . $issue->uuid,
                'payload'      => ['summary' => $issue->code . ' · ' . $issue->title],
            ]);

            return $issue;
        });
    }

    /** @param array<string,mixed> $data */
    public function updateIssue(Issue $issue, array $data): Issue
    {
        $issue->update($data);

        return $issue->refresh();
    }

    /**
     * Resolve, with the three answers an audit will ask for.
     *
     * Root cause is demanded only where it genuinely matters — an incident, or anything critical.
     * Asking for it on every typo is how a field becomes "n/a" everywhere and stops meaning
     * anything on the one record where it mattered.
     */
    public function resolveIssue(Issue $issue, array $data): Issue
    {
        if ($issue->needsRootCause() && trim((string) ($data['root_cause'] ?? '')) === '') {
            throw new RuntimeException(__('project_management.registers.needs_root_cause'));
        }

        $issue->update([
            'status'              => 'resolved',
            'resolved_at'         => now(),
            'resolution'          => $data['resolution'] ?? null,
            'root_cause'          => $data['root_cause'] ?? $issue->root_cause,
            'corrective_action'   => $data['corrective_action'] ?? $issue->corrective_action,
            'preventative_action' => $data['preventative_action'] ?? $issue->preventative_action,
        ]);

        return $issue->refresh();
    }

    // ── Change requests ─────────────────────────────────────────────────────

    /** @param array<string,mixed> $data */
    public function createChange(Project $project, array $data): ChangeRequest
    {
        return ChangeRequest::create([
            ...$data,
            'project_id'   => $project->id,
            'code'         => $this->nextCode(ChangeRequest::class, $project, 'CR'),
            'requested_by' => Auth::id(),
            'currency'     => $data['currency'] ?? $project->currency,
            'status'       => 'draft',
        ]);
    }

    /**
     * Send it for a decision.
     *
     * Routed through the module's one approval engine rather than a bespoke workflow, so a change
     * request obeys the same thresholds, the same separation of duties and the same inbox as
     * everything else. The cost impact is the amount, which is what makes a tenant's "anything over
     * R50 000 goes to the board" rule apply to changes without anybody configuring it twice.
     */
    public function submitChange(ChangeRequest $change): ChangeRequest
    {
        if (! $change->isEditable()) {
            throw new RuntimeException(__('project_management.registers.change_not_editable'));
        }

        return DB::transaction(function () use ($change) {
            $request = $this->approvals->submit('change_request', $change, $change->project, [
                'label'    => $change->code . ' · ' . $change->title,
                'amount'   => $change->impact_cost,
                'currency' => $change->currency,
                'note'     => $change->reason,
            ]);

            $change->update([
                'status'        => 'submitted',
                'approval_uuid' => $request?->uuid,
            ]);

            // No flow designed and no default routing for this subject means nobody was asked. Said
            // out loud on the record rather than left as a request that sits "submitted" forever.
            if ($request === null) {
                $change->update([
                    'status'        => 'approved',
                    'decided_at'    => now(),
                    'decision_note' => __('project_management.registers.no_flow_auto'),
                ]);
            }

            return $change->refresh();
        });
    }

    /**
     * Apply an approved change to the plan.
     *
     * Taking a baseline at this moment is the point of the whole record: it is what makes "what did
     * this change actually do to the schedule" answerable later, instead of a matter of whose
     * memory is better.
     */
    public function implementChange(ChangeRequest $change, ?BaselineService $baselines = null): ChangeRequest
    {
        if ($change->status !== 'approved') {
            throw new RuntimeException(__('project_management.registers.change_not_approved'));
        }

        return DB::transaction(function () use ($change, $baselines) {
            $baseline = null;

            if ($baselines) {
                $baseline = $baselines->capture(
                    $change->project,
                    __('project_management.registers.baseline_name', ['code' => $change->code]),
                );
            }

            $change->update([
                'status'          => 'implemented',
                'implemented_at'  => now(),
                'baseline_id'     => $baseline?->id,
            ]);

            $this->emitter->emit($change->project, 'status.changed', [
                'subject_type' => 'change_request',
                'subject_id'   => $change->uuid,
                'source_ref'   => 'change:' . $change->uuid . ':implemented',
                'payload'      => ['from' => 'approved', 'to' => 'implemented'],
            ]);

            return $change->refresh();
        });
    }

    /**
     * Mirror approval decisions onto the change requests that are waiting on them.
     *
     * The approval engine deliberately knows nothing about its subjects — it decides, and the
     * subject reads the decision. Reconciling on read rather than teaching the engine about a
     * fourth subject type keeps that engine free of a growing match statement, and costs one query
     * on a page that is already loading the register.
     */
    public function reconcileChanges(Project $project): void
    {
        $pending = ChangeRequest::where('project_id', $project->id)
            ->where('status', 'submitted')
            ->whereNotNull('approval_uuid')
            ->get();

        if ($pending->isEmpty()) {
            return;
        }

        $decisions = ApprovalRequest::whereIn('uuid', $pending->pluck('approval_uuid'))
            ->whereIn('status', ['approved', 'rejected'])
            ->get()
            ->keyBy('uuid');

        foreach ($pending as $change) {
            $decision = $decisions[$change->approval_uuid] ?? null;

            if ($decision) {
                $change->update([
                    'status'        => $decision->status,
                    'decided_at'    => $decision->decided_at,
                    'decision_note' => $decision->decision_reason,
                ]);
            }
        }
    }

    // ────────────────────────────────────────────────────────────────────────

    /**
     * The next reference for this register on this project.
     *
     * Derived from the highest number in use rather than a counter column, so it survives a record
     * being hard-deleted and does not need a second table kept in step. Soft-deleted rows still
     * count: reusing the number of a risk somebody deleted would put two different risks under one
     * reference in the same email thread.
     *
     * @param  class-string<Model>  $model
     */
    private function nextCode(string $model, Project $project, string $prefix): string
    {
        return DB::transaction(function () use ($model, $project, $prefix) {
            $last = $model::withTrashed()
                ->where('project_id', $project->id)
                ->lockForUpdate()
                ->orderByDesc('id')
                ->value('code');

            $n = $last && preg_match('/(\d+)$/', $last, $m) ? ((int) $m[1]) + 1 : 1;

            return sprintf('%s-%03d', $prefix, $n);
        });
    }
}
