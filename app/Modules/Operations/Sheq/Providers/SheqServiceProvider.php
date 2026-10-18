<?php

namespace App\Modules\Operations\Sheq\Providers;

use App\Modules\Operations\Sheq\Adapters\HrEmployeeDirectory;
use App\Modules\Operations\Sheq\Adapters\PlatformNotificationTransport;
use App\Modules\Operations\Sheq\Adapters\SheqCompetencyLedger;
use App\Modules\Operations\Sheq\Contracts\CompetencyLedger;
use App\Modules\Operations\Sheq\Contracts\EmployeeDirectory;
use App\Modules\Operations\Sheq\Contracts\NotificationTransport;
use App\Modules\Operations\Sheq\Console\InstallLibrariesCommand;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Binds SHEQ's anti-corruption layer to the adapters that implement it (SHEQ-ADR-002).
 *
 * These interfaces are the seam between SHEQ and the modules that own the data — HR owns employees,
 * LMS owns training. Swapping an adapter here is how SHEQ moves from reading another module's
 * models to consuming its events, without a single caller changing.
 *
 * The adapters degrade rather than fail (SHEQ-ENT-3): each reports `isAvailable()`, and callers
 * record an unavailable source as an unknown rather than as compliance. That is why the binding is
 * unconditional — a tenant without LMS still gets a ledger, it simply answers "I cannot tell you",
 * which the context snapshot writes down explicitly.
 */
class SheqServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EmployeeDirectory::class, HrEmployeeDirectory::class);

        // The competency view merges SHEQ's governed records — manual certificates and legal
        // appointments — with LMS. SHEQ's own record wins where both exist, because it is the one
        // carrying the appointment scope, the revocation and the grace period.
        $this->app->bind(CompetencyLedger::class, SheqCompetencyLedger::class);

        // Delivery is borrowed from the platform's mail and SMS services. Bound as an interface so
        // a test can assert that a fatality produced a voice dispatch without a provider being
        // reachable, and so voice can be added later without touching the router.
        $this->app->bind(NotificationTransport::class, PlatformNotificationTransport::class);
    }

    public function boot(): void
    {
        $this->installLibrariesAfterMigrations();
    }

    /**
     * Install the shipped libraries once a deployment's migrations have finished.
     *
     * A deploy runs `php artisan migrate`, so this is the one hook that fires on every deployment
     * without anybody adding a step to a script they may not control. It closes the window the
     * nightly schedule leaves open: without it, a fresh installation has no regulatory packs until
     * the following night, and during that window no tenant can activate one and no incident can
     * be reported at all. That window was reached in production.
     *
     * Deliberately narrow:
     *
     * - console only, so no web request ever triggers an install;
     * - not during tests, where migrations run per suite and a fixture's own pack must not compete
     *   with the shipped ones;
     * - failures are swallowed, because a deployment must not be failed by a content refresh. The
     *   nightly run and the command itself remain, and the module says plainly on screen when no
     *   pack is active.
     */
    private function installLibrariesAfterMigrations(): void
    {
        if (! $this->app->runningInConsole() || $this->app->runningUnitTests()) {
            return;
        }

        Event::listen(MigrationsEnded::class, function (): void {
            try {
                Artisan::call(InstallLibrariesCommand::class);
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }
}
