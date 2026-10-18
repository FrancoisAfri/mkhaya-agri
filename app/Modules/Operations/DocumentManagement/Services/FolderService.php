<?php

namespace App\Modules\Operations\DocumentManagement\Services;

use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolder;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolderPermission;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Building and rearranging the filing structure.
 *
 * Every operation here changes where things live, and the failure modes are all quiet ones — a
 * folder moved into itself, a rename that leaves descendants describing a place that no longer
 * exists, an archive that hides a parent while its children stay visible. Each is handled once,
 * here, rather than in whichever controller happened to need it.
 */
class FolderService
{
    /** How deep a structure may go. Deep enough for any real filing, shallow enough to browse. */
    private const MAX_DEPTH = 10;

    public function __construct(private readonly FolderAudit $audit) {}

    public function create(array $attributes): DocumentFolder
    {
        $parent = $this->resolveParent($attributes['parent_id'] ?? null);

        if ($parent && $parent->depth + 1 >= self::MAX_DEPTH) {
            throw new RuntimeException(__('document_management.folders.too_deep', ['max' => self::MAX_DEPTH]));
        }

        $name = $this->uniqueName(trim($attributes['name']), $parent?->id);

        $folder = DocumentFolder::create([
            'name' => $name,
            'description' => $attributes['description'] ?? null,
            'parent_id' => $parent?->id,
            'is_confidential' => (bool) ($attributes['is_confidential'] ?? false),
            /*
             * Not inherited from the parent here, because it does not need to be: the restriction
             * is read by walking the ancestors, so a subfolder of a read-only folder is already
             * covered. Copying the flag down would only create a second place it could be lifted.
             */
            'download_disabled' => (bool) ($attributes['download_disabled'] ?? false),
            // A subfolder inherits its parent's scope unless told otherwise: a folder inside a
            // department folder belonging to the whole company would be a surprise.
            'scope' => $attributes['scope'] ?? $parent?->scope ?? DocumentFolder::SCOPE_COMPANY,
            'department_unit_id' => $attributes['department_unit_id'] ?? $parent?->department_unit_id,
            'sort_order' => (int) ($attributes['sort_order'] ?? 0),
            'created_by' => Auth::id(),
        ]);

        $this->audit->record($folder, DocumentEvent::FOLDER_CREATED, [
            'name' => $folder->name,
            'parent' => $parent?->name,
            'confidential' => (bool) $folder->is_confidential,
        ]);

        return $folder;
    }

    public function rename(DocumentFolder $folder, string $name): DocumentFolder
    {
        $from = $folder->name;

        $folder->name = $this->uniqueName(trim($name), $folder->parent_id, $folder->id);
        $folder->save(); // the saved hook rebuilds every descendant's path

        if ($from !== $folder->name) {
            $this->audit->record($folder, DocumentEvent::FOLDER_RENAMED, ['from' => $from, 'to' => $folder->name]);
        }

        return $folder->refresh();
    }

    /**
     * Moves a folder under a new parent, or to the root when given null.
     *
     * The check that matters is the cycle: dragging a folder into its own descendant would detach
     * that whole branch from the tree, and because the paths still look plausible afterwards it is
     * the kind of thing nobody notices until a folder simply cannot be found.
     */
    public function move(DocumentFolder $folder, ?string $parentUuid): DocumentFolder
    {
        $parent = $this->resolveParent($parentUuid);

        if ($parent && $parent->id === $folder->id) {
            throw new RuntimeException(__('document_management.folders.move_into_self'));
        }

        if ($parent && in_array($parent->id, $folder->subtreeIds(), true)) {
            throw new RuntimeException(__('document_management.folders.move_into_descendant'));
        }

        $deepest = $this->deepestDescendantDepth($folder) - $folder->depth;

        if ($parent && $parent->depth + 1 + $deepest >= self::MAX_DEPTH) {
            throw new RuntimeException(__('document_management.folders.too_deep', ['max' => self::MAX_DEPTH]));
        }

        $from = $folder->parent?->name;

        $folder->parent_id = $parent?->id;
        $folder->name = $this->uniqueName($folder->name, $parent?->id, $folder->id);
        $folder->save();

        $this->audit->record($folder, DocumentEvent::FOLDER_MOVED, [
            'from' => $from,
            'to' => $parent?->name,
        ]);

        return $folder->refresh();
    }

    /**
     * Copies a folder, everything under it, and the documents inside.
     *
     * Documents are copied rather than shared: two folders pointing at one document would mean
     * editing the copy silently changed the original, and archiving one would take it out of the
     * other. Only the current version comes across — a copy is a new document, not a new claim to
     * somebody else's history.
     */
    public function copy(DocumentFolder $folder, ?string $parentUuid, ?string $name = null): DocumentFolder
    {
        $parent = $this->resolveParent($parentUuid);

        if ($parent && in_array($parent->id, $folder->subtreeIds(), true)) {
            throw new RuntimeException(__('document_management.folders.copy_into_descendant'));
        }

        $copy = DB::transaction(fn () => $this->copyBranch($folder, $parent?->id, $name));

        $this->audit->record($copy, DocumentEvent::FOLDER_COPIED, [
            'from' => $folder->name,
            'to' => $parent?->name,
        ]);

        return $copy;
    }

    private function copyBranch(DocumentFolder $source, ?int $parentId, ?string $name = null): DocumentFolder
    {
        $copy = DocumentFolder::create([
            'name' => $this->uniqueName($name ?: $source->name, $parentId),
            'description' => $source->description,
            'parent_id' => $parentId,
            'is_confidential' => $source->is_confidential,
            'download_disabled' => $source->download_disabled,
            'scope' => $source->scope,
            'department_unit_id' => $source->department_unit_id,
            'sort_order' => $source->sort_order,
            'created_by' => Auth::id(),
        ]);

        // The grants come too. A copy of a restricted folder that anyone could read would be a
        // quiet way around the restriction.
        foreach ($source->permissions as $grant) {
            DocumentFolderPermission::create([
                'folder_id' => $copy->id,
                'subject_type' => $grant->subject_type,
                'subject_id' => $grant->subject_id,
                'subject_name' => $grant->subject_name,
                'can_view' => $grant->can_view,
                'can_download' => $grant->can_download,
                'can_upload' => $grant->can_upload,
                'can_edit' => $grant->can_edit,
                'can_delete' => $grant->can_delete,
                'can_manage' => $grant->can_manage,
                'created_by' => Auth::id(),
            ]);
        }

        FolderAccess::flush();

        foreach ($source->documents()->whereNull('archived_at')->with('currentVersion')->get() as $document) {
            $this->copyDocument($document, $copy);
        }

        foreach (DocumentFolder::where('parent_id', $source->id)->whereNull('archived_at')->get() as $child) {
            $this->copyBranch($child, $copy->id);
        }

        return $copy;
    }

    /** One document into a copied folder, bytes and all. */
    private function copyDocument(Document $document, DocumentFolder $folder): void
    {
        $version = $document->currentVersion;

        if (! $version) {
            return;
        }

        $copy = Document::create([
            'folder_id' => $folder->id,
            'category_id' => $document->category_id,
            'title' => $document->title,
            'description' => $document->description,
            'notes' => $document->notes,
            'tags' => $document->tags,
            'status' => Document::STATUS_ACTIVE,
            'is_confidential' => $document->is_confidential,
            'is_legally_sensitive' => $document->is_legally_sensitive,
            'sensitivity_note' => $document->sensitivity_note,
            'version_count' => 1,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        app(DocumentService::class)->copyVersionInto($copy, $version);
    }

    /**
     * Archives a folder and everything inside it.
     *
     * Cascading is the point. Archiving a parent while its children stayed visible would leave
     * documents reachable through a folder that no longer appears anywhere — filed somewhere that,
     * as far as the tree is concerned, does not exist.
     */
    public function archive(DocumentFolder $folder): DocumentFolder
    {
        $ids = $folder->subtreeIds();

        DB::transaction(function () use ($ids) {
            DocumentFolder::whereIn('id', $ids)->update([
                'archived_at' => now(),
                'archived_by' => Auth::id(),
            ]);

            Document::whereIn('folder_id', $ids)->whereNull('archived_at')->update([
                'status' => Document::STATUS_ARCHIVED,
                'archived_at' => now(),
                'archived_by' => Auth::id(),
                'archive_reason' => __('document_management.folders.archived_with_folder'),
            ]);
        });

        $this->audit->record($folder, DocumentEvent::FOLDER_ARCHIVED, ['name' => $folder->name]);

        return $folder->refresh();
    }

    /**
     * Restores a folder and its subtree.
     *
     * A folder cannot come back under an archived parent — it would be visible in a place that is
     * not — so the ancestors are restored with it.
     */
    public function restore(DocumentFolder $folder): DocumentFolder
    {
        $ids = array_merge($folder->subtreeIds(), array_map(fn ($a) => $a->id, $folder->ancestors()));

        DB::transaction(function () use ($ids) {
            DocumentFolder::whereIn('id', $ids)->update(['archived_at' => null, 'archived_by' => null]);
        });

        $this->audit->record($folder, DocumentEvent::FOLDER_RESTORED, ['name' => $folder->name]);

        return $folder->refresh();
    }

    /**
     * Deletes a folder.
     *
     * Refused while anything is filed inside it, at any depth. Deleting a folder is a statement
     * about the structure; deleting what people filed in it is a different decision, and merging
     * the two means one careless click destroys both.
     */
    public function delete(DocumentFolder $folder): void
    {
        $ids = $folder->subtreeIds();
        $documents = Document::whereIn('folder_id', $ids)->count();

        if ($documents > 0) {
            throw new RuntimeException(__('document_management.folders.not_empty', ['count' => $documents]));
        }

        /*
         * Recorded before the rows go, and against the folder being removed. The event outlives the
         * folder on purpose — "who deleted Employee Files" is a question asked precisely because
         * the folder is no longer there to ask about.
         */
        $this->audit->record($folder, DocumentEvent::FOLDER_DELETED, [
            'name' => $folder->name,
            'path' => $folder->path ?? null,
        ]);

        DB::transaction(function () use ($ids) {
            DocumentFolderPermission::whereIn('folder_id', $ids)->delete();
            DocumentFolder::whereIn('id', $ids)->delete();
        });

        FolderAccess::flush();
    }

    /**
     * A name not already taken among its siblings.
     *
     * Two folders called "Contracts" in one place is a filing structure people stop trusting, and
     * the path — which is how the subtree queries work — would no longer identify one of them.
     */
    private function uniqueName(string $name, ?int $parentId, ?int $ignoreId = null): string
    {
        $name = Str::limit($name, 120, '');
        $candidate = $name;
        $suffix = 1;

        while (DocumentFolder::where('parent_id', $parentId)
            ->where('name', $candidate)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()) {
            $candidate = $name . ' (' . ++$suffix . ')';
        }

        return $candidate;
    }

    private function resolveParent(?string $uuid): ?DocumentFolder
    {
        return blank($uuid) ? null : DocumentFolder::where('uuid', $uuid)->firstOrFail();
    }

    private function deepestDescendantDepth(DocumentFolder $folder): int
    {
        return (int) DocumentFolder::whereIn('id', $folder->subtreeIds())->max('depth');
    }
}
