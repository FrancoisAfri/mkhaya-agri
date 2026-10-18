<?php

namespace Tests\Feature\ProcurementGovernance;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Tests\Support\ShipsLocales;
use Tests\TestCase;

/**
 * Every message this module speaks, in every language it claims to speak.
 *
 * The client side was fully translated long before the server side was, which produced the specific
 * failure this suite exists to prevent: an interface entirely in isiZulu that answered in English
 * the moment something went wrong. A refusal nobody can read is indistinguishable from a broken
 * system, and it lands precisely when the user is least able to guess.
 *
 * Three things are checked, and the second is the one that bites. A key missing from a locale renders
 * its own dotted path to the user — "procurement_governance.rules.opening.needs_two" on screen. A
 * *placeholder* dropped in translation is worse, because the sentence still reads naturally and
 * simply states the wrong thing: ":count sealed bids opened" becomes "sealed bids opened" and nobody
 * notices the number is gone.
 */
class TranslationsTest extends TestCase
{
    use ShipsLocales;

    private const FILE = 'procurement_governance';

    /** Every locale this platform ships. */
    private const LOCALES = ['zu', 'af', 'fr', 'xh', 'ss', 'nr', 'st', 'tn', 'nso', 'ts', 've'];

    /** @return array<string, string> dotted key => string */
    private function flatten(array $tree, string $prefix = ''): array
    {
        $flat = [];

        foreach ($tree as $key => $value) {
            $dotted = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                $flat += $this->flatten($value, $dotted);
            } else {
                $flat[$dotted] = (string) $value;
            }
        }

        return $flat;
    }

    /** @return array<string, string> */
    private function messages(string $locale): array
    {
        $path = lang_path("{$locale}/".self::FILE.'.php');

        return File::exists($path) ? $this->flatten(require $path) : [];
    }

    /** @return list<string> the :placeholders a string carries */
    private function placeholders(string $message): array
    {
        preg_match_all('/:([a-z_]+)/', $message, $matches);

        $found = $matches[1];
        sort($found);

        return array_values(array_unique($found));
    }

    public function test_the_english_source_exists_and_is_not_trivial(): void
    {
        // A guard on everything below: if the English file went missing, every comparison would
        // pass over an empty set and prove nothing.
        $this->assertGreaterThan(100, count($this->messages('en')));
    }

    public function test_every_locale_carries_every_key(): void
    {
        $english = $this->messages('en');
        $gaps = [];

        foreach ($this->shippedLocales(self::LOCALES) as $locale) {
            $missing = array_diff(array_keys($english), array_keys($this->messages($locale)));

            if ($missing !== []) {
                $gaps[$locale] = array_slice(array_values($missing), 0, 5);
            }
        }

        $this->assertSame([], $gaps, 'These locales are missing keys and would show the raw key path: '.json_encode($gaps));
    }

    public function test_no_locale_invents_a_key_the_module_never_uses(): void
    {
        // An extra key is dead weight that outlives the string it was written for, and it quietly
        // makes the parity count look right while a real key is missing.
        $english = $this->messages('en');
        $extra = [];

        foreach ($this->shippedLocales(self::LOCALES) as $locale) {
            $surplus = array_diff(array_keys($this->messages($locale)), array_keys($english));

            if ($surplus !== []) {
                $extra[$locale] = array_values($surplus);
            }
        }

        $this->assertSame([], $extra, 'These locales define keys English does not: '.json_encode($extra));
    }

    public function test_every_translation_keeps_its_placeholders(): void
    {
        /*
         * The quiet failure. A translated string that loses :count still reads as a sentence, so it
         * passes review and ships — and then tells a committee that "sealed bids opened" without
         * saying how many. A string that gains a placeholder is worse still: Laravel leaves it
         * untouched, so the user sees the literal ":name" on screen.
         */
        $english = $this->messages('en');
        $broken = [];

        foreach ($this->shippedLocales(self::LOCALES) as $locale) {
            foreach ($this->messages($locale) as $key => $message) {
                if (! isset($english[$key])) {
                    continue;
                }

                $expected = $this->placeholders($english[$key]);
                $actual = $this->placeholders($message);

                if ($expected !== $actual) {
                    $broken[] = sprintf(
                        '%s %s: expected [%s], found [%s]',
                        $locale,
                        $key,
                        implode(', ', $expected),
                        implode(', ', $actual),
                    );
                }
            }
        }

        $this->assertSame([], $broken, "Placeholders were lost or invented in translation:\n".implode("\n", $broken));
    }

    public function test_every_key_the_code_asks_for_resolves_in_every_locale(): void
    {
        // The parity checks above compare files to each other. This one compares them to what the
        // module actually calls, which is the only comparison a user experiences.
        $used = [];

        foreach (File::allFiles(base_path('app/Modules/Procurement/Governance')) as $file) {
            preg_match_all("/__\('(procurement_governance\.[a-z_.]+)'/", $file->getContents(), $matches);

            foreach ($matches[1] as $key) {
                $used[$key] = true;
            }
        }

        $this->assertNotEmpty($used, 'No translated messages were found in the module at all.');

        $unresolved = [];
        $original = App::getLocale();

        foreach (array_merge(['en'], $this->shippedLocales(self::LOCALES)) as $locale) {
            App::setLocale($locale);

            foreach (array_keys($used) as $key) {
                if (trans($key) === $key) {
                    $unresolved[] = "{$locale}: {$key}";
                }
            }
        }

        App::setLocale($original);

        $this->assertSame([], $unresolved, "These messages would render as their key path:\n".implode("\n", $unresolved));
    }

    public function test_no_server_message_is_left_as_an_english_literal(): void
    {
        /*
         * Catches the next one added rather than the ones already fixed.
         *
         * A flash message or domain rule written as a literal is invisible in review — it works, it
         * reads well, and it is only wrong for the users who do not speak English. Matching on a
         * quoted run of three or more words is crude, but it is the difference between finding this
         * now and finding it when a customer reports it.
         */
        $offenders = [];

        /*
         * Matched at the message argument itself rather than by scanning the surrounding code.
         *
         * A looser scan picks up the replacement array of a perfectly good __() call — the
         * 'from' => strtoupper($from) of a translated string reads as a quoted fragment — and a test
         * that cries wolf is a test people stop reading.
         */
        $patterns = [
            "/->with\(\s*'(?:success|error)'\s*,\s*'([^']{15,})'/",
            "/DomainRuleException::forField\(\s*[^,()]+,\s*'([^']{15,})'/s",
        ];

        foreach (File::allFiles(base_path('app/Modules/Procurement/Governance')) as $file) {
            $source = $file->getContents();

            foreach ($patterns as $pattern) {
                preg_match_all($pattern, $source, $matches);

                foreach ($matches[1] as $literal) {
                    if (str_starts_with($literal, 'procurement_governance')) {
                        continue;
                    }

                    // Spaces, not word count: a flash *key* like 'bid_portal_link' counts as three
                    // words to str_word_count, which splits on underscores.
                    if (substr_count($literal, ' ') >= 2) {
                        $offenders[] = $file->getRelativePathname().': '.mb_substr($literal, 0, 60);
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These messages are hardcoded English and would not translate:\n".implode("\n", $offenders),
        );
    }
}
