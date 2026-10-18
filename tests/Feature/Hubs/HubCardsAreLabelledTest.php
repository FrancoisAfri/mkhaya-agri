<?php

namespace Tests\Feature\Hubs;

use App\Support\Hubs\ModuleHubs;
use Tests\Support\ShipsLocales;
use Tests\TestCase;

/**
 * Every hub card has a name in every language.
 *
 * `ModuleHubs::card()` derives its labels from the card's key — `esign.cards.my_signatures.title`
 * — and nothing connects that to the JSON the browser actually reads. Add a card and forget the
 * strings and the hub renders the key itself: a tile reading
 * "esign.cards.my_signatures.title" in production, which is what happened.
 *
 * Checked against the locale JSON rather than `__()`, because these labels are resolved in the
 * browser by vue-i18n. `__()` reads lang/, which would pass while the page stayed broken.
 */
class HubCardsAreLabelledTest extends TestCase
{
    use ShipsLocales;

    private const LOCALES = ['en', 'af', 'fr', 'zu', 'xh', 'nso', 'st', 'tn', 'ss', 've', 'ts', 'nr'];

    /** @return array<string, array<string, mixed>> the merged locale bundle for one language */
    private function bundle(string $locale): array
    {
        $merged = [];

        // English lives in the base bundle; the rest carry their module strings in modules/.
        foreach ([
            resource_path("js/utils/locales/{$locale}.json"),
            resource_path("js/utils/locales/modules/{$locale}.json"),
        ] as $path) {
            if (! is_file($path)) {
                continue;
            }

            $merged = array_replace_recursive($merged, json_decode(file_get_contents($path), true) ?? []);
        }

        return $merged;
    }

    private function has(array $bundle, string $key): bool
    {
        foreach (explode('.', $key) as $segment) {
            if (! is_array($bundle) || ! array_key_exists($segment, $bundle)) {
                return false;
            }

            $bundle = $bundle[$segment];
        }

        return is_string($bundle) && trim($bundle) !== '';
    }

    /** @return list<array{module: string, hub: string, key: string, title: string, desc: string}> */
    private function cards(): array
    {
        $cards = [];

        foreach (glob(app_path('Support/Hubs/Modules/*.php')) as $file) {
            $class = 'App\\Support\\Hubs\\Modules\\' . basename($file, '.php');

            if (! class_exists($class) || ! is_subclass_of($class, ModuleHubs::class)) {
                continue;
            }

            foreach ($class::hubs() as $hubKey => $hub) {
                foreach ($hub['cards'] ?? [] as $card) {
                    $cards[] = [
                        'module' => $class::code(),
                        'hub'    => $hubKey,
                        'key'    => $card['key'],
                        'title'  => $card['title'],
                        'desc'   => $card['desc'],
                    ];
                }
            }
        }

        return $cards;
    }

    public function test_every_hub_card_has_a_title_and_subtitle_in_english(): void
    {
        $bundle = $this->bundle('en');
        $missing = [];

        foreach ($this->cards() as $card) {
            foreach (['title', 'desc'] as $part) {
                if (! $this->has($bundle, $card[$part])) {
                    $missing[] = "{$card['module']}/{$card['hub']}: {$card[$part]}";
                }
            }
        }

        $this->assertSame([], $missing, "Hub cards with no English label:\n  " . implode("\n  ", $missing));
    }

    public function test_a_card_labelled_in_english_is_labelled_everywhere(): void
    {
        /*
         * The failure is per-language and invisible to whoever added the card, because they are
         * almost certainly reading the site in English.
         */
        $english = $this->bundle('en');
        $missing = [];

        foreach ($this->shippedLocales(self::LOCALES) as $locale) {
            if ($locale === 'en') {
                continue;
            }

            $bundle = $this->bundle($locale);

            foreach ($this->cards() as $card) {
                foreach (['title', 'desc'] as $part) {
                    if ($this->has($english, $card[$part]) && ! $this->has($bundle, $card[$part])) {
                        $missing[] = "{$locale}: {$card[$part]}";
                    }
                }
            }
        }

        $this->assertSame([], $missing, "Hub cards missing a translation:\n  " . implode("\n  ", $missing));
    }

    public function test_the_card_that_was_reported_is_covered(): void
    {
        // "esign.cards.my_signatures.title" rendered as itself on the Sign hub in production.
        foreach ($this->shippedLocales(self::LOCALES) as $locale) {
            $this->assertTrue(
                $this->has($this->bundle($locale), 'esign.cards.my_signatures.title'),
                "esign.cards.my_signatures.title has no {$locale} label.",
            );
        }
    }
}
