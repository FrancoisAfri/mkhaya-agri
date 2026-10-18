<?php

namespace Tests\Feature\DocumentManagement;

use App\Modules\Operations\DocumentManagement\Support\DocumentHubs;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every hub landing must be the screen that lists its cards.
 *
 * They were registered as stubs answering with the document library, so all four ribbons in the
 * navigation led to the same page and not one of the module's twenty-two cards was reachable
 * through it. Nothing caught it: every card path existed, every route resolved, and the only thing
 * missing was the screen that lists them — which no route check can see.
 *
 * Only a browser found it. This is the cheap standing guard so it cannot come back.
 */
class HubLandingsTest extends TestCase
{
    public function test_every_hub_landing_is_handled_by_the_hub_controller(): void
    {
        $wrong = [];

        foreach (array_keys(DocumentHubs::hubs()) as $group) {
            $route = collect(Route::getRoutes())->first(
                fn ($r) => $r->getName() === 'document-management.' . $group,
            );

            if (! $route) {
                $wrong[] = "{$group}: no route";

                continue;
            }

            $action = $route->getActionName();

            if (! str_contains($action, 'ModuleHubController')) {
                $wrong[] = "{$group}: handled by {$action}";
            }
        }

        $this->assertSame([], $wrong, "These hub landings do not render their cards:\n  " . implode("\n  ", $wrong));
    }

    public function test_every_hub_landing_names_the_group_whose_cards_it_should_show(): void
    {
        // The controller reads the group from the route's defaults. Without it the landing renders
        // an empty hub, which looks like a module with no features rather than a wiring mistake.
        foreach (array_keys(DocumentHubs::hubs()) as $group) {
            $route = collect(Route::getRoutes())->first(
                fn ($r) => $r->getName() === 'document-management.' . $group,
            );

            $this->assertNotNull($route);
            $this->assertSame(DocumentHubs::code(), $route->defaults['module'] ?? null);
            $this->assertSame($group, $route->defaults['group'] ?? null);
        }
    }
}
