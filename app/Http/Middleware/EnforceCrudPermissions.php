<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Symfony\Component\HttpFoundation\Response;

class EnforceCrudPermissions
{
    /**
     * Routes that should bypass CRUD permission checks.
     * These are auth/session endpoints that should always remain reachable.
     */
    private const EXEMPT_ROUTE_NAMES = [
        'auth.logout',
        'lock.session',
        'login.unlock',
        'lock.screen',
        'auth.password.change',
        'auth.password.update',
        // Personal per-user preferences — any authenticated user may save their own.
        'settings.theme',
        'settings.theme.update',
        /*
         * Choosing which of the tenant's businesses you are working in.
         *
         * A POST, so the derived permission is the bare 'create' — which says "may create finance
         * records" and has nothing to do with this. Somebody with read-only access to Accounting
         * still has to be able to look at the other business's books, and refusing the switch
         * would leave them permanently inside whichever one they happen to have selected.
         *
         * Nothing is written but the selection itself, and CurrentEntity::set() refuses any entity
         * that is not this tenant's, so the only thing this exemption opens is the tenant's own
         * list of businesses.
         */
        'finance.accounting.entities.switch',
        // Dismissing your own notification is the same category, and was not treated as one. The
        // derived names are 'notifications.read' — which exists nowhere — and the generic 'create',
        // which no role in the catalogue holds, so marking a notification read returned 403 for
        // every user who was not a platform admin. It failed silently: the bell posts and re-reads
        // the list, so a refused request looks exactly like a notification that will not go away.
        'notifications.read',
        'notifications.read-all',
    ];

    /**
     * Route name prefixes whose routes are already protected by role middleware
     * and do not need an additional CRUD permission check.
     */
    private const EXEMPT_ROUTE_PREFIXES = [
        // A category's Insights page shows only the hub cards the person's tier and gates allow, and
        // its schedules are checked against each report's own permission and the owner; there is no
        // 'insights.category.view' permission to hold.
        'insights.category',
        // The attendance engine's screens ask for a T&A permission or the farm permission a farm role
        // carries, in every action (HR\Hr\Attendance\Http\Controllers\EngineController::allow); the
        // names this middleware would derive ('attendance.people.view', …) exist in neither set.
        'attendance.people.', 'attendance.crews.', 'attendance.time-settings', 'attendance.roster.', 'attendance.timesheets.',
        'attendance.badge-kiosk', 'attendance.piecework.', 'attendance.payroll-handoff.',
        'hr.leave.setup.',
        'hr.leave.admin-cancel',
        'hr.leave.payouts',
        'hr.leave.my-payouts',
        'hr.leave.manual-allocations.send-bulk-otp',
        // Agent-to-agent collaboration — available to any Help Desk user (already
        // gated by module access + per-thread participant checks in the controller).
        'helpdesk.agent-chat.',
        'helpdesk.agent-groups.',
        // Project Management authorises every route through a policy (ProjectPolicy /
        // TaskPolicy / EvidencePolicy) on top of module.active + module.tier, using its own
        // pm.* permission set. The generic names this middleware derives ('view', 'create',
        // 'delete', …) would have to be granted alongside them — and a bare 'delete'
        // permission is actively dangerous, because Spatie's Gate::before grants any ability
        // matching a held permission and would bypass those policies entirely.
        'project-management.',
        // Procurement Governance declares the permission it wants on every route
        // (crud.permissions:procgov.plan.view, …) using its own §10 permission set. The names this
        // middleware would otherwise derive are plural ('procgov.plans.view') and do not exist, so
        // a user holding all thirty-one procurement permissions still got a 403 on almost every
        // screen — only platform admins, who bypass this class entirely, could use the module.
        // Those explicit declarations are still enforced; this entry only suppresses the guess.
        'procgov.',
        // SHEQ is the same shape as the two above: it declares its own permission set
        // (sheq.incident.report, sheq.permit.approve, sheq.configure, …), checks it explicitly in
        // every controller action with abort_unless, and sits behind module.active + module.tier.
        // The names this middleware derives are plural and action-shaped ('sheq.forms.index',
        // 'sheq.forms.view') and exist nowhere in the catalogue, so every screen in the module
        // returned 403 to everyone except platform admins, who bypass this class. The module's own
        // checks are untouched by this entry — it only suppresses the guess.
        'sheq.',
        // Hub landing pages are registered generically in routes/modules/hubs.php, whose own
        // comment states the intent: "Each landing is gated by `module.active` only. ModuleHubController
        // already filters the cards to the user's access tier, and every card's own route keeps
        // whatever guard it has today." This middleware overrode that intent by deriving
        // 'hubs.supplier_management.directory' from the route name — a permission that exists
        // nowhere in the catalogue and that no role can therefore hold, so every hub landing was
        // reachable only by platform admins, who bypass this class. A hub landing lists cards the
        // viewer's tier already permits and links to routes that each keep their own guard, so
        // there is nothing here for a derived permission to protect.
        'hubs.',
        // Document Management is the same shape as Project Management, Procurement Governance and
        // SHEQ above: it declares its own permission set (document.view, document.upload,
        // document.purge, document.legal_hold, document.retention.manage, …), enforces it through
        // DocumentPolicy on every action, and sits behind module.active + module.tier. The names
        // derived from its route names are 'document-management.view' and
        // 'document-management.categories.update', neither of which exists in the catalogue — so
        // every screen in the module returned 403 to everyone except platform admins, who bypass
        // this class. Its own policy checks are untouched by this entry; it only suppresses the
        // guess.
        'document-management.',
        // Manufacturing & Production is the same shape again: its own mfg.* permission set
        // (mfg.bom.approve, mfg.configure, mfg.item.manage, ...), enforced by ItemPolicy, BomPolicy
        // and FactoryPolicyRules through authorize() in every controller action, behind
        // module.active + module.tier. The derived names ('manufacturing.engineering.boms.index',
        // 'manufacturing.setup.factories.store') exist nowhere in the catalogue, so without this
        // every screen returned 403 to everyone except platform admins. A bare derived 'delete' or
        // 'update' would be worse than useless here: Spatie's Gate::before grants any ability that
        // matches a held permission, which would bypass the policies that carry the real rules --
        // the separation of drafter and approver among them. ManufacturingRoutesAuthorizeTest
        // asserts every route in the module authorizes on its own, so this exemption cannot
        // quietly leave one open.
        'manufacturing.',
        // Warehouse Management & Intelligence: the same shape. Its wms.* permissions are enforced
        // by WarehousePolicy through authorize() in every action, behind module.active +
        // module.tier; WarehouseRoutesAuthorizeTest holds every route in the module to that.
        'warehouse.',
        // Tax Management & Compliance: the same shape. Its tax.* permissions (tax.prepare,
        // tax.approve, tax.reconcile.sign_off, …) are checked by TaxController::allow() at the top
        // of every action, behind module.active + module.tier, and the services enforce
        // segregation of duties on top. TaxAccessAndComplianceTest holds every route to refusing a
        // user without tax permissions, so this exemption cannot quietly leave one open.
        'tax.',
        // Workflow & Automation: the same shape. Its workflow.* permissions are checked by
        // WorkflowController::allow() in every action (and task actions by assignment), behind
        // module.active + module.tier; WorkflowAccessTest holds every route to refusing a user
        // without workflow permissions.
        'workflow.',
        // Mkhaya Agri: the same shape. Its agri.* permissions are checked by each controller's
        // allow() at the top of every action, behind module.active + module.tier, and farm-owned
        // models are confined to the farms the user may reach; AgriRoutesAuthorizeTest holds every
        // agri route to refusing a user without agri permissions.
        'agri.',
        // Organisations & members (company_memberships): switching is open to any signed-in person
        // among their own memberships, and adding/changing members is checked by
        // OrganisationController against the organisation's administrator roles. The derived
        // names ('organisations.switch', 'create') exist nowhere. OrganisationsTest holds every
        // route to its own check.
        'organisations.',
    ];

    /**
     * Route name segments commonly used as CRUD-ish actions.
     */
    private const ACTION_SEGMENTS = [
        'index',
        'show',
        'create',
        'store',
        'edit',
        'update',
        'destroy',
        'delete',
    ];

    public function handle(Request $request, Closure $next, string ...$explicitPermissions): Response
    {
        $routeName = $request->route()?->getName();

        // The exemption lists exist because the permission name this middleware *derives* from a
        // route name is a guess, and a wrong guess locks people out of a module they are entitled
        // to. A permission named on the route itself is not a guess — it is the module stating its
        // own rule — so an exempt prefix must never suppress it. Otherwise adding
        // 'crud.permissions:procgov.plan.view' to an exempt route would silently drop the check
        // and open the route to every authenticated user.
        if ($explicitPermissions === []) {
            if ($routeName !== null && in_array($routeName, self::EXEMPT_ROUTE_NAMES, true)) {
                return $next($request);
            }

            if ($routeName !== null) {
                foreach (self::EXEMPT_ROUTE_PREFIXES as $prefix) {
                    if (str_starts_with($routeName, $prefix)) {
                        return $next($request);
                    }
                }
            }
        }

        $user = $request->user();

        if (! $user) {
            return $this->deny($request, 'Unauthenticated.', 401);
        }

        // Platform-level admins bypass all module CRUD gates.
        if ($user->is_platform_admin || $user->is_master_admin) {
            return $next($request);
        }

        // Resellers are platform-level actors with their own portal and no tenant CRUD
        // permissions. Let their portal routes through (the 'reseller' middleware guards
        // them) and funnel every other route to the portal instead of a 403 they can
        // never satisfy. Auth/session routes are already exempted above, so logging out
        // still works.
        if ($user->hasRole('reseller')) {
            if ($routeName !== null && str_starts_with($routeName, 'reseller.')) {
                return $next($request);
            }

            if ($request->expectsJson()) {
                return $this->deny($request, 'Resellers use the reseller portal.', 403);
            }

            return redirect()->route('reseller.dashboard');
        }

        $candidatePermissions = $this->resolveCandidatePermissions(
            request: $request,
            routeName: $routeName,
            explicitPermissions: $explicitPermissions
        );

        if ($candidatePermissions === []) {
            return $next($request);
        }

        if (! $this->userHasAnyPermission($user, $candidatePermissions)) {
            $expected = implode(', ', $candidatePermissions);

            return $this->deny(
                $request,
                "You do not have permission to perform this action. Expected one of: [{$expected}]",
                403
            );
        }

        return $next($request);
    }

    private function resolveCandidatePermissions(
        Request $request,
        ?string $routeName,
        array $explicitPermissions
    ): array {
        // Route-level override: ->middleware('crud.permissions:policies.approve')
        // Supports pipe/comma separated values for "any of" checks.
        if ($explicitPermissions !== []) {
            return $this->normalizePermissions($explicitPermissions);
        }

        $action = $this->actionFromMethod($request->method());
        if ($action === null) {
            return [];
        }

        $permissions = [];

        // Most specific check: exact route name can be used as a permission key.
        if ($routeName) {
            $permissions[] = $routeName;

            $base = $this->baseResourceFromRouteName($routeName);
            if ($base !== null) {
                $permissions[] = $base.'.'.$action;
            }
        }

        // Generic action permission (dynamic role/permission setup friendly).
        $permissions[] = $action;

        // Backward compatibility with legacy 3-permission model.
        if (in_array($action, ['create', 'update'], true)) {
            $permissions[] = 'modify';
        }

        // Backward compatibility for installations using 'update' as writable permission.
        if ($action === 'create') {
            $permissions[] = 'update';
        }

        return $this->normalizePermissions($permissions);
    }

    private function actionFromMethod(string $method): ?string
    {
        return match (strtoupper($method)) {
            'GET', 'HEAD', 'OPTIONS' => 'view',
            'POST' => 'create',
            'PUT', 'PATCH' => 'update',
            'DELETE' => 'delete',
            default => null,
        };
    }

    private function baseResourceFromRouteName(string $routeName): ?string
    {
        $parts = explode('.', $routeName);
        $last = end($parts);

        if ($last === false) {
            return null;
        }

        if (in_array($last, self::ACTION_SEGMENTS, true)) {
            array_pop($parts);
            if ($parts === []) {
                return null;
            }

            return implode('.', $parts);
        }

        return $routeName;
    }

    private function normalizePermissions(array $permissions): array
    {
        $flattened = [];

        foreach ($permissions as $permission) {
            foreach (preg_split('/[|,]/', (string) $permission) ?: [] as $item) {
                $item = trim($item);
                if ($item !== '') {
                    $flattened[] = $item;
                }
            }
        }

        return array_values(array_unique($flattened));
    }

    private function userHasAnyPermission($user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            try {
                if ($user->hasPermissionTo($permission)) {
                    return true;
                }
            } catch (PermissionDoesNotExist) {
                // Dynamic candidates may not exist yet; continue to next fallback.
            }
        }

        return false;
    }

    private function deny(Request $request, string $message, int $status): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], $status);
        }

        abort($status, $message);
    }
}
