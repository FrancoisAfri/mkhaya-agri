<?php

namespace Tests\Unit\Packaging;

use PHPUnit\Framework\TestCase;

/**
 * Nothing in `app/` may import a package that only `require-dev` provides.
 *
 * Production installs with `composer install --no-dev`, so a dev-only package is simply not there.
 * The code still looks right, the tests still pass locally, and the failure only appears on the
 * live server as a "Class not found" at the moment somebody uses the feature.
 *
 * This is not hypothetical. `PackLoader` and the two SHEQ install commands used
 * `Symfony\Component\Yaml\Yaml`, which was present locally only because `laravel/sail` — a dev
 * dependency — happened to pull it in. On production all three commands failed, which meant no
 * regulatory pack could ever be installed, which meant SHEQ incident reporting was impossible for
 * every tenant on that deployment. It took a customer hitting a 500 to surface it.
 *
 * The check is deliberately crude — namespace prefixes out of composer.lock against `use`
 * statements in `app/` — because the cost of being wrong in this direction is a production outage
 * and the cost of a false positive is a one-line composer change.
 */
class ShippedCodeOnlyUsesProductionDependenciesTest extends TestCase
{
    public function test_app_does_not_import_anything_from_a_dev_only_package(): void
    {
        $root = dirname(__DIR__, 3);

        $composer = json_decode((string) file_get_contents($root.'/composer.json'), true);
        $lock = json_decode((string) file_get_contents($root.'/composer.lock'), true);

        $production = array_column($lock['packages'] ?? [], 'name');

        /*
         * Namespaces the project itself declares are skipped. A dev tool may ship stubs under
         * `App\`, which would otherwise match every file in the codebase.
         */
        $own = array_merge(
            array_keys($composer['autoload']['psr-4'] ?? []),
            array_keys($composer['autoload-dev']['psr-4'] ?? []),
        );

        $devOnly = [];
        foreach ($lock['packages-dev'] ?? [] as $package) {
            if (in_array($package['name'], $production, true)) {
                continue;
            }

            foreach (['psr-4', 'psr-0'] as $standard) {
                foreach (array_keys($package['autoload'][$standard] ?? []) as $prefix) {
                    if ($prefix !== '' && ! in_array($prefix, $own, true)) {
                        $devOnly[$prefix] = $package['name'];
                    }
                }
            }
        }

        $this->assertNotEmpty($devOnly, 'No dev-only namespaces were resolved — the check would pass vacuously.');

        $offences = [];
        foreach ($this->phpFilesIn($root.'/app') as $file) {
            preg_match_all('/^\s*use\s+([A-Za-z0-9_\\\\]+)/m', (string) file_get_contents($file), $matches);

            foreach ($matches[1] as $imported) {
                foreach ($devOnly as $prefix => $package) {
                    if (str_starts_with($imported, $prefix)) {
                        $relative = str_replace($root.'/', '', $file);
                        $offences[] = "{$relative} imports {$imported} (only in require-dev via {$package})";
                        break;
                    }
                }
            }
        }

        $this->assertSame([], $offences, implode("\n", array_merge(
            ['Shipped code imports packages that production will not have:'],
            array_unique($offences),
            ['', 'Move the package into composer.json "require".'],
        )));
    }

    /** @return iterable<string> */
    private function phpFilesIn(string $directory): iterable
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }
}
