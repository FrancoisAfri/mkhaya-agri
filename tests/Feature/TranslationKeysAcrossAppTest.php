<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Every string any screen asks for has something behind it.
 *
 * vue-i18n renders a key it cannot find as the key itself, so a missing entry is not a blank space
 * or an error — it is `crm_sequences_edit.enrollments.statuses.active` printed in the middle of a
 * table where a status chip should be. That is how this was found: somebody looked at the screen.
 *
 * The module-scoped version of this test already guards Document Management. It is generalised here
 * because the same fault was sitting in CRM, Compliance, Conferences, HR, LMS, Content Marketing,
 * Supplier Management and the ribbon editor — nine modules, none of which had a guard, and every one
 * of them shipped looking finished.
 *
 * Two shapes of call, and the second is the one that bites.
 *
 *   t('a.b.c')            — a literal key; checkable directly.
 *   t(`a.b.${row.value}`) — the leaf is chosen at runtime from data. The group has to exist even
 *                           though this test cannot know which leaf will be asked for, and when the
 *                           group is missing entirely *every* row renders its own key.
 *
 * A third case is not a missing translation at all: a page translating a free-text column can never
 * be exhaustive, because the data is not a fixed set. Those pages fall back to the value itself, and
 * are listed below so the exemption is a decision on the record rather than a silent gap.
 */
class TranslationKeysAcrossAppTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $merged = null;

    /**
     * Namespaces that live in `.ts` locale files rather than the JSON.
     *
     * finance.ts, accounting.ts, bureau.ts, nav.ts and dashboard.ts are merged in by i18n.ts at
     * runtime. This test reads the JSON, so it cannot see them and must not report them.
     */
    private const TS_NAMESPACES = ['fin', 'acc', 'bureau', 'nav', 'home_page'];

    /**
     * Groups whose leaf is a free-text value rather than one of a fixed set.
     *
     * A consent's `purpose` is a varchar somebody types; a breach's subject type is a hardcoded
     * English label like "Former Employee", which could never be a sane key. Both pages now show
     * the value when no translation exists, which is the only honest thing to do with open data.
     */
    private const OPEN_ENDED = [
        'compliance_consents.purposes',
        'compliance_breaches.index.subject_types',
    ];

    public function test_no_screen_asks_for_a_key_that_does_not_exist(): void
    {
        $missing = [];

        foreach ($this->screens() as $file) {
            $source = (string) file_get_contents($file);

            preg_match_all("/\bt\(\s*'([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+)'/", $source, $matches);

            foreach (array_unique($matches[1]) as $key) {
                // A key ending in an underscore is a stem being concatenated with a value.
                if ($this->isTypeScriptNamespace($key) || str_ends_with($key, '_')) {
                    continue;
                }

                if (! $this->resolvesToString($key)) {
                    $missing[] = $this->shorten($file) . ' → ' . $key;
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

    public function test_every_group_a_screen_builds_a_key_from_exists(): void
    {
        /*
         * The half that cannot be checked leaf by leaf. `t(`x.y.${row.status}`)` is only correct if
         * `x.y` is a group — when it is absent, every row on the page prints its own dotted key,
         * which is exactly what a CRM sequence's enrolment table was doing for all 300 rows.
         */
        $missing = [];

        foreach ($this->screens() as $file) {
            $source = (string) file_get_contents($file);

            preg_match_all('/\bt\(\s*`([a-z][a-z0-9_.]*?)\.\$\{/', $source, $matches);

            foreach (array_unique($matches[1]) as $stem) {
                if ($this->isTypeScriptNamespace($stem) || in_array($stem, self::OPEN_ENDED, true)) {
                    continue;
                }

                if (! $this->resolvesToGroup($stem)) {
                    $missing[] = $this->shorten($file) . ' → ' . $stem . '.*';
                }
            }
        }

        sort($missing);

        $this->assertSame(
            [],
            array_values(array_unique($missing)),
            "These build a key from a value, and the group behind it is missing, so every row\n"
                . "renders its own key:\n  " . implode("\n  ", array_unique($missing)),
        );
    }

    public function test_a_page_translating_free_text_falls_back_to_the_value(): void
    {
        /*
         * The exemption above is only safe while those pages actually degrade to the value. If
         * somebody removes the fallback, the exemption would go on hiding a page full of raw keys —
         * so the exemption checks itself.
         */
        $unguarded = [];

        foreach (self::OPEN_ENDED as $group) {
            $namespace = explode('.', $group)[0];

            foreach ($this->screens() as $file) {
                $source = (string) file_get_contents($file);

                if (! str_contains($source, $group . '.${')) {
                    continue;
                }

                /*
                 * The interpolation must go through `label(key, fallback)`, never straight to `t(`.
                 * Looking merely for `te(` somewhere in the file is not enough — the helper's own
                 * definition contains one, so a page could drop the guard at the call site and
                 * still look protected. Naming the helper makes the guard visible at the call site.
                 */
                if (str_contains($source, 't(`' . $group . '.${')
                    && ! str_contains($source, 'label(`' . $group . '.${')) {
                    $unguarded[] = $this->shorten($file) . ' hands ' . $namespace
                        . ' free text straight to t(), so it renders the key when no translation exists';
                }
            }
        }

        $this->assertSame([], array_values(array_unique($unguarded)), implode("\n  ", array_unique($unguarded)));
    }

    /**
     * The languages this install ships besides English.
     *
     * Read from disk rather than listed. An install carrying English only has nothing to compare,
     * and the tests below say that plainly instead of reading a file that is not there.
     *
     * @return list<string>
     */
    private function shippedLocales(): array
    {
        $found = array_map(
            fn (string $path) => basename($path, '.json'),
            glob(resource_path('js/utils/locales/modules/*.json')) ?: [],
        );

        $others = array_values(array_diff($found, ['en']));
        sort($others);

        return $others;
    }

    public function test_every_language_has_what_english_has(): void
    {
        if ($this->shippedLocales() === []) {
            $this->markTestSkipped('This install ships English only — there is nothing to compare it against.');
        }

        /*
         * A screen half in one language and half in another reads as a fault rather than as a gap,
         * and nobody can tell which of the two it is.
         */
        $english = $this->flatten($this->localeFor('en'));
        $gaps = [];

        foreach (['af', 'zu', 'xh', 'st', 'tn', 'nso', 'ts', 've', 'ss', 'nr', 'fr'] as $locale) {
            $short = array_diff($english, $this->flatten($this->localeFor($locale)));

            if ($short !== []) {
                $gaps[] = $locale . ' is missing ' . count($short)
                    . ' (' . implode(', ', array_slice($short, 0, 3)) . ')';
            }
        }

        $this->assertSame([], $gaps, implode("\n  ", $gaps));
    }

    public function test_the_zulu_backlog_stays_closed(): void
    {
        if (! in_array('zu', $this->shippedLocales(), true)) {
            $this->markTestSkipped('isiZulu is not shipped by this install, so its backlog cannot regress.');
        }

        /*
         * isiZulu carried a 1,308-key backlog — whole namespaces (conferences, e-sign, resource
         * reports, email campaigns) that were never translated into it, while the other ten
         * languages had them. It is closed, and this holds it closed: the check above would catch a
         * regression anyway, but the number here says plainly that nought is the expected state
         * rather than a coincidence nobody has looked at.
         */
        $backlog = count(array_diff(
            $this->flatten($this->localeFor('en')),
            $this->flatten($this->localeFor('zu')),
        ));

        $this->assertSame(0, $backlog, "isiZulu has fallen behind English again by {$backlog} keys.");
    }

    public function test_the_guard_is_actually_reading_the_screens(): void
    {
        // A regex that stopped matching would let every assertion above pass in silence.
        $seen = 0;

        foreach ($this->screens() as $file) {
            preg_match_all("/\bt\(\s*['`]/", (string) file_get_contents($file), $matches);
            $seen += count($matches[0]);
        }

        $this->assertGreaterThan(5000, $seen, 'The key-extraction pattern no longer matches this app.');
    }

    // ── Reading the locales ──────────────────────────────────────────────────

    private function resolvesToString(string $dotted): bool
    {
        $node = $this->walk($dotted);

        return is_string($node);
    }

    private function resolvesToGroup(string $dotted): bool
    {
        $node = $this->walk($dotted);

        return is_array($node) && $node !== [];
    }

    private function walk(string $dotted): mixed
    {
        $node = $this->merged();

        foreach (explode('.', $dotted) as $part) {
            if (! is_array($node) || ! array_key_exists($part, $node)) {
                return null;
            }

            $node = $node[$part];
        }

        return $node;
    }

    private function isTypeScriptNamespace(string $key): bool
    {
        foreach (self::TS_NAMESPACES as $namespace) {
            if ($key === $namespace || str_starts_with($key, $namespace . '.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * What a screen actually sees: the base locale with the per-module file merged over it.
     *
     * i18n.ts deep-merges the two, so a key present in either resolves and checking only one of
     * them would report misses that do not happen.
     *
     * @return array<string, mixed>
     */
    private function merged(): array
    {
        return $this->merged ??= $this->deepMerge(
            $this->jsonAt(resource_path('js/utils/locales/en.json')),
            $this->jsonAt(resource_path('js/utils/locales/modules/en.json')),
        );
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    private function deepMerge(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            $base[$key] = is_array($value) && isset($base[$key]) && is_array($base[$key])
                ? $this->deepMerge($base[$key], $value)
                : $value;
        }

        return $base;
    }

    /** @return array<string, mixed> */
    private function localeFor(string $locale): array
    {
        return $this->jsonAt(resource_path("js/utils/locales/modules/{$locale}.json"));
    }

    /** @return array<string, mixed> */
    private function jsonAt(string $path): array
    {
        return json_decode((string) file_get_contents($path), true) ?: [];
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

    /** @return list<string> */
    private function screens(): array
    {
        $files = [];
        $root = resource_path('js');

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'vue') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function shorten(string $path): string
    {
        return str_replace(resource_path('js') . '/', '', $path);
    }
}
