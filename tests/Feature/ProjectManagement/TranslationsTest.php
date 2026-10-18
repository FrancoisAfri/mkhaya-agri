<?php

namespace Tests\Feature\ProjectManagement;

use Tests\Support\ShipsLocales;
use Tests\TestCase;

/**
 * The module's strings resolve on both sides (PM i18n).
 *
 * Worth a test of its own because the failure is silent and ugly: `__()` reads lang/, the browser
 * reads the JSON under resources/js, and a key present in one but not the other shows the user
 * "project_management.flash.project_created" instead of "Project created." Nothing throws, no test
 * fails, and it reaches production looking like a broken build.
 */
class TranslationsTest extends TestCase
{
    use ShipsLocales;

    private const LOCALES = ['en', 'af', 'fr', 'zu', 'xh', 'nso', 'st', 'tn', 'ss', 've', 'ts', 'nr'];

    /** Every `__('project_management.…')` in the module has something behind it. */
    public function test_every_server_side_key_resolves_in_every_locale(): void
    {
        $keys = $this->serverKeys();

        $this->assertNotEmpty($keys, 'No PM translation keys found — has the grep drifted?');

        foreach ($this->shippedLocales(self::LOCALES) as $locale) {
            app()->setLocale($locale);

            foreach ($keys as $key) {
                $this->assertNotSame(
                    $key,
                    __($key),
                    "'{$key}' has no {$locale} translation, so the user would see the key itself."
                );
            }
        }

        app()->setLocale('en');
    }

    /** The generated PHP files and the JSON the browser loads have to agree on what exists. */
    public function test_the_php_and_json_locales_carry_the_same_keys(): void
    {
        foreach ($this->shippedLocales(self::LOCALES) as $locale) {
            $php = require lang_path("{$locale}/project_management.php");
            $json = json_decode(
                file_get_contents(resource_path("js/utils/locales/modules/{$locale}.json")),
                true
            )['project_management'] ?? [];

            foreach ($php as $section => $entries) {
                foreach (array_keys($entries) as $key) {
                    $this->assertArrayHasKey(
                        $key,
                        $json[$section] ?? [],
                        "{$locale}: '{$section}.{$key}' is in lang/ but not in the JSON the browser reads."
                    );
                }
            }
        }
    }

    public function test_a_message_with_placeholders_actually_substitutes_them(): void
    {
        // The JSON uses {count}; the generated PHP has to use :count or Laravel leaves it verbatim.
        $rendered = __('project_management.flash.tasks_bulk_updated', ['count' => 7]);

        $this->assertStringContainsString('7', $rendered);
        $this->assertStringNotContainsString(':count', $rendered);
        $this->assertStringNotContainsString('{count}', $rendered);
    }

    /**
     * @return list<string>
     *
     * Views are searched as well as classes. A PDF is rendered outside the request that asked for
     * it, and the contract template printed raw keys — "project_management.contractors.start_date"
     * — onto a legal document because those labels existed only in the JSON the browser reads.
     * Nothing caught it: this grep used to look at app/ alone.
     */
    private function serverKeys(): array
    {
        $found = [];

        $roots = [
            app_path('Modules/Operations/ProjectManagement'),
            resource_path('views/pdf/contracts'),
        ];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            foreach ($this->phpFiles($root) as $file) {
                preg_match_all("/__\('(project_management\.[a-z_.]+)'/", file_get_contents($file), $matches);
                $found = array_merge($found, $matches[1]);
            }
        }

        return array_values(array_unique($found));
    }

    /** @return list<string> */
    private function phpFiles(string $directory): array
    {
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory)) as $file) {
            // .blade.php reports its extension as 'php', so both are covered by this one test.
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
