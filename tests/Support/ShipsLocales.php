<?php

namespace Tests\Support;

/**
 * Which of the languages a test cares about are actually shipped by this install.
 *
 * Translation tests name the languages they expect, which is right — it is how a missing language
 * gets noticed. But the set that ships is now decided by the locale files on disk, so a test that
 * reads a file which is not there fails for the wrong reason: it reports a missing translation when
 * the truth is that the language was deliberately removed.
 *
 * Intersecting the two keeps both properties. While eleven languages ship, every one is checked
 * exactly as before. While only English ships, the comparisons skip and say so, and the day the
 * files are restored they start asserting again without anybody remembering to re-enable them.
 */
trait ShipsLocales
{
    /**
     * @param  list<string>  $wanted
     * @return list<string>
     */
    protected function shippedLocales(array $wanted): array
    {
        $present = array_map(
            fn (string $path) => basename($path, '.json'),
            glob(resource_path('js/utils/locales/modules/*.json')) ?: [],
        );

        return array_values(array_intersect($wanted, $present));
    }

    /**
     * Skip when there is no translation to compare English against.
     *
     * Said out loud rather than looping over an empty list: a test that asserts nothing reports a
     * pass, and a pass here would mean the translations are fine when there are none.
     */
    protected function skipWithoutTranslations(): void
    {
        if ($this->shippedLocales(['af', 'fr', 'zu', 'xh', 'nso', 'st', 'tn', 'ss', 've', 'ts', 'nr']) === []) {
            $this->markTestSkipped('This install ships English only — there is no translation to compare.');
        }
    }
}
