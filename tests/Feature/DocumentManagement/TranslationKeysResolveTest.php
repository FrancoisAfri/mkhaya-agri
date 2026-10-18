<?php

namespace Tests\Feature\DocumentManagement;

use Tests\Support\ShipsLocales;
use Tests\TestCase;

/**
 * Every string the module asks for has something behind it.
 *
 * vue-i18n renders a key it cannot find as the key itself, so a missing entry is not a blank space
 * or an error — it is `document_management.settings_page.watermark_previews` printed across the
 * settings page in place of a label. Thirty of them shipped that way, because they were written at
 * the module's top level while the screen that reads them resolves under `settings_page.`, and
 * nothing anywhere said so.
 *
 * Each screen declares its own prefix in its `d()` helper, and that prefix is the whole trap: two
 * files sitting side by side read from different places in the same file, and a key that works in
 * one renders raw in the other.
 *
 * Read from en.json rather than from Laravel's `lang/` directory. Two separate sets of translations
 * exist — the PHP ones for server-side messages, the JSON ones for the screens — and this is about
 * the screens.
 */
class TranslationKeysResolveTest extends TestCase
{
    use ShipsLocales;

    /** @var array<string, mixed>|null */
    private ?array $english = null;

    public function test_every_key_a_screen_asks_for_exists(): void
    {
        $missing = [];

        foreach ($this->componentFiles() as $file) {
            $source = (string) file_get_contents($file);
            $prefix = $this->prefixOf($source);

            if ($prefix === null) {
                continue;
            }

            /*
             * A literal key, and not one being concatenated. `d('event_' + action)` is a stem
             * chosen at runtime; treating it as a key would report a miss for something that is
             * never asked for in that form.
             */
            preg_match_all("/\bd\('([a-z0-9_]+)'(?!\s*\+)/", $source, $matches);

            foreach (array_unique($matches[1]) as $key) {
                if (! $this->exists($prefix . $key)) {
                    $missing[] = basename($file) . ' → ' . $prefix . $key;
                }
            }
        }

        sort($missing);

        $this->assertSame(
            [],
            $missing,
            "These render as the key itself, in the middle of the page:\n  " . implode("\n  ", $missing),
        );
    }

    public function test_the_keys_built_at_runtime_have_every_ending_they_can_take(): void
    {
        /*
         * The half the check above cannot see. `d('requests_status_' + request.status)` resolves to
         * one of three keys and the page cannot say which; a missing one of the three shows up only
         * when a request happens to be in that state.
         *
         * Each stem is listed with the endings the code can actually produce, so adding a fourth
         * status without its label fails here rather than in front of somebody.
         */
        $expected = [
            'requests_status_' => ['pending', 'fulfilled', 'cancelled'],
            'quotas_scope_' => ['department', 'folder', 'user'],
            'folder_subject_' => ['group', 'role', 'department', 'user'],
            'confidentiality_' => ['public', 'internal', 'confidential', 'restricted'],
            'access_map_type_' => ['document', 'folder', 'group', 'membership'],
        ];

        $missing = [];

        foreach ($expected as $stem => $endings) {
            foreach ($endings as $ending) {
                $key = 'document_management.' . $stem . $ending;

                if (! $this->exists($key)) {
                    $missing[] = $key;
                }
            }
        }

        $this->assertSame([], $missing, "Built at runtime and missing:\n  " . implode("\n  ", $missing));
    }

    public function test_every_language_has_what_english_has(): void
    {
        /*
         * A half-translated screen is worse than an untranslated one: an English label among eleven
         * Zulu ones reads as a bug rather than as a gap, and nobody can tell which of the two it is.
         */
        $english = $this->flatten($this->english());
        $gaps = [];

        foreach ($this->shippedLocales(['af', 'zu', 'xh', 'st', 'tn', 'nso', 'ts', 've', 'ss', 'nr', 'fr']) as $locale) {
            $theirs = $this->flatten($this->localeFor($locale));
            $short = array_diff($english, $theirs);

            if ($short !== []) {
                $gaps[] = $locale . ' is missing ' . count($short) . ' (' . implode(', ', array_slice($short, 0, 3)) . ')';
            }
        }

        $this->assertSame([], $gaps, implode("\n  ", $gaps));
    }

    public function test_no_screen_string_uses_the_wrong_placeholder_style(): void
    {
        /*
         * Laravel writes `:name`, vue-i18n writes `{name}`, and the two live in different files for
         * the same module. Fourteen client-side strings were written in Laravel's style and printed
         * "Filed in :folder" on screen — a mistake that is invisible in review because both look
         * like a placeholder.
         */
        $offenders = [];

        foreach ($this->flattenWithValues($this->english()) as $key => $value) {
            if (preg_match('/(?<![\w:]):[a-z][a-z_]{2,}/', $value)) {
                $offenders[] = $key . ': ' . $value;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These use Laravel's :placeholder where vue-i18n needs {placeholder}:\n  " . implode("\n  ", $offenders),
        );
    }

    public function test_the_guard_is_actually_reading_the_screens(): void
    {
        // A regex that stopped matching would let all of the above pass in silence.
        $seen = 0;

        foreach ($this->componentFiles() as $file) {
            $source = (string) file_get_contents($file);

            if ($this->prefixOf($source) !== null) {
                preg_match_all("/\bd\('([a-z0-9_]+)'/", $source, $matches);
                $seen += count($matches[1]);
            }
        }

        $this->assertGreaterThan(400, $seen, 'The key-extraction pattern no longer matches this module.');
    }

    /** The prefix a screen resolves its keys under, from its own `d()` helper. */
    private function prefixOf(string $source): ?string
    {
        return preg_match("/const d = \([^)]*\)\s*=>\s*t\('([^']+)'\s*\./", $source, $m)
            ? $m[1] . '.'
            : (preg_match("/const d = \([^)]*\)\s*=>\s*t\('([^']+)'\s*\+/", $source, $m) ? $m[1] : null);
    }

    private function exists(string $dotted): bool
    {
        $node = $this->english();

        // The tree is already the module's own sub-tree, so the module name in the dotted key is
        // the caller's prefix rather than a level to descend into.
        $dotted = preg_replace('/^document_management\./', '', $dotted);

        foreach (explode('.', (string) $dotted) as $part) {
            if (! is_array($node) || ! array_key_exists($part, $node)) {
                return false;
            }

            $node = $node[$part];
        }

        return is_string($node);
    }

    /** @return array<string, mixed> */
    private function english(): array
    {
        return $this->english ??= $this->localeFor('en');
    }

    /** @return array<string, mixed> */
    private function localeFor(string $locale): array
    {
        $path = resource_path("js/utils/locales/modules/{$locale}.json");

        return json_decode((string) file_get_contents($path), true)['document_management'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $tree
     * @return list<string>
     */
    private function flatten(array $tree, string $prefix = ''): array
    {
        $keys = [];

        foreach ($tree as $key => $value) {
            $keys = is_array($value)
                ? array_merge($keys, $this->flatten($value, $prefix . $key . '.'))
                : array_merge($keys, [$prefix . $key]);
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $tree
     * @return array<string, string>
     */
    private function flattenWithValues(array $tree, string $prefix = ''): array
    {
        $flat = [];

        foreach ($tree as $key => $value) {
            if (is_array($value)) {
                $flat += $this->flattenWithValues($value, $prefix . $key . '.');
            } elseif (is_string($value)) {
                $flat[$prefix . $key] = $value;
            }
        }

        return $flat;
    }

    /** @return list<string> */
    private function componentFiles(): array
    {
        $files = [];

        foreach (['Pages', 'Components'] as $directory) {
            foreach (glob(resource_path('js/Modules/DocumentManagement/' . $directory . '/*.vue')) ?: [] as $file) {
                $files[] = $file;
            }
        }

        return $files;
    }
}
