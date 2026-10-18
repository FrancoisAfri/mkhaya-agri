<?php

namespace Tests\Feature\SupplierManagement;

use Tests\Support\ShipsLocales;
use Tests\TestCase;

/**
 * Every string the due-diligence screens speak, in every language the platform claims to speak.
 *
 * This module shipped English-only for a while and nothing appeared broken, because
 * `resources/js/i18n.ts` sets `fallbackLocale: "en"` — an isiZulu user simply read English. That is
 * exactly why drift here is worth a test rather than a reading pass: the failure is invisible until
 * somebody who does not read English uses the screen.
 *
 * The placeholder check is the one that bites. A missing key is loud — the dotted path renders on
 * screen. A dropped placeholder is silent: "{count} check(s) still need attention" becomes "check(s)
 * still need attention" and reads perfectly while having lost the number that was the point.
 */
class DueDiligenceTranslationsTest extends TestCase
{
    use ShipsLocales;

    /** Every locale this platform ships. */
    private const LOCALES = ['en', 'af', 'fr', 'zu', 'xh', 'nso', 'st', 'tn', 'ss', 've', 'ts', 'nr'];

    /** The subtrees the add-on owns. */
    private const SUBTREES = [
        'data_sources', 'verifications', 'ownership', 'risk', 'reputation', 'passport', 'monitoring',
    ];

    /** @return array<string, string> dotted key => string */
    private function flatten(array $tree, string $prefix = ''): array
    {
        $flat = [];

        foreach ($tree as $key => $value) {
            $dotted = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            $flat += is_array($value) ? $this->flatten($value, $dotted) : [$dotted => (string) $value];
        }

        return $flat;
    }

    /** @return array<string, string> the module's strings for one locale */
    private function strings(string $locale): array
    {
        $path = resource_path("js/utils/locales/modules/{$locale}.json");
        $this->assertFileExists($path, "Locale file for {$locale} is missing.");

        $json = json_decode(file_get_contents($path), true);
        $this->assertIsArray($json, "{$locale}.json is not valid JSON.");

        $module = $json['supplier_management'] ?? [];
        $owned = array_intersect_key($module, array_flip(self::SUBTREES));

        return $this->flatten($owned);
    }

    /** @return list<string> placeholder names in a string, sorted */
    private function placeholders(string $value): array
    {
        preg_match_all('/\{(\w+)\}/', $value, $matches);
        $names = $matches[1];
        sort($names);

        return $names;
    }

    public function test_every_locale_carries_every_key(): void
    {
        $english = $this->strings('en');
        $this->assertNotEmpty($english, 'English strings for the module are missing entirely.');

        foreach ($this->shippedLocales(self::LOCALES) as $locale) {
            $missing = array_diff(array_keys($english), array_keys($this->strings($locale)));

            $this->assertSame(
                [],
                array_values($missing),
                "{$locale} is missing ".count($missing)." due-diligence string(s); the first few are: "
                .implode(', ', array_slice(array_values($missing), 0, 5)),
            );
        }
    }

    public function test_no_translation_drops_or_invents_a_placeholder(): void
    {
        $english = $this->strings('en');

        foreach ($this->shippedLocales(self::LOCALES) as $locale) {
            foreach ($this->strings($locale) as $key => $value) {
                if (! isset($english[$key])) {
                    continue;
                }

                $this->assertSame(
                    $this->placeholders($english[$key]),
                    $this->placeholders($value),
                    "{$locale}.{$key} does not carry the same placeholders as the English string. "
                    ."English: \"{$english[$key]}\" — {$locale}: \"{$value}\"",
                );
            }
        }
    }

    public function test_no_translation_is_blank(): void
    {
        foreach ($this->shippedLocales(self::LOCALES) as $locale) {
            foreach ($this->strings($locale) as $key => $value) {
                $this->assertNotSame('', trim($value), "{$locale}.{$key} is blank.");
            }
        }
    }

    public function test_translations_are_written_in_a_latin_script(): void
    {
        // Not a style rule — a guard against characters drifting in from another keyboard layout,
        // which is how Cyrillic once reached a Tshivenda string and read as a plausible word.
        foreach ($this->shippedLocales(self::LOCALES) as $locale) {
            foreach ($this->strings($locale) as $key => $value) {
                $this->assertSame(
                    0,
                    preg_match('/[\x{0400}-\x{04FF}\x{0600}-\x{06FF}\x{4E00}-\x{9FFF}]/u', $value),
                    "{$locale}.{$key} contains characters from a non-Latin script: \"{$value}\"",
                );
            }
        }
    }

    public function test_the_navigation_labels_point_at_keys_that_exist(): void
    {
        // nav.ts maps a ribbon path to an existing page-title key rather than restating the words.
        // A typo there shows the raw English DB name in the sidebar and nothing else goes wrong.
        $navPath = resource_path('js/utils/locales/nav.ts');
        $this->assertFileExists($navPath);

        $nav = file_get_contents($navPath);
        $english = $this->strings('en');

        foreach (['suppliers_monitoring' => 'monitoring.title',
                  'suppliers_settings_data_sources' => 'data_sources.title'] as $slug => $key) {
            $this->assertStringContainsString(
                "{$slug}: 'supplier_management.{$key}'",
                $nav,
                "nav.ts does not map the {$slug} ribbon to its page title.",
            );
            $this->assertArrayHasKey($key, $english, "nav.ts points at {$key}, which does not exist.");
        }
    }
}
