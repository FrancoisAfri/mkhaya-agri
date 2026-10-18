<?php

namespace App\Modules\Operations\DocumentManagement\Http\Controllers;

use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolder;
use App\Modules\Operations\DocumentManagement\Models\DocumentGroup;
use App\Modules\Operations\DocumentManagement\Services\GroupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Groups, their members, and the folders they reach.
 *
 * Managing groups is folder management: what a group is for is being given access to folders, and
 * splitting the two permissions would mean somebody could assemble a group they could not then use.
 */
class DocumentGroupController extends Controller
{
    use \App\Modules\Operations\DocumentManagement\Concerns\PicksTenantSubjects;

    public function __construct(private readonly GroupService $groups)
    {
    }

    public function index(Request $request): Response
    {
        $this->authorize('manageFolders', Document::class);

        return Inertia::render('Modules/DocumentManagement/Pages/Groups', [
            'groups' => DocumentGroup::query()
                ->with('members.person')
                ->orderBy('name')
                ->get()
                ->map(fn (DocumentGroup $group) => [
                    'uuid' => $group->uuid,
                    'name' => $group->name,
                    'description' => $group->description,
                    'expires_at' => $group->expires_at?->toFormattedDateString(),
                    'expired' => $group->hasExpired(),
                    'members' => $group->members->map(fn ($m) => [
                        'id' => $m->id,
                        'name' => $this->name($m),
                        'expires_at' => $m->pivot->expires_at
                            ? \Illuminate\Support\Carbon::parse($m->pivot->expires_at)->toFormattedDateString()
                            : null,
                    ])->values(),
                    'folders' => $this->groups->foldersFor($group)->map(fn ($p) => [
                        'uuid' => $p->folder?->uuid,
                        'name' => $p->folder?->name,
                        'can' => [
                            'view' => (bool) $p->can_view,
                            'download' => (bool) $p->can_download,
                            'upload' => (bool) $p->can_upload,
                            'edit' => (bool) $p->can_edit,
                            'delete' => (bool) $p->can_delete,
                        ],
                        'expires_at' => $p->expires_at?->toFormattedDateString(),
                    ])->filter(fn ($f) => $f['uuid'] !== null)->values(),
                ]),

            'folders' => DocumentFolder::query()->whereNull('archived_at')->orderBy('path')
                ->get()->map(fn (DocumentFolder $f) => [
                    'uuid' => $f->uuid, 'name' => $f->name, 'depth' => $f->depth,
                ]),

            'people' => $this->tenantPeople(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manageFolders', Document::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $this->groups->create($data['name'], $data);

        return back()->with('success', __('document_management.groups.created'));
    }

    public function update(Request $request, DocumentGroup $group): RedirectResponse
    {
        $this->authorize('manageFolders', Document::class);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date'],
        ]);

        if (isset($data['name'])) {
            $group->name = trim($data['name']);
        }

        if ($request->has('description')) {
            $group->description = $data['description'] ?? null;
        }

        // Cleared as well as set: an engagement that was extended should not keep the date it was
        // originally going to end on.
        if ($request->has('expires_at')) {
            $group->expires_at = filled($data['expires_at'] ?? null) ? $data['expires_at'] : null;
        }

        $group->save();

        return back()->with('success', __('document_management.groups.updated'));
    }

    public function destroy(DocumentGroup $group): RedirectResponse
    {
        $this->authorize('manageFolders', Document::class);

        // The folder permissions go with it. A group deleted while its grants survived would leave
        // access nobody can see the source of, which is the worst kind to have.
        \App\Modules\Operations\DocumentManagement\Models\DocumentFolderPermission::query()
            ->where('subject_type', \App\Modules\Operations\DocumentManagement\Models\DocumentFolderPermission::SUBJECT_GROUP)
            ->where('subject_id', $group->id)
            ->delete();

        \Illuminate\Support\Facades\DB::table('document_group_members')->where('group_id', $group->id)->delete();

        $group->delete();

        return back()->with('success', __('document_management.groups.deleted'));
    }

    public function addMembers(Request $request, DocumentGroup $group): RedirectResponse
    {
        $this->authorize('manageFolders', Document::class);

        $data = $request->validate([
            'users' => ['required', 'array', 'min:1'],
            'users.*' => ['integer'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $added = $this->groups->addMembers($group, $data['users'], $data['expires_at'] ?? null);

        return back()->with('success', __('document_management.groups.members_added', ['count' => $added]));
    }

    public function removeMember(Request $request, DocumentGroup $group, int $user): RedirectResponse
    {
        $this->authorize('manageFolders', Document::class);

        $this->groups->removeMember($group, $user);

        return back()->with('success', __('document_management.groups.member_removed'));
    }

    /** §8: one group, several folders, one decision. */
    public function grantFolders(Request $request, DocumentGroup $group): RedirectResponse
    {
        $this->authorize('manageFolders', Document::class);

        $data = $request->validate([
            'folders' => ['required', 'array', 'min:1'],
            'folders.*' => ['string'],
            'view' => ['nullable', 'boolean'],
            'download' => ['nullable', 'boolean'],
            'upload' => ['nullable', 'boolean'],
            'edit' => ['nullable', 'boolean'],
            'delete' => ['nullable', 'boolean'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $count = $this->groups->grantFolders($group, $data['folders'], [
            'view' => $request->boolean('view', true),
            'download' => $request->boolean('download', true),
            'upload' => $request->boolean('upload'),
            'edit' => $request->boolean('edit'),
            'delete' => $request->boolean('delete'),
        ], $data['expires_at'] ?? null);

        return back()->with('success', __('document_management.groups.folders_granted', ['count' => $count]));
    }

    public function revokeFolder(Request $request, DocumentGroup $group, string $folderUuid): RedirectResponse
    {
        $this->authorize('manageFolders', Document::class);

        $folder = DocumentFolder::where('uuid', $folderUuid)->firstOrFail();

        $this->groups->revokeFolder($group, $folder);

        return back()->with('success', __('document_management.groups.folder_revoked'));
    }

    private function name(?object $user): ?string
    {
        if (! $user) {
            return null;
        }

        $person = $user->person ?? null;

        return $person ? trim($person->first_name . ' ' . $person->surname) : $user->email;
    }
}
