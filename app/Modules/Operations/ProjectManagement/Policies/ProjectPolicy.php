<?php

namespace App\Modules\Operations\ProjectManagement\Policies;

use App\Core\User\Models\User;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Services\PermissionEvaluator;
use App\Modules\Operations\ProjectManagement\Support\RoleCatalog;

/**
 * Authorisation for projects, enforced at the service layer — never only in the UI.
 *
 * Every method delegates to PermissionEvaluator so there is exactly one place a PM permission
 * decision is made (PM-1020). That is what makes project-scoped access work: a user who is Project
 * Manager on one job and has no tenant-wide grant passes here for that project and fails for every
 * other, without a single controller knowing about it.
 *
 * Tenancy is *not* handled here: CompanyScope and row-level security make another tenant's project
 * unreachable, so a policy check on a cross-tenant id never gets a model to check.
 */
class ProjectPolicy
{
    public function __construct(private readonly PermissionEvaluator $evaluator)
    {
    }

    /** Platform/master admins and the top platform roles bypass the individual grants. */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->is_platform_admin || $user->is_master_admin) {
            return true;
        }

        if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['administrator', 'super admin'])) {
            return true;
        }

        return null;
    }

    /**
     * Opening the list. A project-scoped user passes here and then sees only their own projects —
     * the filtering is `Project::visibleTo()`, not a closed door (PM-1020).
     */
    public function viewAny(User $user): bool
    {
        return $this->evaluator->canAnywhere($user, RoleCatalog::VIEW_PROJECTS);
    }

    public function view(User $user, Project $project): bool
    {
        return $this->evaluator->can($user, RoleCatalog::VIEW_PROJECTS, $project);
    }

    public function create(User $user): bool
    {
        return $this->evaluator->can($user, RoleCatalog::CREATE_PROJECTS);
    }

    public function update(User $user, Project $project): bool
    {
        return $this->evaluator->can($user, RoleCatalog::UPDATE_PROJECTS, $project);
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->evaluator->can($user, RoleCatalog::DELETE_PROJECTS, $project);
    }

    public function restore(User $user, Project $project): bool
    {
        return $this->evaluator->can($user, RoleCatalog::DELETE_PROJECTS, $project);
    }

    /** Moving a project out of draft, or closing it out — a heavier grant than editing it. */
    public function changeStatus(User $user, Project $project): bool
    {
        return $this->evaluator->can($user, RoleCatalog::MANAGE_PROJECTS, $project);
    }

    /**
     * Certifying a payment certificate.
     *
     * The heaviest routine act in the module: certifying is the moment work becomes money the
     * company owes, so it sits on the same grant as running the project rather than on the one
     * that lets somebody edit a task.
     */
    public function certifyClaim(User $user, Project $project): bool
    {
        return $this->evaluator->can($user, RoleCatalog::MANAGE_PROJECTS, $project);
    }

    /** Adding or removing people on a project. */
    public function manageMembers(User $user, Project $project): bool
    {
        return $this->evaluator->can($user, RoleCatalog::MANAGE_MEMBERS, $project);
    }

    /** Internal cost, budget and margin — absent from Client and Contractor by design. */
    public function viewCosts(User $user, ?Project $project = null): bool
    {
        return $this->evaluator->can($user, RoleCatalog::VIEW_COSTS, $project);
    }

    /**
     * Module-level settings: working calendars and the integration surface. Not tied to a single
     * project, so it takes no model — hence a separate ability rather than reusing changeStatus.
     */
    public function manageSettings(User $user): bool
    {
        return $this->evaluator->can($user, RoleCatalog::MANAGE_SETTINGS);
    }
}
