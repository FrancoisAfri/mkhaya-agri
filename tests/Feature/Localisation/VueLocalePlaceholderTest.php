<?php

namespace Tests\Feature\Localisation;

use Tests\TestCase;

/**
 * Every placeholder the English screen string uses, the translation uses too.
 *
 * The Vue locales are JSON files the bundler imports, so nothing in the PHP test suite touched
 * them and this whole class of defect was invisible. It is not hypothetical: the Project
 * Management schedule had a filter chip reading "Critical only ({count})" whose eleven
 * translations all dropped the {count}, so in every language but English the chip never showed
 * how many items were on the critical path. Nothing failed and nothing looked wrong.
 *
 * The rule is the set of distinct placeholder *names*, not how many times each appears. A
 * vue-i18n plural string is pipe-separated forms, and a translation may legitimately write its
 * singular as a word — isiXhosa's "iprojekthi enye" is "one project" and is better than
 * "iprojekthi engu-1" — which changes the count without losing the placeholder.
 */
class VueLocalePlaceholderTest extends TestCase
{
    private const LOCALE_DIR = 'resources/js/utils/locales/modules';

    /** @return list<string> */
    /**
     * The screen locales this install actually ships, besides English.
     *
     * Read from disk rather than listed, so removing a language does not fail this test and
     * restoring one does not require remembering to add it back here. An empty result means English
     * only, and the test says so instead of comparing English against nothing.
     *
     * @return list<string>
     */
    private function locales(): array
    {
        $found = array_map(
            fn (string $path) => basename($path, '.json'),
            glob(base_path(self::LOCALE_DIR.'/*.json')) ?: [],
        );

        $others = array_values(array_diff($found, ['en']));
        sort($others);

        return $others;
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

    /** @return array<string,string> */
    private function load(string $locale): array
    {
        $path = base_path(self::LOCALE_DIR."/{$locale}.json");

        $this->assertFileExists($path, "The {$locale} screen locale file is missing.");

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded,
            "{$locale}.json is not valid JSON, so the whole bundle would fail to build.");

        return $this->flatten($decoded);
    }

    /** @return list<string> the distinct placeholder names, sorted */
    private function placeholders(string $message): array
    {
        preg_match_all('/\{(\w+)\}/', $message, $found);

        $names = array_values(array_unique($found[1]));
        sort($names);

        return $names;
    }

    public function test_every_translation_keeps_the_placeholders_of_the_english_string(): void
    {
        if ($this->locales() === []) {
            $this->markTestSkipped('This install ships English only — there are no placeholders to compare.');
        }

        $english = $this->load('en');
        $problems = [];

        foreach ($this->locales() as $locale) {
            $translated = $this->load($locale);

            foreach ($english as $key => $message) {
                if (! is_string($message)) {
                    continue;
                }

                $theirs = $translated[$key] ?? null;

                // A key the translation does not have falls back to English, which reads correctly.
                // A key it *does* have must carry the same placeholders.
                if (! is_string($theirs) || $theirs === $message) {
                    continue;
                }

                $expected = $this->placeholders($message);
                $actual = $this->placeholders($theirs);

                if ($expected !== $actual) {
                    $problems[] = sprintf("%s / %s\n    en: %s\n    %s: %s",
                        $locale, $key, $message, $locale, $theirs);
                }
            }
        }

        $this->assertSame([], $problems,
            "A translation drops or invents a placeholder, so what the English puts there is "
            ."missing or renders as literal braces:\n\n".implode("\n\n", $problems));
    }

    /**
     * The English screen locales both parse and carry the keys the others are checked against.
     *
     * A malformed JSON file here fails the bundle rather than a test, so this is a cheap guard
     * that the file the other assertions compare against is actually loadable.
     */
    public function test_the_english_screen_locale_loads(): void
    {
        $english = $this->load('en');

        $this->assertGreaterThan(10000, count($english),
            'modules/en.json came back far smaller than expected, so the comparison above would '
            .'silently check almost nothing.');
    }

    /*
     * Deliberately not asserted here: that every key in a translation also exists in
     * modules/en.json. The English message set is the union of locales/en.json and
     * modules/en.json (see the composition in resources/js/i18n.ts), so comparing against
     * modules/en.json alone reports tens of thousands of false positives. The equivalent
     * hazard — a key only the translations have, which renders as a raw dotted path to an
     * English reader — is covered for the PHP side by LocaleCoverageTest; on the screen side
     * the extras were checked by hand and are all runtime-built lookups guarded by `te()`.
     */
}
