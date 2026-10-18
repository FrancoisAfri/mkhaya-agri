<?php

namespace Tests\Feature\Navigation;

use App\Support\Hubs\HubRegistry;
use Tests\TestCase;

/**
 * Every sidebar entry has an icon that can actually be drawn.
 *
 * The sidebar turns each stored icon name into a Material Design icon through
 * resources/js/utils/faToMdi.ts. A name missing from that table falls back to a tiny dot, which
 * reads as "no icon"; a table entry naming an icon the installed font does not have renders
 * nothing at all. Both fail silently, and both had happened: sixteen names across seven modules
 * had no entry, and two entries pointed at icons the font does not contain.
 *
 * Ribbons are tenant data, so this reads the places that CREATE them -- every hub config, and
 * every seeder and migration that writes a font_awesome value -- rather than one database.
 */
class MenuIconsResolveTest extends TestCase
{
    /** @return array{map: array<string,string>, fallback: array<string,string>, source: string} */
    private function table(): array
    {
        $source = file_get_contents(resource_path('js/utils/faToMdi.ts'));

        preg_match('/const FA_TO_MDI[^{]*\{(.*?)\n\}/s', $source, $m);
        preg_match_all("/^\s*'?([a-z0-9-]+)'?\s*:\s*'(mdi-[a-z0-9-]+)'/m", $m[1], $pairs, PREG_SET_ORDER);

        $map = [];
        foreach ($pairs as [, $key, $target]) {
            $map[$key] = $target;
        }

        return ['map' => $map, 'source' => $source];
    }

    /** @return array<string, true> */
    private function installedIcons(): array
    {
        $css = base_path('node_modules/@mdi/font/css/materialdesignicons.css');

        if (! is_file($css)) {
            $this->markTestSkipped('node_modules/@mdi/font is not installed; run npm ci to check icon names.');
        }

        preg_match_all('/\.(mdi-[a-z0-9-]+)::before/', file_get_contents($css), $m);

        return array_fill_keys($m[1], true);
    }

    /** The same normalisation faToMdi() applies before looking a name up. */
    private function normalise(string $name): string
    {
        return strtolower(trim(preg_replace(['/^fa[srbldk]?-/', '/^fa /', '/^(ti|tabler)-/'], '', $name)));
    }

    public function test_every_icon_the_table_names_exists_in_the_installed_font(): void
    {
        $installed = $this->installedIcons();

        preg_match_all("/'(mdi-[a-z0-9-]+)'/", $this->table()['source'], $m);
        $missing = array_values(array_unique(array_filter($m[1], fn ($icon) => ! isset($installed[$icon]))));

        $this->assertSame([], $missing,
            "faToMdi.ts names icons the installed MDI font does not contain, so they render blank:\n  "
            .implode("\n  ", $missing));
    }

    public function test_every_ribbon_and_module_icon_in_the_code_can_be_drawn(): void
    {
        $installed = $this->installedIcons();
        $map = $this->table()['map'];

        $used = [];

        foreach (HubRegistry::all() as $class) {
            foreach ($class::navRibbons() as $ribbon) {
                $used[$ribbon['icon']][] = 'hub '.$class::code();
            }
        }

        $files = array_merge(
            glob(database_path('seeders/*.php')),
            glob(database_path('migrations/*.php')),
            glob(database_path('migrations/*/*.php')),
        );

        foreach ($files as $file) {
            preg_match_all("/['\"]font_awesome['\"]\s*=>\s*['\"]([^'\"]+)['\"]/", file_get_contents($file), $m);
            foreach ($m[1] as $icon) {
                $used[$icon][] = basename($file);
            }
        }

        $this->assertGreaterThan(50, count($used), 'Read too few icon names to be checking the real menu.');

        $undrawable = [];

        foreach ($used as $raw => $where) {
            $name = $this->normalise($raw);

            if ($name === '') {
                continue;
            }

            $icon = str_starts_with($name, 'mdi-') ? $name : ($map[$name] ?? null);

            if ($icon === null || ! isset($installed[$icon])) {
                $undrawable[] = sprintf('%s  (%s)', $raw, implode(', ', array_slice(array_unique($where), 0, 3)));
            }
        }

        $this->assertSame([], $undrawable,
            "These menu icons would render as a dot or as nothing. Add them to FA_TO_MDI in "
            ."resources/js/utils/faToMdi.ts:\n  ".implode("\n  ", $undrawable));
    }

    public function test_every_hub_card_icon_can_be_drawn(): void
    {
        $installed = $this->installedIcons();
        $map = $this->table()['map'];
        $undrawable = [];
        $checked = 0;

        // The hub page resolves a card's icon as hubCardIcon() does: the table, then the bare name as MDI.
        foreach (HubRegistry::all() as $class) {
            foreach ($class::hubs() as $group => $hub) {
                foreach ($hub['cards'] ?? [] as $card) {
                    $name = $this->normalise($card['icon'] ?? '');
                    $icon = str_starts_with($name, 'mdi-') ? $name : ($map[$name] ?? 'mdi-'.$name);
                    $checked++;

                    if (! isset($installed[$icon])) {
                        $undrawable[] = sprintf('%s  (%s / %s / %s)', $card['icon'] ?? '', $class::code(), $group, $card['key'] ?? '?');
                    }
                }
            }
        }

        $this->assertGreaterThan(50, $checked, 'Read too few hub cards to be checking the real hubs.');
        $this->assertSame([], $undrawable,
            "These hub cards would draw no icon. Add the name to FA_TO_MDI in resources/js/utils/faToMdi.ts "
            ."or declare an MDI name:\n  ".implode("\n  ", $undrawable));
    }
}
