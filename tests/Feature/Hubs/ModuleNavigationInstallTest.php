<?php

namespace Tests\Feature\Hubs;

use App\Support\Hubs\HubRegistry;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A module that installs but cannot be found.
 *
 * Employee Reputation shipped its tables and its billing row by migration and its navigation by
 * seeder. Seeders do not run on a deploy — `migrate --force` creates the tables and stops — so on
 * production the module was installed, entitled, licensed and reachable by URL, and absent from
 * the menu. To everyone involved that reads as "it was never built".
 *
 * The due-diligence add-on failed the same way a week earlier, which is what makes it a pattern
 * rather than an incident, and worth a test.
 */
class ModuleNavigationInstallTest extends TestCase
{
    public function test_no_active_module_sits_in_the_menu_without_any_ribbons(): void
    {
        // A module row with no ribbons still draws a heading in the sidebar — one that opens
        // nothing. Payroll Bureau and Whistleblower & Ethics were both in that state.
        $orphans = DB::table('security_modules')
            ->where('active', 1)
            ->whereNotIn('id', fn ($q) => $q->select('module_id')->distinct()->from('security_module_ribbons'))
            ->pluck('code_name')
            // Only modules that have a hub config are this test's business: those are the ones
            // where the navigation is already described and simply has not been synced.
            ->filter(fn ($code) => HubRegistry::for($code) !== null)
            ->values()
            ->all();

        $this->assertSame(
            [],
            $orphans,
            'These modules appear in the navigation with nothing beneath them, so clicking one goes '
            .'nowhere. Run `php artisan hubs:sync <code>`: ' . implode(', ', $orphans),
        );
    }

    public function test_every_ribbon_points_at_a_route_that_exists(): void
    {
        // A ribbon whose path has no route is a menu entry that 404s — the same "it does not work"
        // report as a missing module, arrived at from the other direction.
        $uris = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r) => in_array('GET', $r->methods(), true))
            ->map(fn ($r) => trim($r->uri(), '/'))
            ->unique()
            ->all();

        $dead = DB::table('security_module_ribbons as r')
            ->join('security_modules as m', 'm.id', '=', 'r.module_id')
            ->where('m.active', 1)
            ->where('r.active', 1)
            ->whereNotNull('r.ribbon_path')
            ->get(['m.code_name', 'r.ribbon_name', 'r.ribbon_path'])
            ->reject(fn ($row) => in_array(trim((string) $row->ribbon_path, '/'), $uris, true))
            ->map(fn ($row) => "{$row->code_name}: {$row->ribbon_name} → /{$row->ribbon_path}")
            ->values()
            ->all();

        $this->assertSame([], $dead, "Navigation entries pointing at no route:\n  " . implode("\n  ", $dead));
    }
}
