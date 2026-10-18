<?php

namespace Tests\Feature\SupplyChain;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The joins between the layers of the Supply Chain modules, which nothing else can see.
 *
 * Every layer of this category tests well on its own, and that is exactly the problem. Three budget
 * features were built end to end — controller, model, service, engine, dialog — and had never once
 * run, because the route they were named after did not exist. Ziggy throws on an unknown name, so
 * pressing Save failed in the browser before a request was sent: nothing in a log, nothing in a
 * test, and `budget_approval_delegations` with no rows since the day it was created.
 *
 * The three sweeps here are the ones that found it, kept as tests so the next one cannot be
 * introduced quietly:
 *
 *   a screen names a route        → that route has to be registered
 *   a controller renders a page   → that .vue file has to exist
 *   the navigation offers a link  → that link has to go somewhere
 *
 * They are deliberately broad rather than per-feature. A per-feature test is written by the person
 * who just built the feature, which is the one moment they are certain it is wired.
 */
class SupplyChainWiringTest extends TestCase
{
    use DatabaseTransactions;

    /** The screen directories that belong to this category. */
    private const SCREEN_DIRS = [
        'Budget', 'ContractManagement', 'Contracts', 'Procurement',
        'ProcurementGovernance', 'SupplierManagement', 'Marketplace',
    ];

    /** The controller directories that serve them. */
    private const APP_DIRS = [
        'app/Modules/Budget', 'app/Modules/ContractManagement', 'app/Modules/Procurement',
        'app/Modules/SupplierManagement', 'app/Modules/Marketplace',
    ];

    /** @return list<string> every .vue under resources/js/Modules/<dir> */
    private function screens(): array
    {
        $files = [];

        foreach (self::SCREEN_DIRS as $dir) {
            $base = resource_path("js/Modules/{$dir}");

            if (! is_dir($base)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'vue') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    public function test_every_route_a_supply_chain_screen_names_is_registered(): void
    {
        /*
         * A name that is not registered is a dead control: Ziggy throws before anything is sent, so
         * the failure never reaches the server and never reaches a log. This is the sweep that found
         * budget delegation and budget re-parenting, both built in full and both unreachable.
         */
        $broken = [];
        $checked = 0;

        foreach ($this->screens() as $path) {
            $source = (string) file_get_contents($path);
            preg_match_all("/route\(\s*'([^']+)'/", $source, $matches);

            foreach ($matches[1] as $name) {
                $checked++;

                if (! Route::has($name)) {
                    $broken[] = $name . '  in ' . str_replace(resource_path('js/Modules/'), '', $path);
                }
            }
        }

        $this->assertGreaterThan(100, $checked, 'the sweep found almost no route() calls — it is probably looking in the wrong place');
        $this->assertSame([], array_values(array_unique($broken)),
            "These screens name routes that do not exist. Each one is a control that throws in the\n"
            . "browser the moment somebody presses it:\n  " . implode("\n  ", array_unique($broken)));
    }

    public function test_every_page_a_supply_chain_controller_renders_exists(): void
    {
        /*
         * The resolver globs resources/js and looks up `./{name}.vue`, so a component name carries
         * its directory. Get it wrong and the response is a perfectly good 200 whose Vue throws
         * "Page not found" once it reaches a browser — and a test asserting the component name
         * compares the string the server sent against the string the test expected, which are wrong
         * together.
         */
        $missing = [];
        $checked = 0;

        foreach (self::APP_DIRS as $dir) {
            if (! is_dir(base_path($dir))) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir)));

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                preg_match_all("/Inertia::render\(\s*'([^']+)'/", (string) file_get_contents($file->getPathname()), $matches);

                foreach ($matches[1] as $component) {
                    $checked++;

                    if (! file_exists(resource_path("js/{$component}.vue"))) {
                        $missing[] = $component . '  in ' . $file->getFilename();
                    }
                }
            }
        }

        $this->assertGreaterThan(50, $checked, 'the sweep found almost no rendered pages — it is probably looking in the wrong place');
        $this->assertSame([], array_values(array_unique($missing)),
            "These controllers render pages with no .vue file. Each returns 200 and then fails to\n"
            . "render:\n  " . implode("\n  ", array_unique($missing)));
    }

    public function test_every_supply_chain_navigation_entry_goes_somewhere(): void
    {
        /*
         * A ribbon whose path has no route is a menu item that 404s. It is the first thing a new
         * customer clicks and the last thing anybody who uses the module daily would notice, because
         * they stopped reading the menu months ago.
         */
        $ribbons = DB::table('security_module_ribbons as r')
            ->join('security_modules as m', 'm.id', '=', 'r.module_id')
            ->join('security_categories as c', 'c.id', '=', 'm.category_id')
            ->where('c.name', 'ilike', '%supply%')
            ->where('r.active', 1)
            ->get(['m.name as module', 'r.ribbon_name', 'r.ribbon_path']);

        if ($ribbons->isEmpty()) {
            $this->markTestSkipped('No Supply Chain ribbons in this database.');
        }

        $uris = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => in_array('GET', $route->methods(), true))
            ->map(fn ($route) => trim($route->uri(), '/'))
            ->all();

        $broken = [];

        foreach ($ribbons as $ribbon) {
            $path = trim((string) $ribbon->ribbon_path, '/');

            if ($path === '') {
                $broken[] = "{$ribbon->module} / {$ribbon->ribbon_name}: no path at all";

                continue;
            }

            $matched = in_array($path, $uris, true) || collect($uris)->contains(
                fn ($uri) => preg_match('#^' . preg_replace('#\\\{[^}]+\\\}#', '[^/]+', preg_quote($uri, '#')) . '$#', $path) === 1
            );

            if (! $matched) {
                $broken[] = "{$ribbon->module} / {$ribbon->ribbon_name} -> /{$path}";
            }
        }

        $this->assertSame([], $broken,
            "These navigation entries point at nothing:\n  " . implode("\n  ", $broken));
    }

    /**
     * Every model in this category that is addressed by uuid, and the screens that address it.
     *
     * Seven models moved from a row id to a uuid, and each move broke controls that looked perfectly
     * correct: Ziggy builds the URL happily, and the server 404s — or, before the routes were
     * constrained, asks Postgres for `uuid = 714` and raises a 500. The supplier conversion alone
     * cost twenty-two of them, found only by sweeping for it.
     *
     * The bound model is resolved from the controller's own signature rather than from the parameter
     * name, because the name is not reliable: `{budget}` binds the supply-chain Budget on one route
     * and Finance's FinBudget on another, and only one of those is uuid-keyed.
     *
     * @return array<string, string> route name => the parameter that carries the uuid
     */
    private function uuidKeyedRoutes(): array
    {
        $models = [
            \App\Modules\SupplierManagement\Models\Supplier::class,
            \App\Modules\SupplierManagement\Models\PurchaseOrder::class,
            \App\Modules\Procurement\Models\PurchaseRequest::class,
            \App\Modules\Procurement\Models\ProcurementRequisition::class,
            \App\Modules\Budget\Models\Budget::class,
            \App\Modules\ContractManagement\Models\Contract::class,
        ];

        // Every one of them must actually be uuid-keyed, or the sweep below is checking a rule that
        // is not in force.
        foreach ($models as $model) {
            $this->assertSame('uuid', (new $model)->getRouteKeyName(), $model.' is not addressed by uuid');
        }

        $names = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $action = $route->getActionName();

            if (! $route->getName() || ! str_contains($action, '@')) {
                continue;
            }

            [$class, $method] = explode('@', $action, 2);

            if (! class_exists($class) || ! method_exists($class, $method)) {
                continue;
            }

            foreach ((new \ReflectionMethod($class, $method))->getParameters() as $parameter) {
                $type = $parameter->getType();

                if ($type instanceof \ReflectionNamedType && in_array(ltrim($type->getName(), '\\'), $models, true)) {
                    // The parameter name as well as the route: a uuid-keyed route may carry other
                    // parameters -- a screening hit, a case, a budget owner -- that are correctly
                    // numeric, and demanding a uuid of those would be wrong.
                    $names[$route->getName()] = $parameter->getName();
                }
            }
        }

        return $names;
    }

    public function test_no_screen_hands_a_row_id_to_a_uuid_keyed_route(): void
    {
        $bound = $this->uuidKeyedRoutes();
        $offenders = [];
        $checked = 0;

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js/Modules'))) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'vue') {
                continue;
            }

            preg_match_all(
                "/route\(\'([a-z0-9.\-]+)\',\s*([^)]+)\)/",
                (string) file_get_contents($file->getPathname()),
                $matches,
                PREG_SET_ORDER,
            );

            foreach ($matches as [, $name, $args]) {
                if (! isset($bound[$name])) {
                    continue;
                }

                $checked++;
                $first = trim(explode(',', ltrim(trim($args), '['))[0]);
                // A ref reads as `thing.value`; what matters is what `thing` is called.
                $named = str_ends_with($first, '.value') ? substr($first, 0, -6) : $first;

                /*
                 * `.uuid`, or a variable whose own name ends in uuid. The name matters as much as the
                 * value: four panels held a uuid in a prop called `supplierId`, which is an open
                 * invitation to the next person to pass an id into it.
                 */
                if (! str_ends_with($named, '.uuid') && ! str_ends_with(strtolower($named), 'uuid')) {
                    $offenders[] = str_replace(resource_path('js/Modules').'/', '', $file->getPathname())
                        .": route('{$name}', {$first})";
                }
            }
        }

        $this->assertGreaterThan(60, $checked, 'the sweep found almost no uuid-keyed calls — it is looking in the wrong place');
        $this->assertSame([], array_values(array_unique($offenders)),
            "These controls pass a row id to a route keyed on the uuid. Each 404s the moment somebody "
            ."presses it:\n  ".implode("\n  ", array_unique($offenders)));
    }

    public function test_no_uuid_keyed_route_still_demands_a_number(): void
    {
        /*
         * A numeric constraint left behind on a uuid-keyed route 404s a page that looks correct
         * everywhere else, which is the hardest kind of break to attribute. Removing the constraint
         * entirely is not the answer either: without a pattern, Postgres is asked `uuid = 714` and
         * raises a 500 where a stale bookmark deserves a 404.
         */
        $bound = $this->uuidKeyedRoutes();
        $numeric = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! isset($bound[$route->getName()])) {
                continue;
            }

            $owned = $bound[$route->getName()];

            if (($route->wheres[$owned] ?? null) === '[0-9]+') {
                $numeric[] = $route->uri()." ({$owned})";
            }
        }

        $this->assertSame([], array_values(array_unique($numeric)),
            "These uuid-keyed routes still require a number:\n  ".implode("\n  ", array_unique($numeric)));
    }

    public function test_every_route_a_supply_chain_screen_names_is_registered_still_passes_after_the_move(): void
    {
        // Renaming a parameter or splitting a route group is how a name quietly stops existing.
        $this->assertNotEmpty($this->uuidKeyedRoutes());
    }

    public function test_every_supply_chain_route_resolves_to_a_method_that_exists(): void
    {
        /*
         * A route pointing at a method that was renamed or removed is the same dead button from the
         * other direction, and it fails as a 500 rather than a 404 — which reads to whoever is
         * looking as a broken feature rather than a missing one.
         */
        $prefixes = ['budgets', 'contracts', 'purchase-requests', 'procurement/', 'procurement-governance',
            'bid-portal', 'suppliers', 'marketplace'];
        $broken = [];
        $checked = 0;

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();

            foreach ($prefixes as $prefix) {
                if (! str_starts_with($uri, $prefix)) {
                    continue;
                }

                $action = $route->getActionName();

                if ($action === 'Closure' || ! str_contains($action, '@')) {
                    break;
                }

                $checked++;
                [$class, $method] = explode('@', $action, 2);

                if (! class_exists($class)) {
                    $broken[] = "{$uri} -> missing class {$class}";
                } elseif (! method_exists($class, $method)) {
                    $broken[] = "{$uri} -> missing method {$class}::{$method}";
                }

                break;
            }
        }

        $this->assertGreaterThan(200, $checked, 'the sweep found almost no supply chain routes');
        $this->assertSame([], $broken, "Routes pointing at nothing:\n  " . implode("\n  ", $broken));
    }
}
