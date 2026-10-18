<?php

namespace Tests\Feature\Onboarding;

use Tests\Support\ShipsLocales;
use Tests\TestCase;

/**
 * Every `t('…')` a page asks for exists in every language — English above all.
 *
 * The Onboarding module shipped with all 215 of its keys translated into eleven languages and
 * absent from English. English is the fallback locale, so vue-i18n had nothing to fall back to and
 * returned the key: an English user read "onboarding.add_task" on screen while a Zulu user read a
 * finished page. Nothing failed. `TranslationsTest` asserts lang/ ⊆ JSON, which is a different
 * claim entirely and passes happily while every English string is missing.
 *
 * The direction that bites is the one nobody checks. A missing Zulu key falls back to English and
 * merely reads untranslated; a missing English key has nowhere to fall and reads as machine output.
 */
class VueTranslationKeyTest extends TestCase
{
    use ShipsLocales;

    private const LOCALES = ['en', 'af', 'fr', 'zu', 'xh', 'nso', 'st', 'tn', 'ss', 've', 'ts', 'nr'];

    /**
     * Page directories under resources/js/Modules whose keys must resolve.
     *
     * Scoped to the modules this test was written for rather than the whole app: switching it on
     * everywhere at once would fail on pre-existing gaps in unrelated modules and get skipped.
     * Add a directory here when you have cleared it.
     */
    private const COVERED = ['Onboarding', 'PreBoarding', 'Offboarding'];

    public function test_every_key_a_page_asks_for_resolves_in_every_locale(): void
    {
        $messages = [];

        foreach ($this->shippedLocales(self::LOCALES) as $locale) {
            $messages[$locale] = json_decode(
                file_get_contents(resource_path("js/utils/locales/modules/{$locale}.json")),
                true
            );
        }

        $checked = 0;
        $failures = [];

        foreach (self::COVERED as $directory) {
            foreach ($this->vueFiles(resource_path("js/Modules/{$directory}")) as $file) {
                foreach ($this->keysUsedIn($file) as $key) {
                    $checked++;

                    foreach ($this->shippedLocales(self::LOCALES) as $locale) {
                        if ($this->lookup($messages[$locale], $key) === null) {
                            $failures[] = sprintf(
                                '%s → %s (%s)',
                                str_replace(resource_path('js/Modules/'), '', $file),
                                $key,
                                $locale
                            );
                        }
                    }
                }
            }
        }

        $this->assertGreaterThan(0, $checked, 'No translation keys found — has the regex drifted?');

        $this->assertSame(
            [],
            array_slice($failures, 0, 25),
            count($failures) . ' page translation keys do not resolve. A missing English key is the '
            . 'serious one: English is the fallback, so the user reads the key itself.'
        );
    }

    /**
     * Keys a page asks for, as `t('namespace.key')`.
     *
     * Deliberately literal-only. A computed key — `t(\`onboarding.status_${row.status}\`)` — cannot
     * be checked from source without knowing the data, and guessing at it would either miss real
     * gaps or invent failures for keys that are never requested.
     */
    private function keysUsedIn(string $file): array
    {
        preg_match_all(
            "/[\\\$ (>]t\('([a-z_]+\.[a-z_.]+)'/",
            file_get_contents($file),
            $matches
        );

        return array_values(array_unique($matches[1]));
    }

    private function lookup(array $messages, string $dotted): ?string
    {
        $node = $messages;

        foreach (explode('.', $dotted) as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return null;
            }

            $node = $node[$segment];
        }

        return is_string($node) && $node !== '' ? $node : null;
    }

    /** @return list<string> */
    private function vueFiles(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'vue') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
