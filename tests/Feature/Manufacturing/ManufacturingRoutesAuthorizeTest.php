<?php

namespace Tests\Feature\Manufacturing;

use App\Http\Controllers\ModuleHubController;
use App\Modules\Operations\Manufacturing\Support\ManufacturingHubs;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;

/**
 * The safety net under an exemption.
 *
 * EnforceCrudPermissions exempts the "manufacturing." prefix, because the permission names it
 * would derive from route names exist nowhere in the catalogue. That is the right call -- and it
 * means the module's own authorization is the ONLY check left. A route added later without an
 * authorize() call would be both exempt and open to every authenticated, entitled user.
 *
 * "Never rely on hidden menu items as the only access control."
 */
class ManufacturingRoutesAuthorizeTest extends ManufacturingTestCase
{
    /** @return list<\Illuminate\Routing\Route> */
    private function moduleRoutes(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            fn ($r) => str_starts_with((string) $r->getName(), 'manufacturing.')
        ));
    }

    public function test_the_module_has_routes_and_each_is_gated_by_entitlement_and_tier(): void
    {
        $routes = $this->moduleRoutes();
        $this->assertGreaterThan(60, count($routes));

        foreach ($routes as $route) {
            $mw = $route->gatherMiddleware();

            $this->assertContains('module.active:manufacturing', $mw, "{$route->getName()} skips the entitlement gate.");
            $this->assertContains('module.tier:manufacturing', $mw, "{$route->getName()} skips the tier gate.");
        }
    }

    public function test_every_route_authorizes_through_a_policy_in_its_own_action(): void
    {
        $unguarded = [];

        foreach ($this->moduleRoutes() as $route) {
            [$class, $method] = explode('@', $route->getActionName());

            // The generic hub landing filters its cards by tier and by gate; every card's own
            // route is checked here. It lists navigation and returns no tenant record.
            if ($class === ModuleHubController::class) {
                continue;
            }

            $reflection = new ReflectionMethod($class, $method);
            $source = implode('', array_slice(
                file($reflection->getFileName()),
                $reflection->getStartLine() - 1,
                $reflection->getEndLine() - $reflection->getStartLine() + 1,
            ));

            // authorize(...) or a helper that does -- DashboardController asks userCan() for its
            // flags, but authorizes viewAny first, which is what this looks for.
            if (! preg_match('/\$this->authorize\(/', $source)) {
                $unguarded[] = $route->getName().'  ('.class_basename($class).'@'.$method.')';
            }
        }

        $this->assertSame([], $unguarded,
            "These manufacturing routes are exempt from the generic permission check and do not "
            ."authorize on their own:\n  ".implode("\n  ", $unguarded));
    }

    public function test_an_authenticated_user_with_no_grants_is_refused_everywhere_that_matters(): void
    {
        $refusedOnGet = [];
        $openOnWrite = [];

        foreach ($this->moduleRoutes() as $route) {
            [$class] = explode('@', $route->getActionName());

            if ($class === ModuleHubController::class || str_contains($route->uri(), '{')) {
                continue;
            }

            $methods = array_diff($route->methods(), ['HEAD']);

            if ($methods === ['GET']) {
                $status = $this->actingAs($this->outsider)->get('/'.$route->uri())->getStatusCode();

                if ($status !== 403) {
                    $refusedOnGet[] = "GET /{$route->uri()} answered {$status}";
                }
            } else {
                $status = $this->actingAs($this->outsider)->post('/'.$route->uri(), [])->getStatusCode();

                if (! in_array($status, [403, 405], true)) {
                    $openOnWrite[] = "POST /{$route->uri()} answered {$status}";
                }
            }
        }

        $this->assertSame([], array_merge($refusedOnGet, $openOnWrite));
    }

    public function test_each_hub_card_names_the_ability_its_own_page_authorizes(): void
    {
        $checked = 0;

        foreach (ManufacturingHubs::hubs() as $group => $hub) {
            foreach ($hub['cards'] as $card) {
                $route = collect(Route::getRoutes()->getRoutes())
                    ->first(fn ($r) => $r->uri() === $card['path'] && in_array('GET', $r->methods(), true));

                $this->assertNotNull($route, "The {$group} card /{$card['path']} has no GET route.");

                [$class, $method] = explode('@', $route->getActionName());
                $reflection = new ReflectionMethod($class, $method);
                $source = implode('', array_slice(
                    file($reflection->getFileName()),
                    $reflection->getStartLine() - 1,
                    $reflection->getEndLine() - $reflection->getStartLine() + 1,
                ));

                $this->assertNotEmpty($card['gate'], "The {$group} card {$card['key']} declares no gate.");

                preg_match("/\\\$this->authorize\(\s*'([a-zA-Z]+)'\s*,\s*([A-Za-z]+)::class/", $source, $m);
                $this->assertSame($m[1] ?? null, $card['gate']['ability'],
                    "{$card['key']}: the card offers a page that authorizes something else.");
                $this->assertSame($m[2] ?? null, class_basename($card['gate']['subject']), $card['key']);
                $checked++;
            }
        }

        $this->assertSame(53, $checked);
    }

    public function test_every_hub_group_has_a_landing_served_by_the_hub_controller(): void
    {
        foreach (ManufacturingHubs::hubs() as $group => $hub) {
            $route = collect(Route::getRoutes()->getRoutes())
                ->first(fn ($r) => $r->uri() === $hub['path'] && in_array('GET', $r->methods(), true));

            $this->assertNotNull($route, "The {$group} hub has no landing route.");
            $this->assertSame(ModuleHubController::class.'@show', $route->getActionName(),
                "/{$hub['path']} is answered by a feature page, so the {$group} hub never renders.");
            $this->assertSame($group, $route->defaults['group'] ?? null);
        }
    }

    public function test_hub_cards_follow_what_the_person_may_actually_open(): void
    {
        // Engineering now sits inside Planning & Engineering and Setup inside Reports, Costing & Setup,
        // so each check looks only at that group's cards within the hub that holds them.
        $hubOf = ['engineering' => 'planning', 'setup' => 'reports'];
        $cards = fn ($user, string $group) => array_values(array_filter(array_column(
            $this->actingAs($user)->get('/manufacturing/'.$hubOf[$group])->assertOk()->viewData('page')['props']['cards'],
            'path'
        ), fn ($p) => str_starts_with($p, '/manufacturing/'.$group.'/')));

        // A planner may read the engineering register and may not configure the plant.
        $this->assertContains('/manufacturing/engineering/boms', $cards($this->planner, 'engineering'));
        $this->assertSame([], $cards($this->planner, 'setup'),
            'The setup hub offered cards to somebody every setup page would refuse.');

        // A configurator is the other way round: sets up the plant, has no engineering grants.
        $this->assertCount(6, $cards($this->configurator, 'setup'));
        $this->assertSame([], $cards($this->configurator, 'engineering'));
    }

    public function test_every_hub_label_and_ribbon_resolves_in_english(): void
    {
        $en = json_decode(file_get_contents(resource_path('js/utils/locales/modules/en.json')), true)['manufacturing'];
        $has = function (string $key) use ($en): bool {
            $node = $en;
            foreach (explode('.', preg_replace('/^manufacturing\./', '', $key)) as $segment) {
                if (! is_array($node) || ! array_key_exists($segment, $node)) {
                    return false;
                }
                $node = $node[$segment];
            }

            return is_string($node) && $node !== '';
        };

        $missing = [];

        foreach (ManufacturingHubs::hubs() as $hub) {
            foreach ([$hub['title'], $hub['subtitle']] as $key) {
                $has($key) || $missing[] = $key;
            }
            foreach ($hub['cards'] as $card) {
                foreach ([$card['title'], $card['desc']] as $key) {
                    $has($key) || $missing[] = $key;
                }
            }
        }

        $this->assertSame([], $missing);

        // And the sidebar's third-level ribbons are mapped, or they render as raw English.
        $nav = file_get_contents(resource_path('js/utils/locales/nav.ts'));
        foreach (['manufacturing', 'manufacturing_engineering', 'manufacturing_production', 'manufacturing_quality', 'manufacturing_setup'] as $slug) {
            $this->assertStringContainsString("  {$slug}: 'manufacturing.hubs.", $nav, "{$slug} has no nav label.");
        }
    }
}
