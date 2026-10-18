<?php

namespace App\Core\Auth\Support;

use App\Core\User\Models\User;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Where a person belongs when they sign in.
 *
 * Login sent everyone to the dashboard, which assumes everyone with an account is staff. Project
 * clients and contractors are not: MemberInviteService creates their account with no role and no
 * module access at all, because "portal access is decided by project membership and the role, not
 * by anything on the user row".
 *
 * So a client accepting an invitation landed on the portal and could use it — that path signs them
 * in and redirects there directly — and then could never get back. The next ordinary login went to
 * the dashboard, EnforceCrudPermissions derived [dashboard, dashboard.view, view], they held none
 * of them, and the answer was a 403. Setting a password appeared to work and logging in appeared to
 * be broken, which is exactly how it was reported.
 *
 * ── Why not grant them the permission ───────────────────────────────────────
 *
 * Because the dashboard is the internal application and a client should not be standing in it. And
 * because `view` is generic: Spatie's Gate::before grants any ability matching a held permission,
 * so handing an outside party a bare `view` is not a small thing — EnforceCrudPermissions makes the
 * same argument about `delete` in its own comments.
 *
 * ── Why the decision is made here and not in the middleware ─────────────────
 *
 * Funnelling a 403 to the portal is what the middleware already does for resellers, and it is the
 * tempting fix. It cannot be done for portal users: EnsureModuleTier redirects its own failures to
 * the dashboard, so portal → tier refusal → dashboard → 403 → portal is an infinite loop for
 * anybody whose module access is not what we assumed. Deciding once, at the point of login, cannot
 * loop — the worst case is a 403 that stops.
 */
final class LandingPage
{
    /** Where to send this user after they authenticate. */
    public static function for(?User $user): string
    {
        if (! $user) {
            return route('dashboard');
        }

        // Staff, and anybody who can actually open the dashboard, carry on as before. Checked
        // against the same three names EnforceCrudPermissions derives for that route, so this
        // agrees with the guard rather than second-guessing it.
        if ($user->is_platform_admin || $user->is_master_admin) {
            return route('dashboard');
        }

        if (self::holdsAny($user, ['dashboard', 'dashboard.view', 'view'])) {
            return route('dashboard');
        }

        if (self::isPortalMember($user) && RouteFacade::has('project-management.portal')) {
            return route('project-management.portal');
        }

        // Unchanged for everyone else. Somebody who can open neither still lands where they always
        // did and sees the same refusal — this is here to route the people it knows about, not to
        // invent a home for people it does not.
        return route('dashboard');
    }

    /**
     * An outside party attached to a live project.
     *
     * Deliberately not "has a client membership": internal staff can be a client on a project, and
     * they belong on the dashboard. The dashboard check above runs first for exactly that reason —
     * only somebody who cannot open the dashboard at all is treated as portal-only.
     */
    private static function isPortalMember(User $user): bool
    {
        return ProjectMember::query()
            ->where('user_id', $user->id)
            ->active()
            ->whereIn('role_key', ['client', 'contractor'])
            ->exists();
    }

    /**
     * @param  list<string>  $permissions
     */
    private static function holdsAny(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            try {
                if ($user->hasPermissionTo($permission)) {
                    return true;
                }
            } catch (\Throwable) {
                // A permission that has never been created is not held by anybody. Spatie throws
                // rather than returning false, and a throw here would turn a redirect decision
                // into a failed login.
                continue;
            }
        }

        return false;
    }
}
