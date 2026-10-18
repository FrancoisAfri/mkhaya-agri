<?php

namespace App\Modules\Operations\DocumentManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentGrant;
use App\Modules\Operations\DocumentManagement\Services\InternalShareService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Sharing a document with somebody inside the organisation.
 *
 * Uses the same permission as external sharing. Both hand a document to somebody who could not
 * otherwise open it, and the difference between a colleague and an auditor is not a difference in
 * how carefully the decision should be made.
 */
class DocumentInternalShareController extends Controller
{
    public function __construct(private readonly InternalShareService $shares)
    {
    }

    public function store(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('share', $document);

        $data = $request->validate([
            'subject_type' => ['required', Rule::in(DocumentGrant::SUBJECTS)],
            'subject_id' => ['nullable', 'integer'],
            'subject_name' => ['nullable', 'string', 'max:190'],
            'can_download' => ['nullable', 'boolean'],
            'expires_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        [$subjectId, $subjectName] = $this->resolveSubject($data);

        if ($subjectName === null) {
            return back()->with('error', __('document_management.sharing.unknown_subject'));
        }

        $this->shares->grant($document, $data['subject_type'], $subjectId, $subjectName, [
            'can_download' => $request->boolean('can_download'),
            'expires_days' => $data['expires_days'] ?? null,
            'note' => $data['note'] ?? null,
        ]);

        return back()->with('success', __('document_management.sharing.shared_internally'));
    }

    public function destroy(DocumentGrant $grant): RedirectResponse
    {
        $document = $grant->document()->withoutGlobalScopes()->firstOrFail();

        $this->authorize('share', $document);

        $this->shares->revoke($grant);

        return back()->with('success', __('document_management.sharing.internal_revoked'));
    }

    /**
     * Turns what the form sent into a subject this company actually has.
     *
     * Resolved server-side rather than trusting the name in the request: the label is what a list of
     * shares displays afterwards, and a name supplied by the browser would let one person's share
     * be labelled as somebody else's.
     *
     * @param array<string, mixed> $data
     * @return array{0: ?int, 1: ?string}
     */
    private function resolveSubject(array $data): array
    {
        $id = $data['subject_id'] ?? null;

        return match ($data['subject_type']) {
            DocumentGrant::SUBJECT_COMPANY => [null, __('document_management.share_everyone')],

            DocumentGrant::SUBJECT_USER => (function () use ($id) {
                $user = $id ? User::find($id) : null;

                if (! $user) {
                    return [null, null];
                }

                // There is no users.name column; the person record carries it.
                $person = $user->person;
                $name = $person ? trim($person->first_name . ' ' . $person->surname) : $user->email;

                return [$user->id, $name ?: $user->email];
            })(),

            DocumentGrant::SUBJECT_DEPARTMENT => (function () use ($id) {
                $name = $id
                    ? DB::table('company_units')->where('id', $id)->where('type', 'department')->value('name')
                    : null;

                return $name ? [(int) $id, $name] : [null, null];
            })(),

            // Roles are matched on the name, as folder permissions already are.
            DocumentGrant::SUBJECT_ROLE => (function () use ($data) {
                $name = $data['subject_name'] ?? null;

                return $name && \Spatie\Permission\Models\Role::where('name', $name)->exists()
                    ? [null, $name]
                    : [null, null];
            })(),

            default => [null, null],
        };
    }
}
