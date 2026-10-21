<?php

namespace App\Modules\Operations\DocumentManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentApprovalAssignment;
use App\Modules\Operations\DocumentManagement\Models\DocumentApprovalRequest;
use App\Modules\Operations\DocumentManagement\Models\DocumentApprovalStep;
use App\Modules\Operations\DocumentManagement\Models\DocumentApprovalWorkflow;
use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use App\Modules\Operations\DocumentManagement\Notifications\ApprovalRequestNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Running a document through its approvals.
 *
 * The four shapes the requirement asks for are one mechanism rather than four. Steps sharing a
 * position run at the same time — that is parallel. Consecutive positions run in order — that is
 * sequential. A single step is single-level and several are multi-level. Nothing here has a flag
 * saying which kind of workflow it is, because the arrangement of the steps already says it.
 *
 * Delegation and deadlines sit on the assignment rather than the request, since they are about one
 * person's part: the finance manager can hand theirs to a deputy without affecting the legal
 * review running alongside it.
 */
class ApprovalService
{
    /**
     * Sends a document for approval.
     *
     * The version is recorded, because an approval is about specific bytes — approving a contract
     * and then replacing the file would otherwise leave the approval attached to something nobody
     * agreed to.
     *
     * @return array{request: ?DocumentApprovalRequest, error: ?string}
     */
    public function submit(Document $document, ?DocumentApprovalWorkflow $workflow = null, ?string $note = null): array
    {
        if ($document->approvalRequest?->isOpen()) {
            return ['request' => null, 'error' => __('document_management.approvals.already_open')];
        }

        $workflow ??= DocumentApprovalWorkflow::forDocument($document);

        if (! $workflow) {
            return ['request' => null, 'error' => __('document_management.approvals.no_workflow')];
        }

        if ($workflow->steps->isEmpty()) {
            return ['request' => null, 'error' => __('document_management.approvals.no_steps')];
        }

        return DB::transaction(function () use ($document, $workflow, $note) {
            $request = DocumentApprovalRequest::create([
                'document_id' => $document->id,
                'workflow_id' => $workflow->id,
                'version_id' => $document->current_version_id,
                'status' => DocumentApprovalRequest::SUBMITTED,
                'current_position' => (int) $workflow->steps->min('position'),
                'submitted_by' => Auth::id(),
                'submitted_at' => now(),
                'outcome_note' => $note,
            ]);

            // Every step is written up front, not just the current one. The person submitting can
            // then see the whole route their document is about to take, which is most of what they
            // want to know before they press the button.
            foreach ($workflow->steps as $step) {
                $this->createAssignments($request, $step);
            }

            $document->forceFill([
                'approval_status' => Document::APPROVAL_SUBMITTED,
                'approval_request_id' => $request->id,
            ])->save();

            app(DocumentService::class)->record($document, DocumentEvent::APPROVAL_SUBMITTED, null, [
                'workflow' => $workflow->name,
            ]);

            $this->openPosition($request);

            return ['request' => $request->fresh(), 'error' => null];
        });
    }

    /**
     * Turns one step into the assignments it implies.
     *
     * A step naming a role becomes one assignment per person holding it, so "all managers must
     * approve" means what it says. Nobody holding the role at all leaves a single unassigned row
     * rather than silently skipping the step — a step that vanishes because a role is empty is a
     * document approved by nobody.
     */
    private function createAssignments(DocumentApprovalRequest $request, DocumentApprovalStep $step): void
    {
        $due = $step->deadline_days ? now()->addDays($step->deadline_days) : null;

        $base = [
            'request_id' => $request->id,
            'step_id' => $step->id,
            'position' => $step->position,
            'step_name' => $step->name,
            'status' => DocumentApprovalAssignment::PENDING,
            'due_at' => $due,
        ];

        if ($step->approver_type === DocumentApprovalStep::APPROVER_USER && $step->approver_id) {
            DocumentApprovalAssignment::create($base + ['assigned_to' => $step->approver_id]);

            return;
        }

        $holders = User::whereHas('roles', fn ($q) => $q->where('name', $step->approver_name))->get();

        if ($holders->isEmpty()) {
            DocumentApprovalAssignment::create($base + ['assigned_role' => $step->approver_name]);

            return;
        }

        foreach ($holders as $holder) {
            DocumentApprovalAssignment::create($base + [
                'assigned_to' => $holder->id,
                'assigned_role' => $step->approver_name,
            ]);
        }
    }

    /**
     * Records one person's decision.
     *
     * @param  'approved'|'rejected'|'changes_requested'  $decision
     * @return array{request: ?DocumentApprovalRequest, error: ?string}
     */
    public function decide(
        DocumentApprovalAssignment $assignment,
        string $decision,
        ?User $user = null,
        ?string $comment = null,
    ): array {
        $user ??= Auth::user();
        $request = $assignment->request;

        if (! $assignment->isFor($user)) {
            return ['request' => null, 'error' => __('document_management.approvals.not_yours')];
        }

        if ($assignment->status !== DocumentApprovalAssignment::PENDING) {
            return ['request' => null, 'error' => __('document_management.approvals.already_decided')];
        }

        if (! $request->isOpen()) {
            return ['request' => null, 'error' => __('document_management.approvals.closed')];
        }

        // Four eyes, where the workflow asks for them: whoever submitted the document, or uploaded
        // the version being approved, does not approve it.
        if ($decision === 'approved' && $request->workflow?->separate_from_submitter
            && in_array((int) $user?->id, [(int) $request->submitted_by, (int) \App\Modules\Operations\DocumentManagement\Models\DocumentVersion::query()->whereKey($request->version_id)->value('uploaded_by')], true)) {
            return ['request' => null, 'error' => __('document_management.approvals.not_your_own')];
        }

        // A decision on a step that is not the one in progress would let somebody approve a later
        // stage before the earlier one had run, which is the whole point of sequential.
        if ($assignment->position !== $request->current_position) {
            return ['request' => null, 'error' => __('document_management.approvals.not_current_step')];
        }

        return DB::transaction(function () use ($assignment, $decision, $user, $comment, $request) {
            $assignment->forceFill([
                'status' => $decision,
                'decided_by' => $user?->id,
                'decided_at' => now(),
                'comment' => $comment,
            ])->save();

            $document = $request->document;

            app(DocumentService::class)->record($document, match ($decision) {
                DocumentApprovalAssignment::APPROVED => DocumentEvent::APPROVED,
                DocumentApprovalAssignment::REJECTED => DocumentEvent::REJECTED,
                default => DocumentEvent::CHANGES_REQUESTED,
            }, null, ['step' => $assignment->step_name, 'comment' => $comment]);

            /*
             * A rejection or a request for changes stops the whole thing there.
             *
             * Carrying on to the next step after somebody has said no would produce a document
             * approved by finance and rejected by legal, with nothing to say which won.
             */
            if ($decision !== DocumentApprovalAssignment::APPROVED) {
                return $this->close($request, $decision === DocumentApprovalAssignment::REJECTED
                    ? DocumentApprovalRequest::REJECTED
                    : DocumentApprovalRequest::CHANGES_REQUESTED, $comment);
            }

            return $this->advance($request);
        });
    }

    /**
     * Moves on if this position is finished.
     *
     * @return array{request: ?DocumentApprovalRequest, error: ?string}
     */
    private function advance(DocumentApprovalRequest $request): array
    {
        $atPosition = $request->assignments()->where('position', $request->current_position)->get();
        $step = $atPosition->first()?->step_id
            ? DocumentApprovalStep::find($atPosition->first()->step_id)
            : null;

        $approved = $atPosition->where('status', DocumentApprovalAssignment::APPROVED)->count();
        $pending = $atPosition->where('status', DocumentApprovalAssignment::PENDING)->count();

        $satisfied = $step && ! $step->requiresEveryone()
            // "Any" is satisfied by the first approval, and the rest stop being anybody's problem.
            ? $approved >= 1
            : $pending === 0;

        if (! $satisfied) {
            $request->forceFill(['status' => DocumentApprovalRequest::UNDER_REVIEW])->save();

            return ['request' => $request->fresh(), 'error' => null];
        }

        if ($step && ! $step->requiresEveryone() && $pending > 0) {
            // Whoever had not answered no longer needs to: their task is closed as skipped rather
            // than left pending forever in somebody's queue.
            $request->assignments()
                ->where('position', $request->current_position)
                ->where('status', DocumentApprovalAssignment::PENDING)
                ->update(['status' => DocumentApprovalAssignment::SKIPPED, 'decided_at' => now()]);
        }

        $next = $request->assignments()
            ->where('position', '>', $request->current_position)
            ->min('position');

        if ($next === null) {
            return $this->close($request, DocumentApprovalRequest::APPROVED);
        }

        $request->forceFill([
            'current_position' => (int) $next,
            'status' => DocumentApprovalRequest::UNDER_REVIEW,
        ])->save();

        $this->openPosition($request->fresh());

        return ['request' => $request->fresh(), 'error' => null];
    }

    /** @return array{request: DocumentApprovalRequest, error: null} */
    private function close(DocumentApprovalRequest $request, string $status, ?string $note = null): array
    {
        $request->forceFill([
            'status' => $status,
            'completed_at' => now(),
            'outcome_note' => $note ?: $request->outcome_note,
        ])->save();

        $document = $request->document;

        $document->forceFill([
            'approval_status' => match ($status) {
                DocumentApprovalRequest::APPROVED => Document::APPROVAL_APPROVED,
                DocumentApprovalRequest::REJECTED => Document::APPROVAL_REJECTED,
                default => Document::APPROVAL_CHANGES_REQUESTED,
            },
        ])->save();

        return ['request' => $request->fresh(), 'error' => null];
    }

    /** Tells the people whose turn it now is. */
    private function openPosition(DocumentApprovalRequest $request): void
    {
        $assignments = $request->currentAssignments()->get();

        foreach ($assignments as $assignment) {
            $recipient = $assignment->responsibleId() ? User::find($assignment->responsibleId()) : null;

            if (! $recipient) {
                continue;
            }

            try {
                Notification::send($recipient, new ApprovalRequestNotification($request, $assignment));
            } catch (\Throwable $e) {
                // One mail failing must not stop the others being told, or leave the workflow
                // half-open with nobody aware it is their turn.
                Log::warning('Approval notification failed.', [
                    'assignment' => $assignment->uuid, 'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Hands one person's part to somebody else.
     *
     * Both ends are kept: the request went to the finance manager, and their deputy answered it.
     * Recording only the delegate loses who was accountable; recording only the manager claims they
     * approved something they never read.
     *
     * @return array{assignment: ?DocumentApprovalAssignment, error: ?string}
     */
    public function delegate(DocumentApprovalAssignment $assignment, User $to, ?User $from = null, ?string $reason = null): array
    {
        $from ??= Auth::user();

        if (! $assignment->isFor($from)) {
            return ['assignment' => null, 'error' => __('document_management.approvals.not_yours')];
        }

        if ($assignment->status !== DocumentApprovalAssignment::PENDING) {
            return ['assignment' => null, 'error' => __('document_management.approvals.already_decided')];
        }

        if ((int) $to->id === (int) ($from?->id)) {
            return ['assignment' => null, 'error' => __('document_management.approvals.delegate_to_self')];
        }

        $assignment->forceFill([
            'delegated_to' => $to->id,
            'delegated_at' => now(),
            'delegation_reason' => $reason,
        ])->save();

        app(DocumentService::class)->record($assignment->request->document, DocumentEvent::APPROVAL_DELEGATED, null, [
            'step' => $assignment->step_name,
            'to' => $to->id,
            'reason' => $reason,
        ]);

        try {
            Notification::send($to, new ApprovalRequestNotification($assignment->request, $assignment->fresh()));
        } catch (\Throwable $e) {
            Log::warning('Delegation notification failed.', ['error' => $e->getMessage()]);
        }

        return ['assignment' => $assignment->fresh(), 'error' => null];
    }

    /**
     * Publishes an approved document.
     *
     * Separate from approval on purpose: approving says the content is right, publishing says it is
     * now the one in force. A policy approved in March and published in April is normal, and
     * collapsing the two would remove the organisation's ability to decide when.
     *
     * @return array{document: ?Document, error: ?string}
     */
    public function publish(Document $document): array
    {
        if ($document->approval_status !== Document::APPROVAL_APPROVED) {
            return ['document' => null, 'error' => __('document_management.approvals.publish_requires_approval')];
        }

        $document->forceFill([
            'approval_status' => Document::APPROVAL_PUBLISHED,
            'published_at' => now(),
        ])->save();

        app(DocumentService::class)->record($document, DocumentEvent::PUBLISHED);

        return ['document' => $document->fresh(), 'error' => null];
    }

    /** Withdraws a request that is still open. */
    public function cancel(DocumentApprovalRequest $request, ?string $reason = null): void
    {
        if (! $request->isOpen()) {
            return;
        }

        $request->forceFill([
            'status' => DocumentApprovalRequest::CANCELLED,
            'completed_at' => now(),
            'outcome_note' => $reason,
        ])->save();

        $request->assignments()->where('status', DocumentApprovalAssignment::PENDING)
            ->update(['status' => DocumentApprovalAssignment::SKIPPED, 'decided_at' => now()]);

        $request->document->forceFill(['approval_status' => Document::APPROVAL_DRAFT])->save();
    }

    /** What is waiting on somebody, for their queue. */
    public function waitingOn(User $user)
    {
        return DocumentApprovalAssignment::query()
            ->where('status', DocumentApprovalAssignment::PENDING)
            ->where(function ($q) use ($user) {
                $q->where('delegated_to', $user->id)
                    ->orWhere(fn ($w) => $w->where('assigned_to', $user->id)->whereNull('delegated_to'));
            })
            ->whereIn('request_id', DocumentApprovalRequest::query()
                ->whereIn('status', [DocumentApprovalRequest::SUBMITTED, DocumentApprovalRequest::UNDER_REVIEW])
                ->select('id'))
            ->whereColumn('position', '=', DB::raw('(select current_position from document_approval_requests where id = document_approval_assignments.request_id)'));
    }
}
