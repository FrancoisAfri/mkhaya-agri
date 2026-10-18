<?php

namespace App\Modules\Operations\DocumentManagement\Http\Controllers;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolderPermission;
use App\Modules\Operations\DocumentManagement\Models\DocumentGrant;
use App\Modules\Operations\DocumentManagement\Models\DocumentGroup;
use App\Modules\Operations\DocumentManagement\Services\AccessOverview;
use App\Modules\Operations\DocumentManagement\Services\FolderAccess;
use App\Modules\Operations\DocumentManagement\Services\GroupService;
use App\Modules\Operations\DocumentManagement\Services\InternalShareService;
use App\Modules\Operations\DocumentManagement\Services\LockService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One screen for reviewing access rather than granting it.
 *
 * Every other permissions screen in this module answers "let this person in". None of them answers
 * "who is in, why, and what is about to lapse" — and that is the question asked in an audit, when
 * somebody leaves, and every time a document turns up where it should not have.
 *
 * Everything here can also be undone from here. A review screen that can only report sends somebody
 * to four other screens to act on what they just read, which is how the review stops happening.
 */
class AccessManagementController extends Controller
{
    use \App\Modules\Operations\DocumentManagement\Concerns\PicksTenantSubjects;

    public function __construct(
        private readonly AccessOverview $overview,
        private readonly GroupService $groups,
        private readonly InternalShareService $shares,
        private readonly LockService $locks,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('share', Document::class);

        /*
         * Looking one person up is the second half of this screen, and it is driven by a query
         * parameter rather than a form post so the answer has a URL. "Here is what Thandi can
         * reach" is a thing people send to each other.
         */
        $subject = $this->tenantUser($request, $request->filled('user') ? (int) $request->input('user') : null);

        return Inertia::render('Modules/DocumentManagement/Pages/AccessManagement', [
            'summary' => $this->overview->summary(),
            'temporary' => $this->overview->temporary(),
            'checked_out' => $this->locks->outstanding()->limit(50)->get()->map(fn (Document $document) => [
                'uuid' => $document->uuid,
                'title' => $document->title,
                'by' => $this->name(User::find($document->locked_by)),
                'since' => $document->locked_at?->diffForHumans(),
                'until' => $document->lock_expires_at?->toDayDateTimeString(),
            ])->values(),
            /*
             * The three things an administrator is asked about after the fact: who was turned away,
             * what left the building, and who has quietly accumulated more access than they need.
             */
            'refusals' => $this->overview->refusals(),
            'downloads' => $this->overview->recentDownloads(),
            'heavily_granted' => $this->overview->heavilyGranted(),

            'people' => $this->tenantPeople($request),
            'subject' => $subject ? [
                'id' => $subject->id,
                'name' => $this->name($subject),
            ] : null,
            'access' => $this->overview->forUser($subject),
        ]);
    }

    /**
     * Withdraws one thing, whatever kind of thing it is.
     *
     * A single endpoint because the screen shows one list. Splitting it by type would mean the page
     * had to know which of four routes each row belonged to, and a row wired to the wrong one would
     * silently revoke nothing.
     */
    public function revoke(Request $request): RedirectResponse
    {
        $this->authorize('share', Document::class);

        $data = $request->validate([
            'type' => ['required', 'in:document_share,folder_grant,group,membership'],
            'uuid' => ['required', 'string'],
            'user_id' => ['nullable', 'integer'],
        ]);

        switch ($data['type']) {
            case 'document_share':
                $grant = DocumentGrant::where('uuid', $data['uuid'])->first();

                if ($grant) {
                    $this->shares->revoke($grant);
                }
                break;

            case 'folder_grant':
                $permission = DocumentFolderPermission::where('uuid', $data['uuid'])->first();

                if ($permission) {
                    $permission->delete();
                    // The resolver caches what it worked out; this same request re-renders the list.
                    FolderAccess::flush();
                }
                break;

            case 'group':
                /*
                 * Ended rather than deleted. The group is the record of who was let in and why, and
                 * destroying it would take the answer to "who had access last quarter" with it.
                 * Backdating the end date stops the access immediately and keeps the history.
                 */
                $group = DocumentGroup::where('uuid', $data['uuid'])->first();

                if ($group) {
                    $group->forceFill(['expires_at' => now()->subMinute()])->save();
                    FolderAccess::flush();
                }
                break;

            case 'membership':
                $group = DocumentGroup::where('uuid', explode(':', $data['uuid'])[0])->first();

                if ($group && $data['user_id']) {
                    $this->groups->removeMember($group, (int) $data['user_id']);
                }
                break;
        }

        return back()->with('success', __('document_management.access_map.revoked'));
    }

    private function name(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        $person = $user->person ?? null;

        return $person ? trim($person->first_name . ' ' . $person->surname) : $user->email;
    }
}
