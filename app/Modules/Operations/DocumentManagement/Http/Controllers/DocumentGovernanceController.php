<?php

namespace App\Modules\Operations\DocumentManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentLegalHold;
use App\Modules\Operations\DocumentManagement\Services\LegalHoldService;
use App\Modules\Operations\DocumentManagement\Services\RetentionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The two governance screens: what is being preserved, and what has come due.
 *
 * They share a controller because they are two halves of one question — a document is either being
 * kept deliberately or it is waiting for somebody to say whether it still needs keeping — and
 * because the disposal queue is meaningless without the hold register next to it.
 */
class DocumentGovernanceController extends Controller
{
    public function __construct(
        private readonly LegalHoldService $holds,
        private readonly RetentionService $retention,
    ) {
    }

    /**
     * The hold register: live matters, and the documents held under each.
     */
    public function holds(Request $request): Response
    {
        $this->authorize('manageLegalHold', Document::class);

        $reference = trim((string) $request->query('reference', ''));

        $documents = $reference === ''
            ? collect()
            : DocumentLegalHold::query()
                ->with(['document.category', 'placedBy.person'])
                ->where('reference', $reference)
                ->active()
                ->orderBy('placed_at')
                ->get()
                ->map(fn (DocumentLegalHold $hold) => [
                    'uuid' => $hold->uuid,
                    'placed_at' => $hold->placed_at?->toDayDateTimeString(),
                    'reason' => $hold->reason,
                    'placed_by' => $this->name($hold->placedBy),
                    'document' => $hold->document ? [
                        'uuid' => $hold->document->uuid,
                        'title' => $hold->document->title,
                        'category' => $hold->document->category?->name,
                    ] : null,
                ]);

        return Inertia::render('Modules/DocumentManagement/Pages/LegalHolds', [
            'register' => collect($this->holds->register())->map(fn (array $row) => [
                'reference' => $row['reference'],
                'documents' => $row['documents'],
                'oldest' => $row['oldest']?->toFormattedDateString(),
            ])->values(),
            'selected' => $reference === '' ? null : $reference,
            'documents' => $documents->values(),
            'can' => [
                'manage' => $request->user()?->can('document.legal_hold') ?? false,
            ],
        ]);
    }

    /** Places a hold on one document. */
    public function place(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('manageLegalHold', $document);

        $data = $request->validate([
            'reference' => ['required', 'string', 'max:120'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->holds->place($document, $data['reference'], $data['reason'] ?? null);

        return back()->with('success', 'Held. It cannot be deleted or destroyed while the hold stands.');
    }

    /** Lifts one hold. Others on the same document, under other matters, are untouched. */
    public function release(Request $request, DocumentLegalHold $hold): RedirectResponse
    {
        $document = $hold->document()->withoutGlobalScopes()->first();

        $this->authorize('manageLegalHold', $document ?? Document::class);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->holds->release($hold, $data['reason'] ?? null);

        return back()->with('success', 'Hold lifted. The record that it was in place is kept.');
    }

    /** Lifts every live hold placed under one matter. */
    public function releaseMatter(Request $request): RedirectResponse
    {
        $this->authorize('manageLegalHold', Document::class);

        $data = $request->validate([
            'reference' => ['required', 'string', 'max:120'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $lifted = $this->holds->releaseMatter($data['reference'], $data['reason'] ?? null);

        return back()->with('success', "Matter closed. {$lifted} hold(s) lifted.");
    }

    /**
     * The disposal queue.
     *
     * Shows what has come due and what has already been approved, side by side, because approving
     * a disposal and carrying it out are different acts and somebody has to be able to see what is
     * waiting on the second.
     */
    public function disposal(Request $request): Response
    {
        $this->authorize('reviewRetention', Document::class);

        $state = in_array($request->query('state'), [RetentionService::STATE_APPROVED, RetentionService::STATE_RETAINED], true)
            ? $request->query('state')
            : RetentionService::STATE_DUE;

        $documents = $this->retention->queue($state)->paginate(25)->through(fn (Document $document) => [
            'uuid' => $document->uuid,
            'title' => $document->title,
            'category' => $document->category?->name,
            'folder' => $document->folder?->name,
            'due_at' => $document->disposal_due_at?->toFormattedDateString(),
            'reviewed_at' => $document->disposal_reviewed_at?->toFormattedDateString(),
            'note' => $document->disposal_note,
            'held' => $document->isUnderLegalHold(),
        ]);

        return Inertia::render('Modules/DocumentManagement/Pages/Disposal', [
            'documents' => $documents,
            'state' => $state,
            'summary' => $this->retention->summary(),
            'can' => [
                'purge' => $request->user()?->can('document.purge') ?? false,
            ],
        ]);
    }

    /** Keep it: the reviewer says this is still needed. */
    public function retain(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('reviewRetention', Document::class);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        $this->retention->retain($document, $data['note'] ?? null);

        return back()->with('success', 'Kept. It will come up for review again at the end of its next period.');
    }

    /** Approve destruction — which is not the same as destroying it. */
    public function approveDisposal(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('reviewRetention', Document::class);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        $result = $this->retention->approveDisposal($document, $data['note'] ?? null);

        return $result['ok']
            ? back()->with('success', 'Approved for disposal. Somebody with the destroy permission still has to carry it out.')
            : back()->with('error', $result['error']);
    }

    private function name(?object $user): ?string
    {
        if (! $user) {
            return null;
        }

        // There is no users.name column; the person record carries it.
        $person = $user->person ?? null;

        return $person
            ? trim($person->first_name . ' ' . $person->surname)
            : $user->email;
    }
}
