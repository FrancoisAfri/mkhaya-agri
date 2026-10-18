<?php

namespace Tests\Feature\Localisation;

use App\Http\Middleware\SetLocale;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Support\Facades\App;
use ReflectionClass;
use Tests\TestCase;

/**
 * The three lists that decide what language somebody sees have to agree.
 *
 * They are kept in three places and nothing connected them:
 *
 *  - `SUPPORTED_LOCALES` in `resources/js/i18n.ts` — what the language picker offers;
 *  - the directories under `lang/` — what the server can translate;
 *  - `SetLocale::SUPPORTED` — what the server will actually switch to.
 *
 * The third was short by seven. The picker offered isiXhosa, `lang/xh` existed, and the
 * middleware quietly refused to honour it, so the Vue half of the screen changed language and
 * the server-rendered half did not. Nothing failed; it just looked broken to the one person who
 * had chosen that language.
 */
class LocaleCoverageTest extends TestCase
{
    /** @return list<string> */
    private function middlewareLocales(): array
    {
        // Asked of the middleware rather than reflected out of a constant: the list is derived from
        // the `lang/` directories now, so that a language removed or restored updates the server
        // and the picker together instead of needing an edit in two places.
        $supported = SetLocale::supported();
        sort($supported);

        return $supported;
    }

    /** @return list<string> */
    private function langDirectories(): array
    {
        $dirs = array_map('basename', glob(base_path('lang').'/*', GLOB_ONLYDIR) ?: []);
        sort($dirs);

        return $dirs;
    }

    /** @return list<string> */
    /**
     * What the front end will offer, worked out the way the front end works it out.
     *
     * `SUPPORTED_LOCALES` used to be a literal array in i18n.ts and this read it with a regular
     * expression. It is discovered from the locale files on disk now — `import.meta.glob` resolved
     * at build time — so the honest way to predict it is to look at the same files.
     */
    private function pickerLocales(): array
    {
        $files = glob(resource_path('js/utils/locales/modules/*.json')) ?: [];
        $legacy = glob(resource_path('js/utils/locales/*.json')) ?: [];

        $locales = array_map(function (string $path) {
            $name = basename($path, '.json');

            // The one file whose name is not its language code.
            return $name === 'zulu' ? 'zu' : $name;
        }, array_merge($files, $legacy));

        $locales = array_values(array_unique(array_merge(['en'], $locales)));
        sort($locales);

        return $locales;
    }

    /**
     * The languages other than English that this install actually ships.
     *
     * Every comparison below is English against these. When the translation files have been removed
     * the list is empty and there is genuinely nothing to compare — so the tests skip and say why,
     * rather than looping over nothing and reporting a pass that guarantees nothing. Restore the
     * files and every one of them starts asserting again.
     *
     * @return list<string>
     */
    private function translatedLocales(): array
    {
        return array_values(array_diff($this->middlewareLocales(), ['en']));
    }

    private function skipWithoutTranslations(): void
    {
        if ($this->translatedLocales() === []) {
            $this->markTestSkipped('This install ships English only — there is no translation to compare it against.');
        }
    }

    public function test_the_picker_only_offers_languages_the_server_can_switch_to(): void
    {
        $this->assertSame($this->pickerLocales(), $this->middlewareLocales(),
            'The language picker and the server disagree about what is shipped. A locale the picker '
            .'offers but the middleware refuses gives that person a half-translated screen — and a '
            .'language whose Vue files were restored without its lang/ directory does exactly that.');
    }

    public function test_every_language_the_server_will_switch_to_has_translations_on_disk(): void
    {
        foreach ($this->middlewareLocales() as $locale) {
            $this->assertContains($locale, $this->langDirectories(),
                "SetLocale will switch to '{$locale}' but lang/{$locale} does not exist, so every "
                .'translated string would silently fall back to English.');
        }
    }

    public function test_every_shipped_language_has_the_same_translation_files_as_english(): void
    {
        $this->skipWithoutTranslations();

        $english = array_map('basename', glob(base_path('lang/en').'/*.php') ?: []);
        sort($english);

        foreach ($this->langDirectories() as $locale) {
            if ($locale === 'en') {
                continue;
            }

            $files = array_map('basename', glob(base_path("lang/{$locale}").'/*.php') ?: []);

            /*
             * A missing file is not a crash — Laravel falls back per key — so this is the only
             * thing that would ever report it. Reported as a difference rather than equality so
             * the failure names the file.
             */
            $this->assertSame([], array_values(array_diff($english, $files)),
                "lang/{$locale} is missing translation files that lang/en has.");
        }
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
     * Every translation file the installation ships, discovered rather than listed.
     *
     * Listed by hand it goes stale the first time somebody adds a module, and a file nobody
     * remembered to list is exactly the one that drifts.
     *
     * @return array<string, array{string}>
     */
    public static function translationFiles(): array
    {
        // A data provider is static and runs before the application boots, so `base_path()` is
        // not available here; the repository root is three directories up from this file.
        $files = glob(dirname(__DIR__, 3).'/lang/en/*.php') ?: [];

        return array_combine(
            array_map(fn (string $p) => basename($p, '.php'), $files),
            array_map(fn (string $p) => [basename($p, '.php')], $files),
        );
    }

    /**
     */
    #[DataProvider('translationFiles')]
    public function test_every_language_has_every_key_the_english_file_has(string $file): void
    {
        $this->skipWithoutTranslations();

        $english = $this->flatten(require base_path("lang/en/{$file}.php"));

        foreach ($this->middlewareLocales() as $locale) {
            if ($locale === 'en') {
                continue;
            }

            $translated = $this->flatten(require base_path("lang/{$locale}/{$file}.php"));

            // Laravel falls back per key, so a missing key is invisible — it just renders in
            // English next to translated text, which reads as a half-finished product.
            $this->assertSame([], array_values(array_diff(array_keys($english), array_keys($translated))),
                "lang/{$locale}/{$file}.php is missing keys that lang/en has.");
        }
    }

    /**
     * Every placeholder the English message substitutes, the translation substitutes too.
     *
     * Checked by actually rendering both through the translator rather than by matching `:(\w+)`
     * with a regex, because that regex is wrong: `waiting :counth` is `:count` followed by a
     * literal "h", and Laravel renders it "18h". A regex reads it as a placeholder named `counth`
     * and reports a translation that is perfectly correct. Laravel's own replacement logic is the
     * only thing that knows the difference, so it does the work here.
     *
     * Two failures matter, and rendering catches both:
     *   - a dropped placeholder, so the number or name never appears on screen;
     *   - a misspelt one (`:atribute`), which renders as literal text in the message.
     *
     */
    #[DataProvider('translationFiles')]
    public function test_every_translation_substitutes_what_the_english_message_substitutes(string $file): void
    {
        $this->skipWithoutTranslations();

        $english = $this->flatten(require base_path("lang/en/{$file}.php"));

        foreach ($this->middlewareLocales() as $locale) {
            if ($locale === 'en') {
                continue;
            }

            App::setLocale($locale);

            foreach ($english as $key => $message) {
                if (! is_string($message)) {
                    continue;
                }

                preg_match_all('/:(\w+)/', $message, $found);

                foreach (array_unique($found[1]) as $candidate) {
                    /*
                     * Every prefix of the captured word, because the capture may have swallowed
                     * trailing literal text. One of them is the real placeholder name, and
                     * Laravel tries the longest first, so passing all of them substitutes it
                     * exactly once either way.
                     */
                    $sentinel = '~~'.strtoupper($candidate).'~~';
                    $replacements = [];

                    for ($length = strlen($candidate); $length >= 1; $length--) {
                        $replacements[substr($candidate, 0, $length)] = $sentinel;
                    }

                    $renderedEnglish = trans("{$file}.{$key}", $replacements, 'en');

                    if (! str_contains($renderedEnglish, $sentinel)) {
                        continue;   // not a placeholder after all, just text after a colon
                    }

                    $this->assertStringContainsString($sentinel, trans("{$file}.{$key}", $replacements),
                        "lang/{$locale}/{$file}.php key '{$key}' never substitutes ':{$candidate}', "
                        .'so what the English message puts there is missing from the translation.');
                }

                // And nothing is left looking like an unsubstituted placeholder.
                $everything = [];
                foreach (array_unique($found[1]) as $candidate) {
                    for ($length = strlen($candidate); $length >= 1; $length--) {
                        $everything[substr($candidate, 0, $length)] = 'X';
                    }
                }

                $rendered = trans("{$file}.{$key}", $everything);

                $this->assertDoesNotMatchRegularExpression('/:[a-z_]{3,}/', (string) $rendered,
                    "lang/{$locale}/{$file}.php key '{$key}' still contains something that looks "
                    .'like a placeholder after substitution — most likely a misspelt one.');
            }
        }

        App::setLocale('en');
    }

    /**
     * The other direction: a key every translation has and English does not.
     *
     * This is the worse failure and the one nobody looks for. English is the fallback, so a key
     * missing from a *translation* degrades to readable English — but a key missing from
     * `lang/en` has nothing to fall back to, and Laravel prints the key itself. Budget delegation
     * and roll-up shipped that way: eleven languages read correctly and English showed
     * `budget_management.delegation.overlaps` under the form field.
     *
     */
    #[DataProvider('translationFiles')]
    public function test_english_has_every_key_the_translations_have(string $file): void
    {
        $this->skipWithoutTranslations();

        $english = $this->flatten(require base_path("lang/en/{$file}.php"));

        foreach ($this->middlewareLocales() as $locale) {
            if ($locale === 'en') {
                continue;
            }

            $path = base_path("lang/{$locale}/{$file}.php");

            if (! file_exists($path)) {
                continue;
            }

            $extra = array_values(array_diff(
                array_keys($this->flatten(require $path)), array_keys($english),
            ));

            $this->assertSame([], $extra,
                "lang/{$locale}/{$file}.php has keys lang/en does not. English is the fallback, so "
                .'these render as the raw translation key to an English user.');
        }
    }

    public function test_no_message_anywhere_renders_as_its_own_key(): void
    {
        $this->skipWithoutTranslations();

        /*
         * The end-to-end form of both parity checks. Asserted by resolving every key through the
         * translator in every language, because that is what the screen does — the files can line
         * up and a key still fail to resolve if a file is malformed or a group name collides.
         */
        foreach (array_keys(self::translationFiles()) as $file) {
            $keys = array_keys($this->flatten(require base_path("lang/en/{$file}.php")));

            foreach ($this->middlewareLocales() as $locale) {
                App::setLocale($locale);

                foreach ($keys as $key) {
                    $line = "{$file}.{$key}";
                    $this->assertNotSame($line, trans($line),
                        "'{$line}' does not resolve in '{$locale}' — it renders as its own key.");
                }
            }
        }

        App::setLocale('en');
    }

    public function test_a_validation_message_actually_renders_in_the_chosen_language(): void
    {
        $this->skipWithoutTranslations();

        // End to end rather than file-level: the file could be perfect and still not be reached,
        // which is exactly the bug this test class exists for.
        App::setLocale('xh');
        $this->assertStringContainsString('iyafuneka',
            trans('validation.required', ['attribute' => 'igama']));

        App::setLocale('nso');
        $this->assertStringContainsString('nyakega',
            trans('validation.required', ['attribute' => 'leina']));

        App::setLocale('en');
    }

    public function test_a_browser_asking_for_isixhosa_is_given_isixhosa(): void
    {
        /*
         * The regression itself: this once fell through to English because 'xh' was missing from
         * the middleware's list while lang/xh held translations all along.
         *
         * Skipped rather than deleted while the language is not shipped. This install carries
         * English only, so there is no isiXhosa to be given — but the moment lang/xh is restored
         * the assertion applies again, which is exactly when somebody needs it to.
         */
        if (! in_array('xh', SetLocale::supported(), true)) {
            $this->markTestSkipped('isiXhosa is not shipped by this install.');
        }

        $this->withHeader('Accept-Language', 'xh-ZA,xh;q=0.9')->get('/login');

        $this->assertSame('xh', App::getLocale());
    }

    public function test_a_browser_asking_for_a_language_we_do_not_ship_is_given_english(): void
    {
        $this->withHeader('Accept-Language', 'de-DE,de;q=0.9')->get('/login');

        $this->assertSame('en', App::getLocale());
    }
}
