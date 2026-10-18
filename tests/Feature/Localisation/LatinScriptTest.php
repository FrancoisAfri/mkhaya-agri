<?php

namespace Tests\Feature\Localisation;

use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Every language this product ships is written in Latin script, so a character that is not is a typo.
 *
 * This is the one translation defect no other sweep can see. A Cyrillic 'е' and a Latin 'e'
 * render identically in every font, so the screen looks right. Key parity passes, because the
 * key is there. The "English in every locale" sweep passes, because the string was translated.
 * The missing-key sweep passes, because nothing is missing. It surfaces months later, when
 * somebody searches the page for a word they can plainly see and is told there are no matches —
 * or when a report groups what should be one value into two.
 *
 * It gets in by the ordinary route: a phrase pasted out of a browser, a PDF or a chat window,
 * where one letter came from a different keyboard layout. I put one into an isiXhosa string
 * myself and only caught it by running a script over the file afterwards; a script somebody has
 * to remember to run is not a guard, so it lives here now.
 *
 * Common and Inherited are allowed alongside Latin because a few characters that are legitimately
 * part of Latin text — the micro sign, combining accents — carry no script of their own. One Greek
 * letter is allowed by name: Δ is read as "change in" in every one of these languages, and the
 * analytics screens use it as the mathematical symbol it is, not as a letter.
 */
class LatinScriptTest extends TestCase
{
    /** @return list<array{string}> */
    public static function translationFiles(): array
    {
        // Spelled out from this file's own location rather than through `base_path()`: a data
        // provider is collected before the application boots, and the helper is not there yet.
        $root = dirname(__DIR__, 3);

        $files = glob($root.'/resources/js/utils/locales/*.json') ?: [];
        $files = array_merge($files, glob($root.'/resources/js/utils/locales/modules/*.json') ?: []);
        $files = array_merge($files, glob($root.'/resources/js/utils/locales/*.ts') ?: []);

        /*
         * The PHP side counts too. A flash message is translated in `lang/`, and a bad character
         * there is just as invisible — more so, because nobody reads a flash twice.
         */
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'/lang', RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $entry) {
            if ($entry->isFile() && in_array($entry->getExtension(), ['php', 'json'], true)) {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return array_map(fn (string $path) => [$path], $files);
    }

    #[DataProvider('translationFiles')]
    public function test_a_translation_file_contains_no_non_latin_letters(string $path): void
    {
        $offences = $this->nonLatinLetters((string) file_get_contents($path));

        $this->assertSame([], $offences, sprintf(
            "%s contains %d letter(s) outside Latin script. These are invisible on screen and "
            ."break search and grouping on the affected value:\n  %s",
            str_replace(dirname(__DIR__, 3).'/', '', $path), count($offences), implode("\n  ", $offences),
        ));
    }

    /** The detector has to be known to work, or an empty result means nothing. */
    public function test_the_check_catches_a_cyrillic_look_alike(): void
    {
        // "Isic\u{0435}lo" — a Cyrillic IE where an 'e' belongs. Written as an escape on purpose:
        // pasted literally into this file, the next person to read it could not tell either.
        $planted = "{\"sheq.x\": \"Isic\u{0435}lo esifakwe\"}";

        $found = $this->nonLatinLetters($planted);

        $this->assertCount(1, $found);
        $this->assertStringContainsString('CYRILLIC', $found[0]);
        $this->assertStringContainsString('Isic', $found[0], 'The report must locate the word.');

        // And it does not cry wolf over the Latin spelling, or over characters that are part of
        // ordinary Latin text without being Latin themselves.
        $this->assertSame([], $this->nonLatinLetters('{"a": "Isicelo esifakwe 50 µg, café, Δ 4%"}'));
    }

    /**
     * @return list<string> one line per offending letter, with the word it sits in
     */
    private function nonLatinLetters(string $source): array
    {
        /*
         * Allowed deliberately, and listed one at a time rather than by opening up the Greek
         * block: Δ is a symbol here, and every other Greek letter in a South African translation
         * would still be a mistake.
         */
        $symbols = ['Δ'];

        preg_match_all('/\p{L}/u', $source, $matches, PREG_OFFSET_CAPTURE);

        $offences = [];

        foreach ($matches[0] as [$letter, $offset]) {
            if (in_array($letter, $symbols, true)
                || preg_match('/^[\p{Latin}\p{Common}\p{Inherited}]$/u', $letter)) {
                continue;
            }

            $offences[] = sprintf('%s (%s) in "%s"',
                $letter,
                // The character's own name is the only useful thing to show: on screen it looks
                // like the letter it is impersonating.
                @mb_chr(mb_ord($letter, 'UTF-8'), 'UTF-8') === $letter
                    ? ($this->characterName($letter) ?: 'U+'.strtoupper(dechex(mb_ord($letter, 'UTF-8'))))
                    : 'unnamed',
                trim(mb_substr(substr($source, max(0, $offset - 20), 60), 0, 60)),
            );
        }

        return $offences;
    }

    private function characterName(string $letter): string
    {
        if (function_exists('IntlChar::charName')) {
            return (string) \IntlChar::charName($letter);
        }

        return class_exists(\IntlChar::class) ? (string) \IntlChar::charName($letter) : '';
    }
}
