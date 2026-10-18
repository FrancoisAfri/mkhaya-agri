<?php

namespace Tests\Feature\Frontend;

use Tests\TestCase;

/**
 * Every page a controller renders can actually be found by the browser.
 *
 * Ninety-five pages used to be imported at the top of `app.ts` and held in an `explicitPages` map,
 * because `import.meta.glob` is evaluated when the dev server starts and a brand-new directory is
 * invisible to it until a restart. That is a dev-server annoyance with a dev-server fix. What the
 * list cost was a production main chunk of 8.3 MB — pages nobody on a given visit would open,
 * welded into the file everybody downloads first, growing with every module added.
 *
 * Removing it means one thing has to hold: the glob must reach every page. It does today, but the
 * failure mode if it ever stops is unpleasant and silent — the server answers 200, Inertia cannot
 * find the component, and the person gets a blank screen with an error in a console they are not
 * looking at. No PHP test would see it, because the response is fine.
 *
 * So this reads the page names out of the controllers and checks each one against both the glob
 * patterns and the file on disk.
 */
class PageResolvesTest extends TestCase
{
    /**
     * The patterns in app.ts, as prefixes. Kept in step by the first test below, which fails if
     * app.ts gains or loses one.
     *
     * @var list<string>
     */
    private const GLOB_PREFIXES = [
        'Modules/', 'Policies/', 'HR/', 'Pages/', 'Tenancy/', 'Reseller/', 'Core/',
    ];

    /** Infrastructure the glob deliberately excludes; a page must never live here. */
    private const EXCLUDED = ['Core/Layouts/', 'Core/Components/'];

    /** @return list<string> every component name passed to Inertia::render() */
    private function renderedPages(): array
    {
        $names = [];

        foreach (['app', 'routes'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir)));

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                preg_match_all(
                    "/Inertia::render\(\s*'([^']+)'/",
                    (string) file_get_contents($file->getPathname()),
                    $matches,
                );

                foreach ($matches[1] as $name) {
                    $names[$name] = true;
                }
            }
        }

        return array_keys($names);
    }

    public function test_the_glob_patterns_here_still_match_the_ones_in_app_ts(): void
    {
        /*
         * This test is only as good as its copy of the patterns. If somebody narrows a pattern in
         * app.ts and this file keeps the old one, the sweep below passes while real pages stop
         * resolving — the exact failure it exists to prevent.
         */
        $source = (string) file_get_contents(resource_path('js/app.ts'));

        preg_match_all("#'\./([A-Za-z]+)/\*\*/\*\.vue'#", $source, $matches);

        $inApp = array_values(array_unique(array_map(fn ($d) => $d.'/', $matches[1])));
        sort($inApp);

        $here = self::GLOB_PREFIXES;
        sort($here);

        $this->assertSame($here, $inApp,
            'app.ts and this test disagree about which directories hold pages. Update GLOB_PREFIXES.');
    }

    public function test_every_page_a_controller_renders_is_reachable_by_the_glob(): void
    {
        $unreachable = [];
        $pages = $this->renderedPages();

        foreach ($pages as $name) {
            $matched = false;

            foreach (self::GLOB_PREFIXES as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    $matched = true;
                    break;
                }
            }

            foreach (self::EXCLUDED as $excluded) {
                if (str_starts_with($name, $excluded)) {
                    $matched = false;
                    break;
                }
            }

            if (! $matched) {
                $unreachable[] = $name;
            }
        }

        $this->assertGreaterThan(300, count($pages),
            'the sweep found almost no rendered pages — it is looking in the wrong place');

        $this->assertSame([], $unreachable,
            "These pages are rendered by a controller but sit outside every glob pattern in app.ts.\n"
            ."The server answers 200 and the browser shows a blank screen:\n  "
            .implode("\n  ", $unreachable));
    }

    public function test_every_page_a_controller_renders_exists_on_disk(): void
    {
        /*
         * The other half. A name that matches a pattern but has no file behind it fails the same
         * way — and this is the mistake that is easy to make, because the name is a string in PHP
         * and nothing checks it against the filesystem.
         */
        /*
         * One page is already missing, and was before this test existed.
         *
         * `/quick-access` is a live route with a service, accessible-ribbon logic and full
         * create/update/delete behind it, rendering `Modules/QuickAccess/Index` — which has never
         * existed, not even in the explicit map that used to list pages. Anybody opening that URL
         * gets a blank screen today.
         *
         * Named here rather than tolerated silently: it is one line, it says what is wrong, and it
         * fails the moment a second page joins it. Either the page gets built or the four routes
         * get removed; both are decisions for somebody who knows what that screen was meant to be.
         */
        $knownMissing = ['Modules/QuickAccess/Index'];

        $missing = [];

        foreach ($this->renderedPages() as $name) {
            if (! file_exists(resource_path("js/{$name}.vue")) && ! in_array($name, $knownMissing, true)) {
                $missing[] = $name;
            }
        }

        // And the known one must stay known: if it is fixed, this list should shrink with it.
        foreach ($knownMissing as $known) {
            if (file_exists(resource_path("js/{$known}.vue"))) {
                $this->fail("{$known} exists now — remove it from \$knownMissing.");
            }
        }

        $this->assertSame([], $missing,
            "These pages are rendered by a controller but there is no such .vue file:\n  "
            .implode("\n  ", $missing));
    }
}
