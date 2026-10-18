<?php

namespace Tests\Feature\Manufacturing;

use App\Core\Role\Models\Role;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;
use Illuminate\Support\Facades\Route;

/**
 * End of programme: every Manufacturing screen that takes no parameter opens for somebody holding
 * every grant, and renders a page that exists in the front end. Screens that need a record (an
 * order, an inspection, a finding) are opened by the feature test that creates the record.
 */
class EveryPageRendersTest extends ManufacturingTestCase
{
    /** Routes that answer with JSON or a file rather than a page. */
    private const NOT_PAGES = ['manufacturing.workforce.labour.hours', 'manufacturing.fulfilment.customer-orders.quote',
        'manufacturing.floor.queue', 'manufacturing.floor.identify'];

    public function test_every_screen_opens_and_its_page_exists(): void
    {
        $this->openBooks();
        $role = Role::firstOrCreate(['name' => 'mfg test everything', 'guard_name' => 'web']);
        $role->syncPermissions(RoleCatalog::all());
        $user = $this->makeUser('everything@example.test', $role);
        $this->grantModuleTier($user);

        $opened = [];
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if (! $name || ! str_starts_with($name, 'manufacturing.') || ! in_array('GET', $route->methods(), true)
                || $route->parameterNames() !== [] || in_array($name, self::NOT_PAGES, true) || str_contains($route->uri(), '.csv')) {
                continue;
            }
            $response = $this->actingAs($user)->get(route($name));
            if ($response->headers->get('content-type') && str_contains($response->headers->get('content-type'), 'json')) {
                continue;
            }
            $this->assertSame(200, $response->status(), "{$name} did not open: ".$response->status());
            $component = $response->viewData('page')['component'] ?? null;
            $this->assertNotNull($component, "{$name} rendered no page");
            $this->assertFileExists(resource_path("js/{$component}.vue"), "{$name} renders {$component}, which does not exist");
            $opened[$name] = $component;
        }

        $this->assertGreaterThan(40, count($opened), 'Fewer screens opened than the module has: '.implode(', ', array_keys($opened)));
    }
}
