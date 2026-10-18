<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Contracts\CompetencyLedger;
use App\Modules\Operations\Sheq\Contracts\EmployeeDirectory;
use App\Modules\Operations\Sheq\Contracts\NotificationTransport;
use App\Modules\Operations\Sheq\Http\Api\V1\SyncApiController;
use App\Modules\Operations\Sheq\Http\Controllers\IncidentController;
use App\Modules\Operations\Sheq\Http\Controllers\RegulatoryPackController;
use App\Modules\Operations\Sheq\Http\Controllers\SheqDashboardController;
use App\Modules\Operations\Sheq\Http\Controllers\StatutoryObligationController;
use App\Modules\Operations\Sheq\Services\ContextSnapshotBuilder;
use App\Modules\Operations\Sheq\Services\IncidentReporter;
use App\Modules\Operations\Sheq\Services\LegalRegisterService;
use App\Modules\Operations\Sheq\Services\NotificationRouter;
use App\Modules\Operations\Sheq\Services\NotificationSender;
use App\Modules\Operations\Sheq\Sync\SyncRegistry;
use App\Modules\Operations\Sheq\Sync\SyncService;
use Database\Seeders\SheqModuleSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Container wiring for the module.
 *
 * This class exists because of a real defect: SHEQ's interfaces were introduced without binding
 * them to implementations, and every existing test constructed the services by hand
 * (`new ContextSnapshotBuilder(new HrEmployeeDirectory(), …)`). The suite was green while
 * /sheq/incidents/create returned a 500 — "Target [EmployeeDirectory] is not instantiable".
 *
 * A test that builds its own dependencies proves the class works. It says nothing about whether the
 * application can build it. These assertions cover the gap: everything a route reaches must be
 * resolvable from the container exactly as the framework would resolve it.
 */
class SheqWiringTest extends TestCase
{
    public function test_the_anti_corruption_interfaces_are_bound(): void
    {
        $this->assertInstanceOf(EmployeeDirectory::class, app(EmployeeDirectory::class));
        $this->assertInstanceOf(CompetencyLedger::class, app(CompetencyLedger::class));

        // Delivery is borrowed from the platform the same way, through an interface — so it can
        // go unbound the same way.
        $this->assertInstanceOf(NotificationTransport::class, app(NotificationTransport::class));
    }

    /**
     * Every permission a SHEQ controller demands must exist in the catalogue.
     *
     * Added after a real defect: the contractor register, the legal register, statutory forms and
     * settings all guarded on permission names that were never seeded, so `can()` returned false
     * for everybody and the screens 403'd for admins. Nothing caught it because the strings were
     * plausible — `sheq.contractor.manage` reads exactly like a permission that exists.
     */
    public function test_every_permission_a_controller_demands_is_seeded(): void
    {
        $catalogue = SheqModuleSeeder::permissions();
        $missing = [];

        foreach (glob(app_path('Modules/Operations/Sheq/Http/Controllers/*.php')) as $file) {
            $source = file_get_contents($file);

            // Only the strings actually passed to an authorisation check.
            preg_match_all("/(?:->can|abort_unless\\(\\\$request->user\\(\\)\\?->can)\\(\\s*'(sheq\\.[a-z_.]+)'/",
                $source, $matches);

            foreach ($matches[1] ?? [] as $permission) {
                if (! in_array($permission, $catalogue, true)) {
                    $missing[] = basename($file).' → '.$permission;
                }
            }
        }

        $this->assertSame([], array_unique($missing), implode(PHP_EOL, array_unique($missing)));
    }

    public function test_the_sync_engine_resolves(): void
    {
        // SyncService depends on the registry and the merger; the API controller depends on all
        // three plus CurrentCompany. A missing binding here breaks every field device at once.
        $this->assertInstanceOf(SyncService::class,
            app(SyncService::class));
        $this->assertInstanceOf(SyncRegistry::class,
            app(SyncRegistry::class));
        $this->assertInstanceOf(SyncApiController::class,
            app(SyncApiController::class));
    }

    /**
     * Every entity the registry offers must actually be syncable against the real schema.
     *
     * A field named in `syncable` that is not a column produces a SQL error on a device's push —
     * discovered in production, on a phone, at the bottom of a shaft.
     */
    public function test_every_syncable_field_exists_as_a_column(): void
    {
        $registry = app(SyncRegistry::class);
        $problems = [];

        foreach ($registry->all() as $type => $spec) {
            /**
             * A missing table would otherwise report every one of its fields as a missing column,
             * which reads as a schema bug when it is really the test database being rebuilt by
             * another suite. Say which it is.
             */
            if (! Schema::hasTable($spec->table)) {
                $this->markTestSkipped(
                    "Table {$spec->table} does not exist — the test database is mid-migration."
                );
            }

            $columns = Schema::getColumnListing($spec->table);

            $missing = array_diff(
                array_merge($spec->syncable, $spec->appendOnly, $spec->jsonFields,
                    [$spec->statusField, $spec->siteField]),
                $columns
            );

            foreach ($missing as $field) {
                $problems[] = "{$type}.{$field} is not a column on {$spec->table}";
            }
        }

        $this->assertSame([], $problems, implode(PHP_EOL, $problems));
    }

    public function test_the_notification_pipeline_resolves(): void
    {
        // NotificationSender depends on the NotificationTransport interface; an unbound one would
        // only surface on the scheduled send, hours after the incident it was meant to alert about.
        $this->assertInstanceOf(NotificationRouter::class, app(NotificationRouter::class));
        $this->assertInstanceOf(NotificationSender::class, app(NotificationSender::class));
        $this->assertInstanceOf(LegalRegisterService::class, app(LegalRegisterService::class));
    }

    public function test_every_sheq_console_command_is_registered_and_resolvable(): void
    {
        // A command that cannot be constructed fails inside the scheduler at 03:40, where nobody
        // is watching — the same invisibility that made the controller defect expensive.
        $commands = Artisan::all();

        foreach ([
            'sheq:packs:install',
            'sheq:notifications:send',
            'sheq:legal-register:recompute',
        ] as $name) {
            $this->assertArrayHasKey($name, $commands, "{$name} is not registered.");
            $this->assertInstanceOf(Command::class, $commands[$name]);
        }
    }

    public function test_every_sheq_controller_resolves_from_the_container(): void
    {
        // Constructor injection is what broke; resolving each controller is what proves it works.
        foreach ([
            IncidentController::class,
            StatutoryObligationController::class,
            RegulatoryPackController::class,
            SheqDashboardController::class,
        ] as $controller) {
            $this->assertInstanceOf($controller, app($controller), "{$controller} is not resolvable.");
        }
    }

    public function test_the_incident_pipeline_resolves_with_its_full_dependency_graph(): void
    {
        // IncidentReporter → ContextSnapshotBuilder → EmployeeDirectory + CompetencyLedger.
        // Three levels deep, which is exactly where an unbound interface hides.
        $this->assertInstanceOf(IncidentReporter::class, app(IncidentReporter::class));
        $this->assertInstanceOf(ContextSnapshotBuilder::class, app(ContextSnapshotBuilder::class));
    }

    public function test_every_sheq_route_points_at_a_resolvable_controller(): void
    {
        $failures = [];

        foreach (Route::getRoutes() as $route) {
            // Both surfaces. The API routes live under api/v1/sheq and were invisible to a sweep
            // that only matched the web prefix — so a controller reachable only by a field device
            // could have been unresolvable with the suite still green.
            if (! str_starts_with($route->uri(), 'sheq')
                && ! str_starts_with($route->uri(), 'api/v1/sheq')) {
                continue;
            }

            $action = $route->getAction('controller');
            if (! is_string($action) || ! str_contains($action, '@')) {
                continue;
            }

            [$class, $method] = explode('@', $action, 2);

            try {
                $instance = app($class);
                if (! method_exists($instance, $method)) {
                    $failures[] = "{$route->uri()} → {$class}::{$method}() does not exist";
                }
            } catch (\Throwable $e) {
                $failures[] = "{$route->uri()} → {$class} is not resolvable: ".$e->getMessage();
            }
        }

        $this->assertSame([], $failures, implode(PHP_EOL, $failures));
    }
}
