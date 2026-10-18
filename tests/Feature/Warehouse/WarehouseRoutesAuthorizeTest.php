<?php

namespace Tests\Feature\Warehouse;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * The module is exempt from the platform's guessed CRUD permissions because it authorises every
 * action itself. This holds it to that: an authenticated person with no warehouse permission gets
 * a 403 or a 404 from every route, never a page or a successful write.
 */
class WarehouseRoutesAuthorizeTest extends WarehouseTestCase
{
    public function test_every_route_refuses_a_person_without_warehouse_permissions(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => Str::startsWith((string) $r->getName(), 'warehouse.'));

        $this->assertGreaterThan(10, $routes->count());

        $open = [];
        foreach ($routes as $route) {
            $uri = preg_replace('/\{[^}]+\}/', (string) Str::uuid(), $route->uri());
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $status = $this->actingAs($this->outsider)->call($method, '/'.$uri)->getStatusCode();
                // Hub landings filter their cards rather than refusing the page; an empty hub is not a leak.
                if (! in_array($status, [403, 404], true) && ! Str::startsWith($route->getName(), 'warehouse.hub.')) {
                    $open[] = "{$method} /{$route->uri()} answered {$status}";
                }
            }
        }

        $this->assertSame([], $open, implode("\n", $open));
    }
}
