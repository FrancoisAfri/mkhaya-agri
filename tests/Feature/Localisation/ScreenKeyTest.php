<?php

namespace Tests\Feature\Localisation;

use Tests\TestCase;

/**
 * Every key a screen asks for exists in the English locale.
 *
 * vue-i18n has no failure mode worth the name: ask for a key nothing defines and it renders the
 * key. So a screen can ship reading `fleet_job_cards.col_vehicle` in a table head, in English,
 * and nothing anywhere reports it — not a test, not a log, not the bundler. Fleet Job Cards did
 * exactly that for its whole surface, 211 keys, because the module had no section in the locale
 * at all; it was found by accident while chasing something else.
 *
 * This reads the keys out of the components, so a new screen is covered the moment it is written.
 */
class ScreenKeyTest extends TestCase
{
    /**
     * Namespaces composed in i18n.ts from TypeScript rather than JSON. Their contents cannot be
     * read without running the bundler, so a key under one of these is checked only as far as
     * "that namespace is really built in that file" — which is what would have caught Fleet.
     */
    private const TYPESCRIPT_NAMESPACES = [
        'acc' => 'accounting.ts',
        'fin' => 'finance.ts',
        'nav' => 'nav.ts',
        'bureau' => 'bureau.ts',
        'home_page' => 'dashboard.ts',
    ];

    /** @return array<string,string> */
    private function englishKeys(): array
    {
        $out = [];

        // modules/en.json is deep-merged over en.json, so it wins; for "does this key exist at
        // all" either file is enough, and the union is what we want.
        foreach (['locales/en.json', 'locales/modules/en.json'] as $relative) {
            $path = base_path("resources/js/utils/{$relative}");
            $decoded = json_decode((string) file_get_contents($path), true);

            $this->assertIsArray($decoded, "{$relative} is not valid JSON.");

            $out += $this->flatten($decoded);
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

    /**
     * Every class with a hubs() method, keyed by class name.
     *
     * @return array<string,class-string>
     */
    private function hubClasses(): array
    {
        $found = \App\Support\Hubs\HubRegistry::all();
        $directory = new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS);

        foreach (new \RecursiveIteratorIterator($directory) as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), 'Hubs.php')) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (! preg_match('/namespace\s+([^;]+);/', $source, $ns)
                || ! preg_match('/\bclass\s+(\w+)/', $source, $cls)
                || ! str_contains($source, 'function hubs(')) {
                continue;
            }

            $class = trim($ns[1]).'\\'.$cls[1];

            // ModuleHubs itself matches the filename and declares hubs() abstract.
            if (class_exists($class)
                && ! (new \ReflectionClass($class))->isAbstract()
                && ! in_array($class, $found, true)) {
                $found[$cls[1]] = $class;
            }
        }

        return $found;
    }

    /** @return array<string,string> dotted key => first component that asks for it */
    private function referencedKeys(): array
    {
        $found = [];
        $root = base_path('resources/js');
        $directory = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);

        foreach (new \RecursiveIteratorIterator($directory) as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['vue', 'ts'], true)) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            $short = str_replace(base_path().'/', '', $file->getPathname());

            /*
             * t('some.key') and $t('some.key'). The leading character class keeps this off
             * `useFormat(` and similar: a bare `t(` has to be preceded by something that is not
             * part of an identifier.
             */
            preg_match_all(
                '/[^a-zA-Z_$]\$?t\(\s*[\'"]([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+)[\'"]/',
                $source,
                $matches,
            );

            foreach ($matches[1] as $key) {
                $found[$key] ??= $short;
            }
        }

        return $found;
    }

    public function test_every_key_a_screen_asks_for_exists_in_english(): void
    {
        $referenced = $this->referencedKeys();

        $this->assertGreaterThan(20000, count($referenced),
            'Far fewer keys were found than this app has, so the scan is no longer reading the components.');

        $english = $this->englishKeys();
        $typescript = [];

        foreach (self::TYPESCRIPT_NAMESPACES as $namespace => $file) {
            $path = base_path("resources/js/utils/locales/{$file}");
            $typescript[$namespace] = is_file($path) ? (string) file_get_contents($path) : '';
        }

        $unresolved = [];

        foreach ($referenced as $key => $file) {
            if (array_key_exists($key, $english)) {
                continue;
            }

            $namespace = strtok($key, '.');
            $leaf = substr($key, (int) strrpos($key, '.') + 1);

            /*
             * A key ending in _ is the literal half of a concatenation — t('x.status_' + row.status).
             * It is satisfied if anything is defined under that prefix; whether every value of
             * row.status has a key is beyond what the source can tell us. This has to be settled
             * before the TypeScript branch below, because seven of these live under `acc` and
             * testing them as whole keys reported all seven as missing.
             */
            if (str_ends_with($key, '_')) {
                $haystack = $typescript[$namespace] ?? '';

                foreach (array_keys($english) as $candidate) {
                    if (str_starts_with($candidate, $key)) {
                        continue 2;
                    }
                }

                if (preg_match('/\b'.preg_quote($leaf, '/').'[a-z0-9_]+\s*:/', $haystack) === 1) {
                    continue;
                }

                $unresolved[] = "{$key}*  (asked for in {$file}; nothing is defined under that prefix)";

                continue;
            }

            /*
             * A TypeScript namespace. Checking that the leaf appears as a property in that file
             * is as far as the source can take us without running the bundler — and it is enough
             * for this class of defect: `bureau.eti.months_left` was caught here, a chip on the
             * bureau employee list that rendered its own key because bureau.ts never defined it.
             */
            if (isset($typescript[$namespace])) {
                if (preg_match('/\b'.preg_quote($leaf, '/').'\s*:/', $typescript[$namespace]) === 1) {
                    continue;
                }

                $unresolved[] = "{$key}  (asked for in {$file}; "
                    .self::TYPESCRIPT_NAMESPACES[$namespace].' does not define it)';

                continue;
            }

            $unresolved[] = "{$key}  (asked for in {$file})";
        }

        sort($unresolved);

        $this->assertSame([], $unresolved,
            "These keys render as themselves on the screen, in every language including English:\n  "
            .implode("\n  ", $unresolved)."\n");
    }

    public function test_every_hub_card_has_a_title_and_a_subtitle(): void
    {
        /*
         * A hub is a module's landing page, and its card titles are built in PHP —
         * `static::code().'.cards.'.$key.'.title'` — so the scan above cannot see them: there is
         * no literal key anywhere in the source to find. A missing one puts a dotted key on the
         * first screen anybody opens in that module.
         */
        /*
         * Every class that defines hubs, found by name, because neither obvious list is complete.
         * Globbing Support/Hubs/Modules misses the seven that live beside their own module (SHEQ
         * alone has 34 cards). Mkhaya Agri ships 40 hub classes.
         */
        $registry = $this->hubClasses();

        $this->assertGreaterThan(35, count($registry),
            'Hub definitions were not found, so this test is checking nothing.');

        $english = $this->englishKeys();
        $unresolved = [];

        foreach ($registry as $code => $class) {
            $this->assertTrue(class_exists($class), "{$class} (registered for {$code}) does not load.");

            foreach ($class::hubs() as $group => $hub) {
                $keys = [
                    'the hub heading' => $hub['title'] ?? null,
                    'the hub subtitle' => $hub['subtitle'] ?? null,
                ];

                foreach ($hub['cards'] ?? [] as $card) {
                    $keys["the '{$card['key']}' card title"] = $card['title'] ?? null;
                    $keys["the '{$card['key']}' card subtitle"] = $card['desc'] ?? null;
                }

                foreach ($keys as $what => $key) {
                    if ($key === null || array_key_exists($key, $english)) {
                        continue;
                    }

                    $short = substr($class, (int) strrpos($class, '\\') + 1);
                    $unresolved[] = "{$short}/{$group}: {$what} is {$key}, which resolves to nothing";
                }
            }
        }

        sort($unresolved);

        $this->assertSame([], $unresolved, "These hub cards show a dotted key instead of a label:\n  "
            .implode("\n  ", $unresolved)."\n");
    }

    public function test_every_ribbon_label_points_at_a_key_that_exists(): void
    {
        /*
         * `ribbonLabelKeys` in nav.ts maps a sidebar ribbon's path to a key that already exists
         * in its module's locale, so the sidebar entry cannot drift from the page's own heading.
         * The value is a key, not words, so a typo in it is silent: the ribbon falls back to the
         * English name from the database and stays English in all twelve languages.
         */
        $source = (string) file_get_contents(base_path('resources/js/utils/locales/nav.ts'));
        $start = strpos($source, 'ribbonLabelKeys');

        $this->assertNotFalse($start, 'nav.ts no longer declares ribbonLabelKeys.');

        $block = substr($source, $start, (int) strpos($source, "\n}", $start) - $start);

        preg_match_all("/^\s+([a-z0-9_]+):\s*'([^']+)'/m", $block, $matches, PREG_SET_ORDER);

        $this->assertNotEmpty($matches, 'No ribbon label mappings were parsed out of nav.ts.');

        $english = $this->englishKeys();
        $unresolved = [];

        foreach ($matches as [, $slug, $target]) {
            if (! array_key_exists($target, $english)) {
                $unresolved[] = "{$slug} -> {$target}  (nothing defines it, so the ribbon stays English)";
            }
        }

        sort($unresolved);

        $this->assertSame([], $unresolved, "These sidebar ribbons point at keys that do not exist:\n  "
            .implode("\n  ", $unresolved)."\n");
    }

    public function test_no_module_is_missing_from_the_screen_locale_entirely(): void
    {
        /*
         * The same defect counted the other way round, and the one that matters: a namespace a
         * component uses that the locale has never heard of. Fleet Job Cards was 144 distinct
         * keys under a namespace that did not exist, so it would have failed here by itself,
         * loudly, naming the module.
         */
        $referenced = $this->referencedKeys();
        $english = $this->englishKeys();
        $defined = [];

        foreach (array_keys($english) as $key) {
            $defined[strtok($key, '.')] = true;
        }

        foreach (array_keys(self::TYPESCRIPT_NAMESPACES) as $namespace) {
            $defined[$namespace] = true;
        }

        $counts = [];

        foreach ($referenced as $key => $file) {
            $namespace = strtok($key, '.');

            if (! isset($defined[$namespace])) {
                $counts[$namespace] ??= ['keys' => 0, 'file' => $file];
                $counts[$namespace]['keys']++;
            }
        }

        $absent = [];

        foreach ($counts as $namespace => $detail) {
            $absent[] = "{$namespace}: {$detail['keys']} keys used, nothing defined (e.g. {$detail['file']})";
        }

        sort($absent);

        $this->assertSame([], $absent,
            "These modules have no section in the screen locale, so every label on them is a dotted key:\n  "
            .implode("\n  ", $absent)."\n");
    }
}
