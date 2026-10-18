<?php

namespace App\Modules\Operations\DocumentManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/** A place in the repository. Folders nest, and a confidential folder marks what it holds. */
class DocumentFolder extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'document_folders';

    protected $guarded = ['id'];

    protected $casts = [
        'is_confidential' => 'boolean',
        'download_disabled' => 'boolean',
        'depth' => 'integer',
        'sort_order' => 'integer',
        'archived_at' => 'datetime',
    ];

    public const SCOPE_COMPANY = 'company';
    public const SCOPE_DEPARTMENT = 'department';

    protected static function booted(): void
    {
        static::creating(fn (self $f) => $f->uuid ??= (string) Str::uuid());

        // The materialised path is derived, never typed — otherwise a rename leaves every
        // descendant pointing at a folder name that no longer exists.
        static::saving(function (self $folder) {
            $parent = $folder->parent_id ? self::withoutGlobalScopes()->find($folder->parent_id) : null;
            $folder->depth = $parent ? $parent->depth + 1 : 0;
            $folder->path = trim(($parent->path ?? '') . '/' . $folder->name, '/');
        });

        /*
         * Descendants carry their ancestors' names in their own path, so a rename or a move that
         * only fixed this row would leave every folder beneath it describing a place that no longer
         * exists — and the confidentiality and permission lookups that walk the tree would still
         * work while the paths shown to people were quietly wrong.
         */
        static::saved(function (self $folder) {
            if ($folder->wasChanged(['name', 'parent_id', 'path'])) {
                $folder->rebuildDescendantPaths();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'folder_id');
    }

    /** Confidential by its own flag or by sitting under a confidential folder. */
    public function isConfidential(): bool
    {
        if ($this->is_confidential) {
            return true;
        }

        return $this->parent_id !== null && (bool) $this->parent?->isConfidential();
    }

    /**
     * Every folder that is confidential, directly or by inheritance.
     *
     * isConfidential() walks up a chain per folder, which is fine for one document and useless for
     * a query — a listing cannot ask a PHP method about ten thousand rows. This resolves the whole
     * tree once so a query can simply exclude the ids.
     *
     * @return list<int>
     */
    public static function confidentialIds(): array
    {
        $folders = static::query()->get(['id', 'parent_id', 'is_confidential'])->keyBy('id');
        $resolved = [];

        $isConfidential = function ($id) use (&$isConfidential, $folders, &$resolved) {
            if (array_key_exists($id, $resolved)) {
                return $resolved[$id];
            }

            $folder = $folders[$id] ?? null;
            if ($folder === null) {
                return $resolved[$id] = false;
            }

            // Marked here, or anywhere above. Recorded on the way back down so a deep tree costs
            // one pass rather than one per node.
            $resolved[$id] = (bool) $folder->is_confidential
                || ($folder->parent_id !== null && $isConfidential($folder->parent_id));

            return $resolved[$id];
        };

        return $folders->keys()->filter(fn ($id) => $isConfidential($id))->values()->all();
    }

    /** A department folder belongs to one business unit; a company folder belongs to everybody. */
    public function isDepartmental(): bool
    {
        return $this->scope === self::SCOPE_DEPARTMENT && $this->department_unit_id !== null;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Recomputes `path` and `depth` for everything beneath this folder.
     *
     * Walks a level at a time rather than recursing per row: a tree of any size is a handful of
     * queries this way, and saving each child triggers the same hook so the whole subtree settles
     * without this method knowing how deep it goes.
     */
    public function rebuildDescendantPaths(): void
    {
        $children = self::withoutGlobalScopes()->where('parent_id', $this->id)->get();

        foreach ($children as $child) {
            $path = trim($this->path . '/' . $child->name, '/');
            $depth = $this->depth + 1;

            if ($child->path === $path && $child->depth === $depth) {
                continue; // already correct — stop walking this branch
            }

            // Written directly rather than through save(), so the saving hook does not recompute
            // from a parent it would have to load again.
            self::withoutGlobalScopes()->where('id', $child->id)->update(['path' => $path, 'depth' => $depth]);

            $child->path = $path;
            $child->depth = $depth;
            $child->rebuildDescendantPaths();
        }
    }

    /**
     * This folder and everything under it.
     *
     * Matched on the materialised path rather than by walking parents, which is the reason the path
     * is maintained at all: one query answers "everything in Human Resources" however deep it goes.
     */
    public function scopeSubtreeOf($query, self $folder)
    {
        return $query->where(fn ($q) => $q->where('id', $folder->id)
            ->orWhere('path', 'like', $folder->path . '/%'));
    }

    /** @return list<int> this folder's id and every descendant's */
    public function subtreeIds(): array
    {
        return self::withoutGlobalScopes()
            ->where('company_id', $this->company_id)
            ->where(fn ($q) => $q->where('id', $this->id)->orWhere('path', 'like', $this->path . '/%'))
            ->pluck('id')->all();
    }

    /** The chain from the root down to this folder, for a breadcrumb. */
    public function ancestors(): array
    {
        $chain = [];
        $folder = $this->parent;

        while ($folder) {
            array_unshift($chain, $folder);
            $folder = $folder->parent;
        }

        return $chain;
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(DocumentFolderPermission::class, 'folder_id');
    }
}
