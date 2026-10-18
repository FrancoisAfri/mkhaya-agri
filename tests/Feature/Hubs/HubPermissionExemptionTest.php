<?php

namespace Tests\Feature\Hubs;

use App\Http\Middleware\EnforceCrudPermissions;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Hub landings are gated by `module.active` and the controller's tier filtering — not by a
 * permission guessed from the route name. This middleware used to guess one anyway
 * ('hubs.supplier_management.directory'), a name that exists nowhere in the catalogue and that no
 * role can therefore hold, which left every hub landing reachable only by users who happened to
 * carry the generic 'view' permission.
 *
 * Locked down here rather than left to a browser check, because the failure is silent: it does not
 * break a page, it makes one unreachable for a subset of roles nobody thinks to test with.
 */
class HubPermissionExemptionTest extends TestCase
{
    /** Run a named route through the middleware with a user holding exactly $held. */
    private function passThrough(string $routeName, array $held, array $explicit = []): bool
    {
        $user = new class($held)
        {
            public bool $is_platform_admin = false;

            public bool $is_master_admin = false;

            public function __construct(private array $held) {}

            public function hasPermissionTo($permission): bool
            {
                return in_array($permission, $this->held, true);
            }

            public function hasRole($role): bool
            {
                return false;
            }
        };

        $route = (new RoutingRoute(['GET'], '/whatever', fn () => 'ok'))->name($routeName);

        $request = Request::create('/whatever', 'GET');
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => $user);

        // A denial aborts rather than returning a response, so reaching the closure is the signal.
        try {
            $response = (new EnforceCrudPermissions())
                ->handle($request, fn () => response('reached'), ...$explicit);
        } catch (HttpException) {
            return false;
        }

        return $response->getStatusCode() === 200;
    }

    public function test_a_hub_landing_does_not_demand_a_permission_derived_from_its_route_name(): void
    {
        // The exact shape that was broken: a user entitled to the module, holding module
        // permissions, but not the generic 'view' that 19 of 33 roles happen to carry.
        $this->assertTrue(
            $this->passThrough('hubs.supplier_management.directory', ['suppliers.view']),
            'A hub landing demanded a permission derived from its route name.',
        );
    }

    public function test_a_hub_landing_is_reachable_by_a_user_holding_no_permissions_at_all(): void
    {
        $this->assertTrue(
            $this->passThrough('hubs.budget_management.planning', []),
            'Hub landings are gated by module.active and tier, so the CRUD guard must not block them.',
        );
    }

    public function test_the_exemption_does_not_suppress_a_permission_named_on_the_route(): void
    {
        // Load-bearing: an exempt prefix must never drop an explicit crud.permissions:x, or adding
        // one to a hub route would silently open it to every authenticated user.
        $this->assertFalse(
            $this->passThrough('hubs.supplier_management.directory', ['suppliers.view'], ['suppliers.approve']),
            'An explicitly named permission was suppressed by the hubs. exemption.',
        );

        $this->assertTrue(
            $this->passThrough('hubs.supplier_management.directory', ['suppliers.approve'], ['suppliers.approve']),
            'An explicitly named permission was not honoured on an exempt route.',
        );
    }

    public function test_a_user_can_dismiss_their_own_notification(): void
    {
        // Marking a notification read used to demand 'notifications.read' — a permission that
        // exists nowhere — falling back to the generic 'create', which no role in the catalogue
        // holds. So every user who was not a platform admin got a 403, and it failed silently:
        // the bell posts and re-reads the list, so a refusal looks exactly like a notification
        // that will not go away.
        $this->assertTrue(
            $this->passThrough('notifications.read', []),
            'A user must be able to dismiss their own notification.',
        );

        $this->assertTrue(
            $this->passThrough('notifications.read-all', []),
            'A user must be able to clear their own notifications.',
        );
    }

    public function test_routes_outside_the_exemption_are_still_guarded(): void
    {
        // The exemption is a prefix match, so a route merely containing 'hubs.' must stay guarded.
        $this->assertFalse(
            $this->passThrough('suppliers.hubs.directory', []),
            'A non-hub route was exempted by a substring match.',
        );
    }
}
