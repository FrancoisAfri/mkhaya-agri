<?php

namespace Tests\Feature\Localisation;

use Tests\TestCase;

/**
 * A key a controller flashes has to exist in the screen locale, because that is the only place
 * it can be translated.
 *
 * The app has no PHP translation catalogue for module screens — `MainLayout` takes whatever a
 * controller flashed, asks vue-i18n whether it is a known key, and translates it if so,
 * otherwise shows the string unchanged. That design is fine, and it fails silently: a key the
 * screen locale does not carry passes the `te()` guard as "not a key" and the user reads the
 * dotted key out of the snackbar.
 *
 * Fleet Job Cards shipped that way. Every one of its 34 flash messages — "created",
 * "approved", "cancelled" — reached the user as `fleet_job_cards.flash.created`, because the
 * module had no section in the screen locale at all. `__()` in the controller returns the key
 * when nothing defines it, so nothing threw, nothing logged, and the English screen looked as
 * broken as the isiZulu one.
 *
 * This test reads the keys out of the controllers rather than a list somebody maintains, so a
 * new flash message is covered the moment it is written.
 */
class FlashedKeyTest extends TestCase
{
    /** The flash channels `MainLayout` watches. Anything else is not shown as a snackbar. */
    private const CHANNELS = ['success', 'error', 'warning', 'info', 'message'];

    /** @return array<string,string> every key in the English screen locale */
    private function englishScreenKeys(): array
    {
        $out = [];

        /*
         * Order matters and it is the reverse of what reads naturally. i18n.ts composes the
         * English messages as withModules(en, 'en'), a deep merge that puts modules/en.json on
         * top — so where both files define a key, the modules one is what the screen shows.
         *
         * Writing this with `+=`, which keeps the first value seen, had the test reading the
         * shadowed copy: locales/en.json still holds helpdesk_service_report.flash.sent as
         * "Report sent to :email." while modules/en.json has the live "{email}" version, and the
         * placeholder check below quietly found nothing to object to. The test passed with the
         * defect reinstated, which is how this was caught.
         */
        foreach (['locales/en.json', 'locales/modules/en.json'] as $relative) {
            $path = base_path("resources/js/utils/{$relative}");

            $this->assertFileExists($path, "{$relative} is missing, so no screen can be translated.");

            $decoded = json_decode((string) file_get_contents($path), true);

            $this->assertIsArray($decoded, "{$relative} is not valid JSON.");

            $out = array_replace($out, $this->flatten($decoded));
        }

        /*
         * Four namespaces are composed in i18n.ts from TypeScript rather than JSON. Reading them
         * properly would mean running the bundler, so take the keys textually: a false *pass*
         * here needs a controller to flash a key whose last segment happens to appear as a
         * property name in one of these files, which is a far smaller risk than not checking
         * them at all.
         */
        foreach (['finance', 'accounting', 'nav', 'bureau', 'dashboard'] as $module) {
            $path = base_path("resources/js/utils/locales/{$module}.ts");

            if (is_file($path)) {
                $out["__ts_{$module}"] = (string) file_get_contents($path);
            }
        }

        return $out;
    }

    /** @return array<string,string> */
    private function flatten(array $node, string $prefix = ''): array
    {
        $out = [];

        foreach ($node as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            $out += is_array($value) ? $this->flatten($value, $path) : [$path => $value];
        }

        return $out;
    }

    /** @return array<string,string> dotted key => the file that flashes it */
    private function flashedKeys(): array
    {
        $found = [];
        $channels = implode('|', self::CHANNELS);

        foreach ($this->phpFiles() as $path) {
            $source = (string) file_get_contents($path);
            $short = str_replace(base_path().'/', '', $path);

            // ->with('success', __('some.key')) and the trans() spelling.
            preg_match_all(
                '/->with\(\s*[\'"](?:'.$channels.')[\'"]\s*,\s*(?:__|trans)\(\s*[\'"]'
                .'([a-z][a-z0-9_]*\.[a-z0-9_.]+)[\'"]/i',
                $source,
                $direct,
            );

            // ->with('success', ['key' => 'some.key', 'params' => [...]])
            preg_match_all(
                '/->with\(\s*[\'"](?:'.$channels.')[\'"]\s*,\s*\[\s*[\'"]key[\'"]\s*=>\s*[\'"]'
                .'([a-z][a-z0-9_]*\.[a-z0-9_.]+)[\'"]/i',
                $source,
                $structured,
            );

            foreach ([...$direct[1], ...$structured[1]] as $key) {
                $found[$key] ??= $short;
            }
        }

        return $found;
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        $out = [];

        foreach (['app', 'routes'] as $root) {
            $directory = new \RecursiveDirectoryIterator(base_path($root), \FilesystemIterator::SKIP_DOTS);

            foreach (new \RecursiveIteratorIterator($directory) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $out[] = $file->getPathname();
                }
            }
        }

        return $out;
    }

    public function test_the_controllers_flash_keys_the_screens_can_actually_translate(): void
    {
        $flashed = $this->flashedKeys();

        $this->assertNotEmpty($flashed,
            'No flashed translation keys were found at all, so this test is no longer reading the controllers.');

        $english = $this->englishScreenKeys();
        $tsSources = array_filter($english, fn (string $k) => str_starts_with($k, '__ts_'), ARRAY_FILTER_USE_KEY);

        $unresolved = [];

        foreach ($flashed as $key => $file) {
            if (array_key_exists($key, $english)) {
                continue;
            }

            // Laravel's own catalogue is a legitimate home for a flashed key too.
            if (trans($key) !== $key) {
                continue;
            }

            $leaf = substr($key, (int) strrpos($key, '.') + 1);
            $inTypeScript = false;

            foreach ($tsSources as $source) {
                if (str_contains($source, "'{$key}'") || str_contains($source, "{$leaf}:")) {
                    $inTypeScript = true;
                    break;
                }
            }

            if (! $inTypeScript) {
                $unresolved[] = "{$key}  (flashed from {$file})";
            }
        }

        sort($unresolved);

        $this->assertSame([], $unresolved, "These flashed keys resolve to nothing, so the user reads the key itself:\n  "
            .implode("\n  ", $unresolved)."\n");
    }

    public function test_a_flashed_key_with_placeholders_is_flashed_with_its_values(): void
    {
        /*
         * `__('key', $params)` cannot substitute anything when nothing defines the key: it hands
         * back the key and drops the values on the floor. The screen then renders the English
         * template with its placeholders showing — "Report sent to {email}." — so a message with
         * placeholders must be flashed as ['key' => ..., 'params' => ...] instead.
         *
         * A key the PHP catalogue *does* define is not affected: `__()` renders it there, in the
         * request's locale, and the values are substituted before the string is ever flashed.
         * Eleven Project Management messages look like this defect and are not: each locale's
         * own project_management.php carries them. Checking only the screen locale called them
         * broken and would have had me rewrite eleven correct call sites.
         */
        $english = $this->englishScreenKeys();
        $channels = implode('|', self::CHANNELS);
        $offenders = [];

        foreach ($this->phpFiles() as $path) {
            $source = (string) file_get_contents($path);
            $short = str_replace(base_path().'/', '', $path);

            preg_match_all(
                '/->with\(\s*[\'"](?:'.$channels.')[\'"]\s*,\s*(?:__|trans)\(\s*[\'"]'
                .'([a-z][a-z0-9_]*\.[a-z0-9_.]+)[\'"]\s*,/i',
                $source,
                $matches,
            );

            foreach ($matches[1] as $key) {
                if (trans($key) !== $key) {
                    continue;   // the PHP catalogue renders it, values and all
                }

                $message = $english[$key] ?? null;

                if (is_string($message) && preg_match('/\{[a-z][a-z0-9_]*\}/i', $message)) {
                    $offenders[] = "{$key}  (flashed with values from {$short}, but through __())";
                }
            }
        }

        sort($offenders);

        $this->assertSame([], $offenders, "These flashed messages take placeholders, so their values never arrive:\n  "
            .implode("\n  ", $offenders)."\n");
    }
}
