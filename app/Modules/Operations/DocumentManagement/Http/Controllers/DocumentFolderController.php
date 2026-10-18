<?php

namespace App\Modules\Operations\DocumentManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolder;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolderPermission;
use App\Modules\Operations\DocumentManagement\Services\FolderAccess;
use App\Modules\Operations\DocumentManagement\Services\FolderAudit;
use App\Modules\Operations\DocumentManagement\Services\FolderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The filing structure: creating it, rearranging it, and saying who may use each part.
 *
 * The operations themselves live in FolderService, because every one of them has a way to go
 * quietly wrong — a folder moved inside itself, a rename that strands its descendants, an archive
 * that hides a parent and leaves its children reachable — and those belong in one place rather than
 * in whichever controller happened to need them.
 */
class DocumentFolderController extends Controller
{
    public function __construct(private readonly FolderService $folders)
    {
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manageFolders', Document::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'parent' => ['nullable', 'string'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_confidential' => ['nullable', 'boolean'],
            'download_disabled' => ['nullable', 'boolean'],
            'scope' => ['nullable', 'in:company,department'],
            'department_unit_id' => ['nullable', 'integer'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        return $this->attempt(function () use ($request, $data) {
            $this->folders->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'parent_id' => $data['parent'] ?? null,
                'is_confidential' => $request->boolean('is_confidential'),
                'download_disabled' => $request->boolean('download_disabled'),
                'scope' => $data['scope'] ?? null,
                'department_unit_id' => $data['department_unit_id'] ?? null,
                'sort_order' => $data['sort_order'] ?? 0,
            ]);

            return __('document_management.folders.created');
        });
    }

    public function update(Request $request, DocumentFolder $folder): RedirectResponse
    {
        $this->authorizeFolder($request, $folder);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_confidential' => ['nullable', 'boolean'],
            'download_disabled' => ['nullable', 'boolean'],
            'scope' => ['nullable', 'in:company,department'],
            'department_unit_id' => ['nullable', 'integer'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        return $this->attempt(function () use ($request, $folder, $data) {
            // Renaming goes through the service: it settles on a name no sibling is using and
            // rebuilds the paths beneath.
            if (array_key_exists('name', $data) && $data['name'] !== $folder->name) {
                $this->folders->rename($folder, $data['name']);
            }

            $folder->fill(array_filter([
                'description' => $data['description'] ?? null,
                'scope' => $data['scope'] ?? null,
                'department_unit_id' => $data['department_unit_id'] ?? null,
                'sort_order' => $data['sort_order'] ?? null,
            ], fn ($v) => $v !== null));

            if ($request->has('is_confidential')) {
                $folder->is_confidential = $request->boolean('is_confidential');
            }

            if ($request->has('download_disabled')) {
                $folder->download_disabled = $request->boolean('download_disabled');

                // Resolved access is cached per request and this changes it for everybody, so the
                // tree rendered after this save has to be rendered from the new answer.
                FolderAccess::flush();
            }

            $folder->save();

            return __('document_management.folders.updated');
        });
    }

    /** Re-parents a folder, or sends it to the root when no parent is given. */
    public function move(Request $request, DocumentFolder $folder): RedirectResponse
    {
        $this->authorize('manageFolders', Document::class);

        $data = $request->validate(['parent' => ['nullable', 'string']]);

        return $this->attempt(function () use ($folder, $data) {
            $this->folders->move($folder, $data['parent'] ?? null);

            return __('document_management.folders.moved');
        });
    }

    /** Duplicates a folder, its subfolders, its grants and the documents inside. */
    public function copy(Request $request, DocumentFolder $folder): RedirectResponse
    {
        $this->authorize('manageFolders', Document::class);

        $data = $request->validate([
            'parent' => ['nullable', 'string'],
            'name' => ['nullable', 'string', 'max:150'],
        ]);

        return $this->attempt(function () use ($folder, $data) {
            $copy = $this->folders->copy($folder, $data['parent'] ?? null, $data['name'] ?? null);

            return __('document_management.folders.copied', ['name' => $copy->name]);
        });
    }

    public function archive(DocumentFolder $folder): RedirectResponse
    {
        $this->authorize('manageFolders', Document::class);

        return $this->attempt(function () use ($folder) {
            $this->folders->archive($folder);

            return __('document_management.folders.archived');
        });
    }

    public function restore(DocumentFolder $folder): RedirectResponse
    {
        $this->authorize('manageFolders', Document::class);

        return $this->attempt(function () use ($folder) {
            $this->folders->restore($folder);

            return __('document_management.folders.restored');
        });
    }

    public function destroy(DocumentFolder $folder): RedirectResponse
    {
        $this->authorize('manageFolders', Document::class);

        return $this->attempt(function () use ($folder) {
            $this->folders->delete($folder);

            return __('document_management.folders.deleted');
        });
    }

    /**
     * Replaces the grants on a folder.
     *
     * Sent whole rather than one at a time, because that is how the screen works — somebody edits
     * the list of who may do what and saves it — and applying it as a set means a grant removed on
     * screen is actually removed rather than left behind.
     */
    public function permissions(Request $request, DocumentFolder $folder): RedirectResponse
    {
        /*
         * Either the module-wide permission, or being an administrator of this folder. That second
         * one is the whole point: the Finance manager decides who sees Finance, without also being
         * handed Human Resources and Legal.
         */
        $this->authorizeFolder($request, $folder);

        $data = $request->validate([
            'grants' => ['present', 'array'],
            // Groups included: until now a folder could only be given to a group from the group's
            // own screen, which meant somebody looking at a folder had no way to express "the
            // auditors" and reached for a list of names instead.
            'grants.*.subject_type' => ['required', 'in:role,user,department,group'],
            'grants.*.subject_id' => ['nullable', 'integer'],
            'grants.*.subject_name' => ['nullable', 'string', 'max:150'],
            'grants.*.can_view' => ['nullable', 'boolean'],
            'grants.*.can_download' => ['nullable', 'boolean'],
            'grants.*.can_upload' => ['nullable', 'boolean'],
            'grants.*.can_edit' => ['nullable', 'boolean'],
            'grants.*.can_delete' => ['nullable', 'boolean'],
            'grants.*.can_manage' => ['nullable', 'boolean'],
        ]);

        return $this->attempt(function () use ($folder, $data) {
            DB::transaction(function () use ($folder, $data) {
                /*
                 * What the folder allowed before this save, so the change can be recorded as a
                 * difference. Granting yourself access to Employee Files and then reading it is two
                 * acts, and until now only the second was written down.
                 */
                $before = DocumentFolderPermission::where('folder_id', $folder->id)
                    ->get(['subject_type', 'subject_id', 'subject_name', 'can_view', 'can_download', 'can_upload', 'can_edit', 'can_delete', 'can_manage'])
                    ->map(fn ($row) => $row->toArray())
                    ->all();

                // The screen now shows groups too, so what it posts is the whole picture and
                // rebuilding from it is honest. It was not, while groups were invisible here.
                DocumentFolderPermission::where('folder_id', $folder->id)->delete();

                foreach ($data['grants'] as $grant) {
                    // A role grant is matched by name, so the id is irrelevant and storing one
                    // would go stale the first time roles are re-seeded.
                    DocumentFolderPermission::create([
                        'folder_id' => $folder->id,
                        'subject_type' => $grant['subject_type'],
                        'subject_id' => $grant['subject_type'] === 'role' ? null : ($grant['subject_id'] ?? null),
                        'subject_name' => $grant['subject_name'] ?? null,
                        'can_view' => (bool) ($grant['can_view'] ?? true),
                        // Defaulted to whatever view says, so a grant saved from a screen that has
                        // not been told about downloading behaves as it did before there was a
                        // column for it.
                        'can_download' => (bool) ($grant['can_download'] ?? $grant['can_view'] ?? true),
                        'can_upload' => (bool) ($grant['can_upload'] ?? false),
                        'can_edit' => (bool) ($grant['can_edit'] ?? false),
                        'can_delete' => (bool) ($grant['can_delete'] ?? false),
                        'can_manage' => (bool) ($grant['can_manage'] ?? false),
                        'created_by' => Auth::id(),
                    ]);
                }

                $after = DocumentFolderPermission::where('folder_id', $folder->id)
                    ->get(['subject_type', 'subject_id', 'subject_name', 'can_view', 'can_download', 'can_upload', 'can_edit', 'can_delete', 'can_manage'])
                    ->map(fn ($row) => $row->toArray())
                    ->all();

                app(FolderAudit::class)->recordPermissionChange($folder, $before, $after);
            });

            // This same request re-renders the tree; without this it would render from the
            // resolver's cache, showing the administrator the state before their own save.
            FolderAccess::flush();

            return __('document_management.folders.permissions_saved');
        });
    }

    /**
     * Who may administer this particular folder.
     *
     * Deliberately narrower than the module permission it sits beside. A folder admin decides who
     * else may use their folder and nothing beyond it — moving it elsewhere in the tree or deleting
     * it stays with whoever administers the repository, because both change something outside the
     * folder itself.
     */
    private function authorizeFolder(Request $request, DocumentFolder $folder): void
    {
        if (app(FolderAccess::class)->can($request->user(), $folder, 'manage')) {
            return;
        }

        $this->authorize('manageFolders', Document::class);
    }

    /**
     * Who can currently do what in one folder, for the screen that edits it.
     *
     * Read straight off the folder rather than resolved per person: this is the list of rules, not
     * the list of people they add up to, and somebody editing it needs to see the rules.
     */
    public function permissionsFor(Request $request, DocumentFolder $folder): \Illuminate\Http\JsonResponse
    {
        $this->authorizeFolder($request, $folder);

        return response()->json([
            'folder' => ['uuid' => $folder->uuid, 'name' => $folder->name],
            'grants' => DocumentFolderPermission::query()
                ->where('folder_id', $folder->id)
                ->orderBy('subject_type')
                ->orderBy('subject_name')
                ->get()
                ->map(fn (DocumentFolderPermission $p) => [
                    'uuid' => $p->uuid,
                    'subject_type' => $p->subject_type,
                    'subject_id' => $p->subject_id,
                    'subject_name' => $p->subject_name,
                    'can_view' => (bool) $p->can_view,
                    'can_download' => (bool) $p->can_download,
                    'can_upload' => (bool) $p->can_upload,
                    'can_edit' => (bool) $p->can_edit,
                    'can_delete' => (bool) $p->can_delete,
                    'can_manage' => (bool) $p->can_manage,
                    'expires_at' => $p->expires_at?->toDateString(),
                ])
                ->values(),
        ]);
    }

    /**
     * Runs an operation, turning a refusal into a message on the page.
     *
     * The service throws when something would damage the structure, and those are all things a
     * person can act on — move it somewhere else, empty it first. A stack trace is not the right
     * answer to "you cannot put a folder inside itself".
     */
    private function attempt(callable $operation): RedirectResponse
    {
        try {
            return back()->with('success', $operation());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
