<?php

namespace Tests\Feature\Hubs;

use App\Support\Hubs\HubRegistry;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A hub whose landing page has no route 404s the moment someone clicks it in the nav, and a card
 * pointing at a dead path does the same. Both are easy to introduce by editing a ModuleHubs config
 * alone, so they are checked here rather than found in production.
 */
class HubRoutesTest extends TestCase
{
    public function test_every_hub_landing_has_a_route(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => in_array('GET', $r->methods(), true))
            ->map(fn ($r) => trim($r->uri(), '/'))
            ->all();

        foreach (HubRegistry::all() as $code => $hubs) {
            foreach ($hubs::hubs() as $group => $hub) {
                $this->assertContains(
                    trim($hub['path'], '/'),
                    $uris,
                    "[{$code}.{$group}] hub landing {$hub['path']} has no GET route.",
                );
            }
        }
    }

    public function test_generically_routed_modules_register_a_named_route_per_hub(): void
    {
        foreach (HubRegistry::GENERIC_HUB_ROUTES as $code) {
            $hubs = HubRegistry::for($code);
            $this->assertNotNull($hubs, "{$code} is listed for generic hub routes but is not registered.");

            foreach (array_keys($hubs::hubs()) as $group) {
                $this->assertTrue(
                    Route::has("hubs.{$code}.{$group}"),
                    "Route hubs.{$code}.{$group} is missing.",
                );
            }
        }
    }

    public function test_every_card_points_at_a_real_get_route(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => in_array('GET', $r->methods(), true))
            ->map(fn ($r) => trim($r->uri(), '/'))
            ->all();

        foreach (HubRegistry::all() as $code => $hubs) {
            foreach ($hubs::hubs() as $group => $hub) {
                foreach ($hub['cards'] as $card) {
                    $path = trim(explode('?', $card['path'], 2)[0], '/');
                    // A literal route, or a parameterised one that answers the path (not the 404 fallback).
                    $answered = in_array($path, $uris, true) || (function () use ($path) {
                        try {
                            return ! Route::getRoutes()->match(\Illuminate\Http\Request::create('/'.$path, 'GET'))->isFallback;
                        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                            return false;
                        }
                    })();
                    $this->assertTrue($answered, "[{$code}.{$group}] card {$card['key']} links to {$path}, which has no GET route.");
                }
            }
        }
    }
}
