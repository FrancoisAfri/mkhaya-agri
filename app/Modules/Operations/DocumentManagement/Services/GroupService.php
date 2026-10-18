<?php

namespace App\Modules\Operations\DocumentManagement\Services;

use App\Modules\Operations\DocumentManagement\Models\DocumentFolder;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolderPermission;
use App\Modules\Operations\DocumentManagement\Models\DocumentGroup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Groups, and giving one access to several folders at once.
 *
 * §8 is the reason this exists as a service rather than a form: "give the external auditors view
 * access to these four folders until the end of September" is one decision, and making somebody
 * express it as four separate permission edits is how three of them get set correctly and the
 * fourth gets forgotten.
 */
class GroupService
{
    public function __construct(private readonly FolderAudit $audit) {}

    /** @param array{description?: ?string, expires_at?: ?string} $attributes */
    public function create(string $name, array $attributes = []): DocumentGroup
    {
        return DocumentGroup::create([
            'uuid' => (string) Str::uuid(),
            'name' => trim($name),
            'description' => $attributes['description'] ?? null,
            'expires_at' => filled($attributes['expires_at'] ?? null) ? Carbon::parse($attributes['expires_at']) : null,
            'created_by' => Auth::id(),
        ]);
    }

    /**
     * Puts people in the group.
     *
     * Adds rather than replaces. A membership edit that replaced would silently remove whoever the
     * person editing did not happen to have on screen, which on a group of thirty is a permission
     * change nobody made deliberately.
     *
     * @param list<int> $userIds
     */
    public function addMembers(DocumentGroup $group, array $userIds, ?string $expiresAt = null): int
    {
        $added = 0;

        foreach (array_unique($userIds) as $userId) {
            $existing = DB::table('document_group_members')
                ->where('group_id', $group->id)
                ->where('user_id', $userId)
                ->exists();

            if ($existing) {
                continue;
            }

            DB::table('document_group_members')->insert([
                'company_id' => $group->company_id,
                'group_id' => $group->id,
                'user_id' => $userId,
                'expires_at' => filled($expiresAt) ? Carbon::parse($expiresAt) : null,
                'added_by' => Auth::id(),
                'created_at' => Carbon::now(),
            ]);

            $added++;
        }

        if ($added > 0) {
            FolderAccess::flush();
        }

        return $added;
    }

    public function removeMember(DocumentGroup $group, int $userId): void
    {
        DB::table('document_group_members')
            ->where('group_id', $group->id)
            ->where('user_id', $userId)
            ->delete();

        FolderAccess::flush();
    }

    /**
     * Gives a group access to several folders in one act.
     *
     * The abilities are the same across all of them, because that is what makes this one decision
     * rather than several — a screen that let each folder differ would be the per-folder editor
     * with extra steps.
     *
     * @param list<string> $folderUuids
     * @param array{view?: bool, upload?: bool, edit?: bool, delete?: bool} $abilities
     * @return int how many folders were granted
     */
    public function grantFolders(DocumentGroup $group, array $folderUuids, array $abilities, ?string $expiresAt = null): int
    {
        $folders = DocumentFolder::whereIn('uuid', $folderUuids)->get();

        $expires = filled($expiresAt) ? Carbon::parse($expiresAt) : null;

        foreach ($folders as $folder) {
            $before = DocumentFolderPermission::where('folder_id', $folder->id)
                ->get(['subject_type', 'subject_id', 'subject_name', 'can_view', 'can_download', 'can_upload', 'can_edit', 'can_delete'])
                ->map(fn ($row) => $row->toArray())
                ->all();

            DocumentFolderPermission::updateOrCreate(
                [
                    'folder_id' => $folder->id,
                    'subject_type' => DocumentFolderPermission::SUBJECT_GROUP,
                    'subject_id' => $group->id,
                ],
                [
                    'uuid' => (string) Str::uuid(),
                    'subject_name' => $group->name,
                    'can_view' => (bool) ($abilities['view'] ?? true),
                    'can_download' => (bool) ($abilities['download'] ?? $abilities['view'] ?? true),
                    'can_upload' => (bool) ($abilities['upload'] ?? false),
                    'can_edit' => (bool) ($abilities['edit'] ?? false),
                    'can_delete' => (bool) ($abilities['delete'] ?? false),
                    'expires_at' => $expires,
                    'created_by' => Auth::id(),
                ],
            );

            $after = DocumentFolderPermission::where('folder_id', $folder->id)
                ->get(['subject_type', 'subject_id', 'subject_name', 'can_view', 'can_download', 'can_upload', 'can_edit', 'can_delete'])
                ->map(fn ($row) => $row->toArray())
                ->all();

            // Recorded per folder, because that is where somebody will look for it — "who gave the
            // auditors this folder" is asked about one folder at a time.
            $this->audit->recordPermissionChange($folder, $before, $after);
        }

        FolderAccess::flush();

        return $folders->count();
    }

    /** Takes a group's access to one folder away. */
    public function revokeFolder(DocumentGroup $group, DocumentFolder $folder): void
    {
        $before = DocumentFolderPermission::where('folder_id', $folder->id)
            ->get(['subject_type', 'subject_id', 'subject_name', 'can_view', 'can_download', 'can_upload', 'can_edit', 'can_delete'])
            ->map(fn ($row) => $row->toArray())
            ->all();

        DocumentFolderPermission::where('folder_id', $folder->id)
            ->where('subject_type', DocumentFolderPermission::SUBJECT_GROUP)
            ->where('subject_id', $group->id)
            ->delete();

        $after = DocumentFolderPermission::where('folder_id', $folder->id)
            ->get(['subject_type', 'subject_id', 'subject_name', 'can_view', 'can_download', 'can_upload', 'can_edit', 'can_delete'])
            ->map(fn ($row) => $row->toArray())
            ->all();

        $this->audit->recordPermissionChange($folder, $before, $after);

        FolderAccess::flush();
    }

    /** Every folder this group can reach, and on what terms. */
    public function foldersFor(DocumentGroup $group)
    {
        return DocumentFolderPermission::query()
            ->where('subject_type', DocumentFolderPermission::SUBJECT_GROUP)
            ->where('subject_id', $group->id)
            ->with('folder')
            ->get();
    }
}
