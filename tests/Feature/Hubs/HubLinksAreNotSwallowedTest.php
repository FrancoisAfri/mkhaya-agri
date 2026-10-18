<?php

namespace Tests\Feature\Hubs;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every hub link in the sidebar must reach its own route.
 *
 * A module that registers `module/{uuid}` before its hub routes swallows the literal ones: the
 * wildcard matches first, the controller is handed "envelopes-hub" where it expected a uuid, and
 * Postgres refuses it. The symptom is a 500 on a navigation link, from a route that was registered
 * perfectly well and never reached — which is why it survives a reading of the route file.
 *
 * Found in E-Sign, then in Surveys by sweeping for the same shape. Both are fixed by constraining
 * the wildcard rather than by reordering, because ordering breaks again the next time somebody
 * appends a route. This is the check that says so.
 */
class HubLinksAreNotSwallowedTest extends TestCase
{
    public function test_every_hub_route_is_the_one_that_matches_its_own_path(): void
    {
        $swallowed = [];
        $checked = 0;

        foreach (Route::getRoutes()->getRoutesByName() as $name => $route) {
            if (! str_starts_with($name, 'hubs.') || str_contains($route->uri(), '{')) {
                continue;
            }

            $checked++;

            try {
                $matched = Route::getRoutes()->match(Request::create('/' . $route->uri(), 'GET'))->getName();
            } catch (\Throwable) {
                $matched = null;
            }

            if ($matched !== $name) {
                $swallowed[] = "/{$route->uri()} reaches " . ($matched ?? 'nothing') . ", not {$name}";
            }
        }

        $this->assertGreaterThan(0, $checked, 'No hub routes were found to check.');
        $this->assertSame([], $swallowed, "A wildcard route is swallowing a hub link:\n" . implode("\n", $swallowed));
    }
}
