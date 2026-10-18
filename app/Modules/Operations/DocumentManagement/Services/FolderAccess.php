<?php

namespace App\Modules\Operations\DocumentManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolder;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolderPermission;
use Illuminate\Support\Facades\DB;

/**
 * What a person may do in a folder.
 *
 * Two rules decide it, and the order matters.
 *
 * Grants inherit downwards. A rule on Human Resources covers Employee Files beneath it, because
 * that is what someone means when they set it — nobody expects to repeat the same grant at every
 * level, and a subfolder created later would otherwise be silently unreachable. The nearest folder
 * with a rule wins, so a subfolder can be opened up or closed off without touching its parent.
 *
 * Departmental folders are closed by default. A folder belonging to Finance is for Finance; anyone
 * else needs a grant saying otherwise. Company folders are the opposite — open unless something
 * says they are not — because that is what "company-wide" means.
 *
 * None of this replaces the module's permissions. It narrows them: someone who cannot upload at
 * all does not gain the ability by being granted it on a folder, and the policy is still asked
 * first. This answers "and may they do it *here*".
 */
class FolderAccess
{
    /** @var array<string, array<string,bool>> resolved abilities, per folder per user */
    private array $cache = [];

    /**
     * Bumped whenever a permission changes anywhere.
     *
     * Resolving a folder walks its ancestors and runs a query per level, so the same tree rendered
     * for one person would otherwise be recomputed for every row. Caching that is safe within a
     * request — except in the one request that changes the permissions itself, which is exactly the
     * request that re-renders the tree afterwards. Without this, an administrator saves a grant and
     * is shown the state from before their own save, which reads as the save having failed.
     *
     * Static because the cache is per-instance and the writer is somewhere else entirely; a counter
     * every instance consults is what lets one service invalidate another's work.
     */
    private static int $generation = 0;

    /** @var int the generation this instance's cache was built against */
    private int $builtAt = 0;

    /** Called after anything that could change who may do what. */
    public static function flush(): void
    {
        self::$generation++;
    }

    /**
     * @return array{view:bool,download:bool,upload:bool,edit:bool,delete:bool,manage:bool}
     */
    public function abilities(?User $user, ?DocumentFolder $folder): array
    {
        // Documents outside any folder are governed by the module's permissions alone; there is no
        // folder to ask about.
        if ($folder === null || $user === null) {
            return $this->all(true);
        }

        // Platform administrators have no company, so the departmental reasoning below has nothing
        // to reason about — the same reason the policy answers them first.
        if ($user->is_platform_admin || $user->is_master_admin) {
            return array_merge($this->all(true), ['manage' => true]);
        }

        // Somebody who administers folders for the whole company administers this one too. Without
        // this, granting folder admins would have taken the ability away from the people who
        // already had it.
        //
        // array_merge rather than `+`: the union operator keeps the left operand's key, so it would
        // have quietly kept the `manage => false` that all() sets and this line would have done
        // nothing at all.
        if ($user->can('document.folder.manage')) {
            return array_merge($this->all(true), ['manage' => true]);
        }

        if ($this->builtAt !== self::$generation) {
            $this->cache = [];
            $this->builtAt = self::$generation;
        }

        $key = $user->id . ':' . $folder->id;

        return $this->cache[$key] ??= $this->resolve($user, $folder);
    }

    public function can(?User $user, ?DocumentFolder $folder, string $ability): bool
    {
        return $this->abilities($user, $folder)[$ability] ?? false;
    }

    /** @return array{view:bool,download:bool,upload:bool,edit:bool,delete:bool,manage:bool} */
    private function resolve(User $user, DocumentFolder $folder): array
    {
        $chain = array_merge($folder->ancestors(), [$folder]);

        // Nearest first: the closest folder carrying a rule for this person is the one that decides.
        $resolved = null;

        foreach (array_reverse($chain) as $candidate) {
            $grants = $this->grantsFor($user, $candidate);

            if ($grants !== []) {
                $resolved = $this->merge($grants);
                break;
            }
        }

        $resolved ??= $this->default($user, $folder);

        // A read-only marking is a property of the folder rather than of a grant, so it applies
        // after them: a grant saying "download" cannot undo a folder that says copies do not leave
        // it. The exception is made per document, by someone who may make it, not per folder.
        if ($this->downloadsBlocked($folder)) {
            $resolved['download'] = false;
        }

        return $resolved;
    }

    /**
     * Every grant on one folder that names this person — by role, by name, or by department.
     *
     * @return list<array<string,bool>>
     */
    private function grantsFor(User $user, DocumentFolder $folder): array
    {
        $roles = $user->getRoleNames()->all();
        $departmentId = $this->departmentOf($user);

        $groupIds = $this->groupsOf($user);

        return DocumentFolderPermission::where('folder_id', $folder->id)
            /*
             * A permission with a date on it stops applying by itself.
             *
             * §9's whole point: an access somebody has to remember to revoke is one that outlives
             * its reason — the auditor who still has the Finance folder two years later got it
             * that way.
             */
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(function ($query) use ($user, $roles, $departmentId, $groupIds) {
                $query->where(fn ($q) => $q->where('subject_type', DocumentFolderPermission::SUBJECT_USER)
                    ->where('subject_id', $user->id));

                if ($roles !== []) {
                    $query->orWhere(fn ($q) => $q->where('subject_type', DocumentFolderPermission::SUBJECT_ROLE)
                        ->whereIn('subject_name', $roles));
                }

                if ($departmentId) {
                    $query->orWhere(fn ($q) => $q->where('subject_type', DocumentFolderPermission::SUBJECT_DEPARTMENT)
                        ->where('subject_id', $departmentId));
                }

                if ($groupIds !== []) {
                    $query->orWhere(fn ($q) => $q->where('subject_type', DocumentFolderPermission::SUBJECT_GROUP)
                        ->whereIn('subject_id', $groupIds));
                }
            })
            ->get()
            ->map(fn (DocumentFolderPermission $p) => $p->abilities())
            ->all();
    }

    /**
     * What applies when nothing has been said about this folder or any above it.
     *
     * A company folder is readable by anyone the module already lets in — that is what company-wide
     * means, and requiring a grant for it would mean setting up permissions before the repository
     * is usable at all. A department folder is not: it belongs to that department, and being able
     * to open the library is not the same as being in Finance.
     */
    private function default(User $user, DocumentFolder $folder): array
    {
        if (! $folder->isDepartmental()) {
            return $this->all(true);
        }

        return $this->departmentOf($user) === (int) $folder->department_unit_id
            ? $this->all(true)
            : $this->all(false);
    }

    /**
     * Several grants can apply at once — a person's role and their department, say.
     *
     * The most permissive wins. Two grants are two people saying yes, and the alternative would
     * mean adding somebody to a team could take away access they already had.
     *
     * @param  list<array<string,bool>>  $grants
     */
    /**
     * The effective permission where somebody is covered by several grants.
     *
     * The union: most permissive wins. Somebody in Employees (view), Finance (edit) and Management
     * (download) gets all three, which is §32's rule and the one people expect — a person does not
     * lose an ability by being added to another group.
     *
     * Note what this does not do: a grant never takes an ability away. Denial is expressed by not
     * granting, because a deny that overrides a grant makes effective permissions impossible for
     * anybody to predict from looking at them.
     *
     * @param array<int, array<string, bool>> $grants
     */
    private function merge(array $grants): array
    {
        $merged = $this->all(false);

        foreach ($grants as $grant) {
            foreach ($grant as $ability => $allowed) {
                $merged[$ability] = $merged[$ability] || $allowed;
            }
        }

        return $merged;
    }

    /**
     * The groups this person is currently in.
     *
     * Both dates matter and both are checked here: a membership can lapse on its own — a secondee,
     * somebody covering leave — and the group itself can lapse when the engagement it was made for
     * ends. Either one ending should end the access, and checking only one of them is how somebody
     * keeps access through a group that finished last quarter.
     *
     * @return list<int>
     */
    private function groupsOf(User $user): array
    {
        return DB::table('document_group_members as m')
            ->join('document_groups as g', 'g.id', '=', 'm.group_id')
            ->where('m.user_id', $user->id)
            ->where(fn ($q) => $q->whereNull('m.expires_at')->orWhere('m.expires_at', '>', now()))
            ->where(fn ($q) => $q->whereNull('g.expires_at')->orWhere('g.expires_at', '>', now()))
            ->pluck('g.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The business unit someone belongs to.
     *
     * Read from the unit pivot, which is where departments live on this platform — not from the
     * legacy division columns, which are a denormalised copy and go stale.
     */
    private function departmentOf(User $user): ?int
    {
        /*
         * `company_unit_user_pivots.company_id` holds a *company unit* id despite its name, and the
         * pivot joins on the user rather than the person. Both are worth stating: the column reads
         * as a tenant id, so joining it to companies looks right and returns nothing.
         */
        $unitId = DB::table('company_unit_user_pivots as p')
            ->join('company_units as u', 'u.id', '=', 'p.company_id')
            ->where('p.user_id', $user->id)
            ->where('u.type', 'department')
            ->value('u.id');

        return $unitId === null ? null : (int) $unitId;
    }

    /**
     * @return array{view:bool,download:bool,upload:bool,edit:bool,delete:bool,manage:bool}
     */
    private function all(bool $value): array
    {
        return [
            'view' => $value, 'download' => $value, 'upload' => $value,
            'edit' => $value, 'delete' => $value,
            /*
             * Never included in a blanket answer. A company folder is readable by everybody the
             * module lets in, and if that default extended to administering it then everybody would
             * be an administrator of it — which is the opposite of what a folder admin is for.
             * Only an explicit grant, or the module-wide permission, confers this.
             */
            'manage' => false,
        ];
    }

    /**
     * Whether anything above this folder, or the folder itself, is marked read-only.
     *
     * Inherited downwards and restrictively: marking Human Resources read-only has to cover
     * Employee Files beneath it, or the marking means nothing the moment somebody makes a
     * subfolder. Unlike a grant, the nearest rule does not win here — a subfolder cannot quietly
     * undo a restriction placed above it.
     */
    public function downloadsBlocked(?DocumentFolder $folder): bool
    {
        if ($folder === null) {
            return false;
        }

        foreach (array_merge($folder->ancestors(), [$folder]) as $candidate) {
            if ($candidate->download_disabled) {
                return true;
            }
        }

        return false;
    }

    /**
     * Folders this person cannot see into, for narrowing a listing in one query.
     *
     * Asking per document would mean a permission lookup for every row on the page, and the answer
     * would be identical for all the documents sharing a folder. This resolves it once.
     *
     * Only folders that actually restrict anyone are considered — departmental ones and those
     * carrying a grant — so an installation that has not set up any permissions does no extra work.
     *
     * @return list<int>
     */
    public function closedFolderIds(?User $user): array
    {
        if ($user === null || $user->is_platform_admin || $user->is_master_admin) {
            return [];
        }

        $restricted = DocumentFolder::query()
            ->where(fn ($q) => $q->where('scope', DocumentFolder::SCOPE_DEPARTMENT)
                ->orWhereIn('id', DocumentFolderPermission::select('folder_id')))
            ->get();

        return $restricted
            ->reject(fn (DocumentFolder $folder) => $this->can($user, $folder, 'view'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
