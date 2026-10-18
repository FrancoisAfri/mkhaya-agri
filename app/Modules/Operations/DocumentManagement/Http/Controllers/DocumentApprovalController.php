<?php

namespace App\Modules\Operations\DocumentManagement\Http\Controllers;

use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentApprovalAssignment;
use App\Modules\Operations\DocumentManagement\Models\DocumentApprovalRequest;
use App\Modules\Operations\DocumentManagement\Models\DocumentApprovalWorkflow;
use App\Modules\Operations\DocumentManagement\Services\ApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Sending documents for approval, and answering the requests.
 *
 * Deciding is authorised by the assignment rather than by a document permission: the person who
 * must approve a contract is whoever the workflow named, and requiring them to also hold an edit
 * permission on the document would mean granting reviewers rights they do not need.
 */
class DocumentApprovalController extends Controller
{
    public function __construct(private readonly ApprovalService $approvals)
    {
    }

    public function submit(Request $request, Document $document): RedirectResponse
    {
        // Submitting is a change to the document's state, so it needs the same right as editing it.
        $this->authorize('update', $document);

        $data = $request->validate([
            'workflow' => ['nullable', 'string'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $workflow = filled($data['workflow'] ?? null)
            ? DocumentApprovalWorkflow::where('uuid', $data['workflow'])->first()
            : null;

        $result = $this->approvals->submit($document, $workflow, $data['note'] ?? null);

        return $result['request']
            ? back()->with('success', __('document_management.approvals.submitted'))
            : back()->with('error', $result['error']);
    }

    public function decide(Request $request, DocumentApprovalAssignment $assignment): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected', 'changes_requested'])],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        // Rejecting or asking for changes without saying why leaves the author guessing, and the
        // audit trail with a decision nobody can explain later.
        if ($data['decision'] !== 'approved' && blank($data['comment'] ?? null)) {
            return back()->with('error', __('document_management.approvals.comment_required'));
        }

        $result = $this->approvals->decide($assignment, $data['decision'], Auth::user(), $data['comment'] ?? null);

        return $result['request']
            ? back()->with('success', __('document_management.approvals.decision_recorded'))
            : back()->with('error', $result['error']);
    }

    public function delegate(Request $request, DocumentApprovalAssignment $assignment): RedirectResponse
    {
        $data = $request->validate([
            'to' => ['required', 'integer'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $to = User::find($data['to']);

        if (! $to) {
            return back()->with('error', __('document_management.approvals.delegate_unknown'));
        }

        $result = $this->approvals->delegate($assignment, $to, Auth::user(), $data['reason'] ?? null);

        return $result['assignment']
            ? back()->with('success', __('document_management.approvals.delegated'))
            : back()->with('error', $result['error']);
    }

    public function publish(Document $document): RedirectResponse
    {
        $this->authorize('update', $document);

        $result = $this->approvals->publish($document);

        return $result['document']
            ? back()->with('success', __('document_management.approvals.published'))
            : back()->with('error', $result['error']);
    }

    public function cancel(Request $request, DocumentApprovalRequest $approval): RedirectResponse
    {
        $this->authorize('update', $approval->document);

        $this->approvals->cancel($approval, $request->input('reason'));

        return back()->with('success', __('document_management.approvals.cancelled'));
    }
}
