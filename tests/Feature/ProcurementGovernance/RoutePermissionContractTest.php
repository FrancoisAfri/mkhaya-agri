<?php

namespace Tests\Feature\ProcurementGovernance;

use App\Http\Middleware\EnforceCrudPermissions;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * §10: every route says which permission it needs, and every name it asks for actually exists.
 *
 * This exists because of a defect that no service-level test could have caught. EnforceCrudPermissions
 * derives a permission from the route name when a route does not name one — for `procgov.plans.index`
 * it asks for `procgov.plans.view`, plural, which this module never defines. The result was a 403 on
 * almost every screen for a user holding all thirty-one procurement permissions, while platform
 * admins, who bypass that middleware entirely, saw a module that worked perfectly. It was invisible
 * from an admin account and invisible from the database.
 *
 * So the contract is checked directly: no route may rely on the guess, and no route may ask for a
 * permission that does not exist. Both halves matter — a route asking for `procgov.plan.veiw` locks
 * everyone out just as effectively as no permission at all.
 */
class RoutePermissionContractTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return array<int, array{name: string, permissions: array<int, string>}>
     */
    private function procgovRoutes(): array
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with((string) $route->getName(), 'procgov.')) {
                continue;
            }

            $declared = [];

            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware)) {
                    continue;
                }

                // gatherMiddleware() hands back whatever was written on the route, so this is the
                // alias ('crud.permissions:…') here even though route:list prints the resolved
                // class name. Accept both so the test does not depend on which one it gets.
                if (preg_match('/(?:crud\.permissions|EnforceCrudPermissions):(.+)$/', $middleware, $matches)) {
                    foreach (preg_split('/[|,]/', $matches[1]) ?: [] as $permission) {
                        $permission = trim($permission);

                        if ($permission !== '') {
                            $declared[] = $permission;
                        }
                    }
                }
            }

            $routes[] = ['name' => (string) $route->getName(), 'permissions' => array_values(array_unique($declared))];
        }

        return $routes;
    }

    public function test_the_module_registers_its_routes(): void
    {
        // A guard on the two tests below: if the route file stopped loading they would both pass
        // over an empty list and prove nothing at all.
        $this->assertGreaterThan(80, count($this->procgovRoutes()));
    }

    /**
     * The one family of procgov routes that carries no permission, and why.
     *
     * A bidder is not a platform user. They hold one invitation token for one tender, and asking
     * what module a *tenant* has bought or what a *user* may do is meaningless for them. The
     * authorisation is the token itself, which is 64 random characters, unique across every tender,
     * and resolved before anything else is touched.
     *
     * Listed here rather than left to slip through the general rule, so that adding an unguarded
     * route to this module stays a deliberate act with a reason attached.
     */
    private const PUBLIC_PORTAL_PREFIX = 'procgov.portal.';

    public function test_every_route_names_the_permission_it_needs(): void
    {
        $unguarded = [];

        foreach ($this->procgovRoutes() as $route) {
            if (str_starts_with($route['name'], self::PUBLIC_PORTAL_PREFIX)) {
                continue;
            }

            if ($route['permissions'] === []) {
                $unguarded[] = $route['name'];
            }
        }

        $this->assertSame(
            [],
            $unguarded,
            'These routes fall back to a permission derived from their name, which this module does '
            .'not define — they will 403 for everyone except platform admins: '.implode(', ', $unguarded)
        );
    }

    public function test_the_public_portal_is_rate_limited_since_its_token_is_its_only_credential(): void
    {
        /*
         * The exemption above is only defensible with this in place.
         *
         * The token is the entire credential and what it protects is every competitor's sealed
         * price, so an unthrottled portal route is an offer to brute-force it. This asserts the
         * compensating control exists rather than trusting that whoever added the route remembered.
         */
        $portalRoutes = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with((string) $route->getName(), self::PUBLIC_PORTAL_PREFIX)) {
                continue;
            }

            $portalRoutes[] = (string) $route->getName();

            $throttled = collect($route->gatherMiddleware())
                ->filter(fn ($m) => is_string($m) && str_contains($m, 'throttle'))
                ->isNotEmpty();

            $this->assertTrue($throttled, "{$route->getName()} is public and unthrottled.");
        }

        $this->assertNotEmpty($portalRoutes, 'The bid portal routes are not registered at all.');
    }

    public function test_every_permission_a_route_asks_for_exists(): void
    {
        $existing = DB::table('permissions')->pluck('name')->all();

        $unknown = [];

        foreach ($this->procgovRoutes() as $route) {
            foreach ($route['permissions'] as $permission) {
                if (! in_array($permission, $existing, true)) {
                    $unknown[$permission][] = $route['name'];
                }
            }
        }

        $this->assertSame(
            [],
            $unknown,
            'These routes ask for permissions the seeder never creates: '.json_encode($unknown)
        );
    }

    public function test_an_explicit_permission_survives_an_exempt_route_prefix(): void
    {
        // The prefix exemption exists to stop the middleware guessing. If it also suppressed a
        // permission the route names outright, every procgov route would silently become open to
        // any authenticated user — a far worse bug than the 403 it was added to fix.
        $middleware = new EnforceCrudPermissions;

        $request = Request::create('/procurement-governance/settings', 'GET');
        $request->setRouteResolver(fn () => tap(
            new \Illuminate\Routing\Route(['GET'], '/procurement-governance/settings', []),
            fn ($route) => $route->name('procgov.settings.index')
        ));

        $user = new class
        {
            public bool $is_platform_admin = false;

            public bool $is_master_admin = false;

            public function hasRole(string $role): bool
            {
                return false;
            }

            public function hasPermissionTo(string $permission): bool
            {
                return false;
            }
        };

        $request->setUserResolver(fn () => $user);

        try {
            $middleware->handle($request, fn () => response('reached'), 'procgov.configure');

            $this->fail('An exempt prefix swallowed an explicit permission — the route was left open.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertStringContainsString('procgov.configure', $e->getMessage());
        }
    }
}
