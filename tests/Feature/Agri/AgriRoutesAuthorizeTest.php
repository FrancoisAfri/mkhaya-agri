<?php

namespace Tests\Feature\Agri;

use Illuminate\Support\Facades\Route;

/**
 * Every agri route authorizes on its own.
 *
 * The agri.* prefix is exempt from the generic CRUD-permission middleware (it would derive names
 * like "agri.farms.store" that exist nowhere), so this sweep is what stops that exemption from
 * quietly leaving a route open: somebody in the organisation with no agri permission must be
 * refused by every one of them.
 */
class AgriRoutesAuthorizeTest extends AgriTestCase
{
    public function test_every_agri_route_refuses_a_user_without_agri_permissions(): void
    {
        $farm = $this->farm('NORTH');

        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with((string) $r->getName(), 'agri.'));

        $this->assertGreaterThan(70, $routes->count(), 'Every module of the specification has routes.');

        foreach ($routes as $route) {
            $params = [];
            foreach ($route->parameterNames() as $name) {
                $params[$name] = match ($name) {
                    'farm' => $farm->uuid,
                    'kind' => 'crop_types',
                    'report' => 'yield',
                    default => '00000000-0000-0000-0000-000000000000',
                };
            }
            $method = collect($route->methods())->first(fn ($m) => $m !== 'HEAD');
            $response = $this->actingAs($this->outsider)->call($method, route($route->getName(), $params));

            $this->assertContains($response->status(), [403, 404],
                "{$method} {$route->uri()} ({$route->getName()}) answered {$response->status()} to a user without agri permissions.");
        }
    }
}
