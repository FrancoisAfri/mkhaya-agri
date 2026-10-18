<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Operations\ProjectManagement\Models\PermissionOverride;
use App\Modules\Operations\ProjectManagement\Models\PmRole;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Support\RoleCatalog;

/**
 * The single place a Project Management permission decision is made (PM-1020).
 *
 * The rule, in order:
 *   1. Platform and master admins pass.
 *   2. An explicit **deny** — for this project, or globally — refuses, whatever else grants it.
 *   3. An explicit **allow** grants.
 *   4. Otherwise: the union of the user's global grant and their role on this project. Most
 *      permissive wins (PM-1023), which is why it is a union and not a precedence chain.
 *
 * Global grants come from Spatie, which is the coarse "this person is a project manager here"
 * layer. Project grants come from pm_project_members → pm_roles. Neither is consulted through a
 * bare ability name: Spatie registers a `Gate::before` that grants any ability whose *name* matches
 * a held permission, so a check spelled `can('delete')` would bypass every policy in the module.
 * Every permission in this module is namespaced `pm.*` for that reason.
 *
 * Results are memoised per request. Authorisation is asked dozens of times while rendering a list,
 * and none of the inputs can change mid-request.
 */
class PermissionEvaluator
{
    /** @var array<string,bool> */
    private array $decisions = [];

    /** @var array<int, array<int,string>> user id => [project id => role key] */
    private array $memberships = [];

    /** @var array<string, list<string>> "company:roleKey" => permissions */
    private array $rolePermissions = [];

    /**
     * May this user do this, on this project?
     *
     * $project omitted means "anywhere" — answerable only from a global grant, because a
     * project-scoped role by definition says nothing about projects the user is not on.
     */
    public function can(User $user, string $permission, ?Project $project = null): bool
    {
        $cacheKey = $user->id . '|' . $permission . '|' . ($project?->id ?? 'global');

        return $this->decisions[$cacheKey] ??= $this->decide($user, $permission, $project);
    }

    private function decide(User $user, string $permission, ?Project $project): bool
    {
        if ($user->is_platform_admin || ($user->is_master_admin ?? false)) {
            return true;
        }

        $override = $this->overrideFor($user, $permission, $project);
        if ($override !== null) {
            return $override === PermissionOverride::ALLOW;
        }

        if ($this->hasGlobalGrant($user, $permission)) {
            return true;
        }

        return $project !== null && $this->hasProjectGrant($user, $permission, $project);
    }

    /**
     * May this user do this *somewhere* — tenant-wide, or on at least one project?
     *
     * This is the question a list endpoint asks, and it is deliberately weaker than `can()` with no
     * project. Someone who is Project Manager on one job holds no tenant-wide grant, but refusing
     * them the project list entirely would be wrong: they should reach it and see their own work.
     * What they then see is limited by `visibleProjectIds()`, so "cannot list projects outside
     * their scope" is enforced by the query rather than by the door (PM-1020).
     */
    public function canAnywhere(User $user, string $permission): bool
    {
        if ($this->can($user, $permission)) {
            return true;
        }

        foreach ($this->membershipsFor($user) as $projectId => $roleKey) {
            if (in_array($permission, $this->permissionsForRole(null, $roleKey), true)) {
                return true;
            }
        }

        // An explicit allow on a single project is also enough to open the list.
        return PermissionOverride::query()
            ->where('user_id', $user->id)
            ->where('permission', $permission)
            ->where('effect', PermissionOverride::ALLOW)
            ->exists();
    }

    /**
     * Every permission this user effectively holds — globally, or on one project.
     *
     * Used by the UI so a client can render the right actions in one round trip instead of asking
     * about each one.
     *
     * @return list<string>
     */
    public function permissionsFor(User $user, ?Project $project = null): array
    {
        return array_values(array_filter(
            RoleCatalog::ALL,
            fn (string $permission) => $this->can($user, $permission, $project)
        ));
    }

    /**
     * The projects this user may see, or null when they may see all of them.
     *
     * Null rather than "every id" deliberately: a tenant with 5,000 projects should not have every
     * id enumerated into a WHERE IN clause just to say "no restriction".
     *
     * @return list<int>|null
     */
    public function visibleProjectIds(User $user): ?array
    {
        if ($user->is_platform_admin || ($user->is_master_admin ?? false)) {
            return null;
        }

        if ($this->hasGlobalGrant($user, RoleCatalog::VIEW_PROJECTS)) {
            return null;
        }

        // Project-scoped access only: they see the projects they are on, and nothing else — the
        // acceptance criterion in PM-1020 that must hold through the API as well as the UI.
        $ids = array_keys($this->membershipsFor($user));

        $allowed = PermissionOverride::query()
            ->where('user_id', $user->id)
            ->where('permission', RoleCatalog::VIEW_PROJECTS)
            ->where('effect', PermissionOverride::ALLOW)
            ->whereNotNull('project_id')
            ->pluck('project_id')
            ->all();

        $denied = PermissionOverride::query()
            ->where('user_id', $user->id)
            ->where('permission', RoleCatalog::VIEW_PROJECTS)
            ->where('effect', PermissionOverride::DENY)
            ->whereNotNull('project_id')
            ->pluck('project_id')
            ->all();

        return array_values(array_diff(array_unique(array_merge($ids, $allowed)), $denied));
    }

    /** The user's role on a project, or null if they are not a member. */
    public function roleOn(User $user, Project $project): ?string
    {
        return $this->membershipsFor($user)[$project->id] ?? null;
    }

    /**
     * Is this viewer external (Client or Contractor) on this project?
     *
     * Drives cost redaction in the presenter: external parties never see internal money, and that
     * has to be decided where the payload is built, not where a tab is hidden (PM-1021).
     */
    public function isExternalOn(User $user, ?Project $project): bool
    {
        if ($project === null) {
            return false;
        }

        return RoleCatalog::isExternal($this->roleOn($user, $project));
    }

    /**
     * Is this person only ever an outside party — a subcontractor or a client, never staff?
     *
     * Membership alone is not enough: a project manager can also be listed on a job, and treating
     * them as external would take their own module away. So both halves must hold — an active
     * external membership, and none of this module's permissions. `pm.projects.view` is the test
     * because it is the lowest rung: anybody who works here at all holds it, and nobody outside
     * the business does.
     *
     * Used to decide where somebody lands after login. The company dashboard carries headcount,
     * leave, assets and the CEO's message; a third party who invoices this company should not be
     * shown the shape of it, and should land on the one screen that is theirs.
     */
    public function isExternalPartyOnly(?User $user): bool
    {
        if ($user === null || ($user->is_platform_admin ?? false)) {
            return false;
        }

        if ($user->can(RoleCatalog::VIEW_PROJECTS)) {
            return false;
        }

        return ProjectMember::query()
            ->withoutGlobalScope('company')
            ->where('user_id', $user->id)
            ->active()
            ->whereIn('role_key', RoleCatalog::EXTERNAL_ROLES)
            ->exists();
    }

    /** Forget memoised decisions — for tests, and after a role or membership change. */
    public function flush(): void
    {
        $this->decisions = [];
        $this->memberships = [];
        $this->rolePermissions = [];
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** allow | deny | null. A project-specific override beats a global one for that project. */
    private function overrideFor(User $user, string $permission, ?Project $project): ?string
    {
        $overrides = PermissionOverride::query()
            ->where('user_id', $user->id)
            ->where('permission', $permission)
            ->when(
                $project !== null,
                fn ($q) => $q->where(fn ($w) => $w->whereNull('project_id')->orWhere('project_id', $project->id)),
                fn ($q) => $q->whereNull('project_id')
            )
            ->get();

        if ($overrides->isEmpty()) {
            return null;
        }

        // Deny always wins, at either scope. Anything else would make a deny rule advisory.
        if ($overrides->contains('effect', PermissionOverride::DENY)) {
            return PermissionOverride::DENY;
        }

        return PermissionOverride::ALLOW;
    }

    /**
     * The coarse, tenant-wide grant, from Spatie. Safe to ask through `can()` because every PM
     * permission is namespaced: an unregistered name returns false rather than throwing, and no
     * `pm.*` string collides with a bare ability that Gate::before would short-circuit.
     */
    private function hasGlobalGrant(User $user, string $permission): bool
    {
        return $user->can($permission);
    }

    private function hasProjectGrant(User $user, string $permission, Project $project): bool
    {
        $roleKey = $this->roleOn($user, $project);

        if ($roleKey === null) {
            return false;
        }

        return in_array($permission, $this->permissionsForRole($project->company_id, $roleKey), true);
    }

    /** @return array<int,string> project id => role key */
    private function membershipsFor(User $user): array
    {
        return $this->memberships[$user->id] ??= ProjectMember::query()
            ->active()
            ->where('user_id', $user->id)
            ->pluck('role_key', 'project_id')
            ->all();
    }

    /** @return list<string> */
    private function permissionsForRole(?int $companyId, string $roleKey): array
    {
        $cacheKey = ($companyId ?? 'null') . ':' . $roleKey;

        if (isset($this->rolePermissions[$cacheKey])) {
            return $this->rolePermissions[$cacheKey];
        }

        $role = PmRole::query()->where('key', $roleKey)->first();

        // Fall back to the catalogue when a tenant has not been seeded yet, so a fresh tenant is
        // not silently permission-less between provisioning and the seeder running.
        $permissions = $role?->permissions
            ?? RoleCatalog::builtIn()[$roleKey]['permissions']
            ?? [];

        return $this->rolePermissions[$cacheKey] = $permissions;
    }
}
