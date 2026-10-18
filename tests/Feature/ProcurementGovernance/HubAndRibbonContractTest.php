<?php

namespace Tests\Feature\ProcurementGovernance;

use App\Core\User\Models\User;
use App\Modules\Procurement\Governance\Support\ProcurementGovernanceHubs as Hubs;
use App\Modules\Tenancy\Services\CurrentCompany;
use App\Support\Hubs\HubAccess;
use App\Support\Hubs\HubCardCatalog;
use App\Support\Hubs\HubRegistry;
use App\Support\Hubs\ModuleHubs;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\Support\ShipsLocales;
use Tests\TestCase;

/**
 * The navigation itself: seven ribbons, seventeen cards, and every one of them a promise.
 *
 * A card is a tile with a title, an icon and a link, and all three lie independently. The link can
 * point at a route that was renamed, in which case the tile 404s. The title is a translation key,
 * and a key with no string renders its own dotted path — "procurement_governance.cards.analytics
 * .title" — on a page that is otherwise in isiZulu. The tier can be lower than the hub that
 * contains it, which produces a card nobody who can reach the hub is allowed to open, and one
 * nobody who is allowed to open it can reach.
 *
 * None of that fails a build. It fails when somebody clicks, and the whole module looks broken
 * because one tile is. The ribbons have the same problem one level up, with an extra twist: they
 * live in the database (`security_module_ribbons`), written by a migration, while the code that
 * decides what they should be lives in ProcurementGovernanceHubs. Add a hub and forget the sync and
 * the page exists, the routes work, the tests pass, and there is no way to navigate to it.
 */
class HubAndRibbonContractTest extends TestCase
{
    use ShipsLocales;

    use DatabaseTransactions;

    private const CODE = 'procurement_governance';

    /** Every locale the platform ships, including the source. */
    private const LOCALES = ['en', 'zu', 'af', 'fr', 'xh', 'ss', 'nr', 'st', 'tn', 'nso', 'ts', 've'];

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        Cache::flush();
        app(CurrentCompany::class)->set($this->companyId);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    /** @return list<array{label:string,path:string,tier:int}> every navigable destination */
    private function destinations(): array
    {
        $out = [];

        foreach (Hubs::direct() as $direct) {
            $out[] = ['label' => "direct:{$direct['name']}", 'path' => $direct['path'], 'tier' => $direct['tier']];
        }

        foreach (Hubs::hubs() as $key => $hub) {
            $out[] = ['label' => "hub:{$key}", 'path' => $hub['path'], 'tier' => $hub['tier']];

            foreach ($hub['cards'] as $card) {
                $out[] = ['label' => "card:{$key}/{$card['key']}", 'path' => $card['path'], 'tier' => $card['tier']];
            }
        }

        return $out;
    }

    /** @return array<string, true> registered GET route URIs, without leading slash */
    private function getRoutes(): array
    {
        $uris = [];

        foreach (Route::getRoutes() as $route) {
            if (in_array('GET', $route->methods(), true)) {
                $uris[trim($route->uri(), '/')] = true;
            }
        }

        return $uris;
    }

    private static function stripQuery(string $path): string
    {
        return trim(explode('?', $path, 2)[0], '/');
    }

    public function test_every_ribbon_and_card_points_at_a_route_that_exists(): void
    {
        // The failure this catches is a renamed route with a card left behind: a tile that looks
        // exactly like the working ones and 404s.
        $routes = $this->getRoutes();
        $broken = [];

        foreach ($this->destinations() as $destination) {
            $path = self::stripQuery($destination['path']);

            if (! isset($routes[$path])) {
                $broken[] = "{$destination['label']} → /{$path}";
            }
        }

        $this->assertSame([], $broken, "These navigation entries link to nothing:\n".implode("\n", $broken));
    }

    public function test_the_module_is_registered_in_the_hub_registry(): void
    {
        // Without this the nav renders no ribbons at all and EnsureModuleTier waves every request
        // through, since an unregistered module is treated as "not hub-managed".
        $this->assertSame(Hubs::class, HubRegistry::for(self::CODE));
    }

    public function test_the_ribbons_in_the_database_are_the_ribbons_the_code_declares(): void
    {
        /*
         * The one check that spans code and data. Ribbons are written by a migration and can be
         * repaired with `php artisan hubs:sync`, but nothing forces the two to agree — so a hub
         * added in code without a migration is a page with no way to reach it, and a ribbon left
         * in the database after a hub is removed is a menu item that 404s.
         */
        $moduleId = DB::table('security_modules')->where('code_name', self::CODE)->value('id');

        $this->assertNotNull($moduleId, 'The module is not registered in security_modules, so it has no navigation at all.');

        $stored = DB::table('security_module_ribbons')
            ->where('module_id', $moduleId)
            ->where('active', 1)
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($r) => [
                'name' => $r->ribbon_name,
                'path' => trim((string) $r->ribbon_path, '/'),
                'icon' => $r->font_awesome,
                'access_level' => (int) $r->access_level,
            ])
            ->all();

        $declared = array_map(fn ($r) => [
            'name' => $r['name'],
            'path' => trim($r['path'], '/'),
            'icon' => $r['icon'],
            'access_level' => $r['access_level'],
        ], Hubs::navRibbons());

        $this->assertSame(
            $declared,
            $stored,
            'The stored ribbons have drifted from ProcurementGovernanceHubs. Run: php artisan hubs:sync '.self::CODE,
        );
    }

    public function test_no_card_is_gated_higher_than_the_hub_that_contains_it(): void
    {
        /*
         * A card above its hub's tier is visible to people who cannot open it — which at least
         * fails honestly, with a 403. A card *below* its hub's tier is worse and is what this
         * asserts against in the other direction: the card is only reachable by someone who has
         * already cleared the higher bar to see the hub, so the lower tier is a lie that will be
         * read as permission granted.
         */
        $mismatched = [];

        foreach (Hubs::hubs() as $key => $hub) {
            foreach ($hub['cards'] as $card) {
                if ($card['tier'] < $hub['tier']) {
                    $mismatched[] = sprintf(
                        '%s/%s is tier %d inside a tier %d hub — nobody below %d can ever reach it',
                        $key, $card['key'], $card['tier'], $hub['tier'], $hub['tier'],
                    );
                }
            }
        }

        $this->assertSame([], $mismatched, implode("\n", $mismatched));
    }

    public function test_the_derived_tier_for_a_path_never_falls_below_its_cards_tier(): void
    {
        /*
         * pathTier() is what EnsureModuleTier actually enforces, and it is derived by longest-prefix
         * match rather than read off the card. A card whose path is a *sub*-path of a cheaper card
         * silently inherits the cheaper tier — which is how an ADMIN screen ends up gated at READ
         * without anyone editing its tier.
         */
        $weakened = [];

        foreach ($this->destinations() as $destination) {
            $path = self::stripQuery($destination['path']);
            $effective = Hubs::pathTier($path);

            if ($effective < $destination['tier']) {
                $weakened[] = "{$destination['label']} declares tier {$destination['tier']} but resolves to {$effective}";
            }
        }

        $this->assertSame([], $weakened, "Declared and enforced tiers disagree:\n".implode("\n", $weakened));
    }

    public function test_a_settings_sub_path_is_still_gated_at_admin(): void
    {
        // The guards() subtree floor, tested at the place it matters most: a URL under settings that
        // is not itself a card must not fall back to READ and hand the rules engine to a front-line
        // user who typed it in.
        $this->assertSame(ModuleHubs::ADMIN, Hubs::pathTier('procurement-governance/settings/rules/17'));
        $this->assertSame(ModuleHubs::MODIFY, Hubs::pathTier('procurement-governance/reports/runs/abc'));
        $this->assertSame(ModuleHubs::MODIFY, Hubs::pathTier('procurement-governance/analytics'));
    }

    public function test_every_card_maps_back_to_the_hub_that_owns_it(): void
    {
        // A card is not a ribbon, so the sidebar has nothing to highlight while a user works inside
        // one. If the mapping breaks, the nav branch collapses mid-task and the user loses their
        // place in the module — the classic symptom being "the menu closes when I click anything".
        $ribbons = array_column(Hubs::navRibbons(), 'path');
        $orphans = [];

        foreach (Hubs::hubs() as $key => $hub) {
            foreach ($hub['cards'] as $card) {
                $owner = Hubs::ribbonForPath(self::stripQuery($card['path']));

                // The owner need not be *this* hub — two hubs may legitimately list the same card,
                // and the first one declared wins. What must never happen is no ribbon claiming it.
                if ($owner === null || ! in_array($owner, $ribbons, true)) {
                    $orphans[] = "{$key}/{$card['key']} resolves to ".var_export($owner, true).', which is not a ribbon';
                }
            }
        }

        $this->assertSame([], $orphans, implode("\n", $orphans));
    }

    public function test_the_hub_catalog_can_list_the_cards_behind_every_ribbon(): void
    {
        // What the how-to/AI tooling reads to answer "what can I do here". A hub returning an empty
        // card list reads to that tooling as a module with no features.
        foreach (Hubs::hubs() as $key => $hub) {
            $cards = HubCardCatalog::cards(self::CODE, $hub['path']);

            $this->assertNotEmpty($cards, "The {$key} hub reports no cards.");
            $this->assertCount(count($hub['cards']), $cards);
        }
    }

    public function test_every_ribbon_and_card_string_resolves_in_all_twelve_languages(): void
    {
        /*
         * The nav is the first thing a user sees and the last thing anyone thinks to translate.
         * Checked against the JSON bundles the client actually loads, not the PHP lang files, since
         * these strings are rendered by Vue.
         */
        $keys = [];

        foreach (Hubs::hubs() as $hub) {
            $keys[] = $hub['title'];
            $keys[] = $hub['subtitle'];

            foreach ($hub['cards'] as $card) {
                $keys[] = $card['title'];
                $keys[] = $card['desc'];
            }
        }

        $missing = [];

        foreach ($this->shippedLocales(self::LOCALES) as $locale) {
            $bundle = json_decode(
                (string) file_get_contents(resource_path("js/utils/locales/modules/{$locale}.json")),
                true,
            );

            foreach ($keys as $key) {
                $value = data_get($bundle, $key);

                if (! is_string($value) || trim($value) === '') {
                    $missing[] = "{$locale}: {$key}";
                }
            }
        }

        $this->assertSame([], $missing, "These would render as their own key path in the menu:\n".implode("\n", $missing));
    }

    public function test_every_ribbon_and_card_carries_an_icon(): void
    {
        // An icon-less tile renders as an empty square. Names are checked for shape rather than
        // against the icon set, because a typo produces the same blank square as an empty string.
        $bad = [];

        foreach ($this->destinations() as $destination) {
            $path = $destination['path'];
            $icon = null;

            foreach (array_merge(Hubs::direct(), array_values(Hubs::hubs())) as $entry) {
                if (($entry['path'] ?? null) === $path) {
                    $icon = $entry['icon'];
                }

                foreach ($entry['cards'] ?? [] as $card) {
                    if ($card['path'] === $path) {
                        $icon = $card['icon'];
                    }
                }
            }

            if (! is_string($icon) || ! preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $icon)) {
                $bad[] = "{$destination['label']}: ".var_export($icon, true);
            }
        }

        $this->assertSame([], $bad, "These navigation entries have no usable icon:\n".implode("\n", $bad));
    }

    public function test_a_module_user_can_open_every_hub_and_every_parameterless_card(): void
    {
        /*
         * The walk. Every check above reasons about configuration; this one asks the application.
         *
         * The actor is an ordinary tenant user holding the module at SUPERUSER and every permission
         * the module's routes name — deliberately not a platform admin, who bypasses both gates and
         * would prove only that the controllers do not crash. Anything other than a 200 here is a
         * tile a real user clicks and does not arrive at.
         */
        $user = $this->makeModuleUser();
        $this->actingAs($user);

        $this->assertSame(ModuleHubs::SUPERUSER, HubAccess::level($user, self::CODE));

        // A loop over an empty list passes and proves nothing, which is the failure mode of every
        // test that walks a generated collection. Seven ribbons and seventeen cards.
        $this->assertCount(24, $this->destinations());

        $failures = [];

        foreach ($this->destinations() as $destination) {
            $path = self::stripQuery($destination['path']);

            // Skip nothing: none of this module's cards take a route parameter, and if one ever
            // does, this loop should fail loudly rather than quietly stop covering it.
            $response = $this->get('/'.$path);

            if ($response->getStatusCode() !== 200) {
                $failures[] = sprintf('%s → /%s returned %d', $destination['label'], $path, $response->getStatusCode());
            }
        }

        $this->assertSame([], $failures, "These navigation destinations do not open:\n".implode("\n", $failures));
    }

    public function test_a_read_only_user_is_turned_away_from_the_admin_hub(): void
    {
        // The other half of the walk: the tiers are only worth declaring if they are enforced, and
        // Configure is the hub that hands over the compliance rules engine.
        $user = $this->makeModuleUser(ModuleHubs::READ);
        $this->actingAs($user);

        $this->get('/procurement-governance/configure-hub')->assertStatus(302);
        $this->get('/procurement-governance/settings')->assertStatus(302);

        // ...while the READ hubs stay open, so the gate is a gate and not a wall.
        $this->get('/procurement-governance/planning-hub')->assertStatus(200);
    }

    /** A tenant user holding the module at $tier with every permission its routes name. */
    private function makeModuleUser(int $tier = ModuleHubs::SUPERUSER): User
    {
        $userId = (int) DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'email' => 'hub-walk-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'),
            'company_id' => $this->companyId,
            'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $moduleId = DB::table('security_modules')->where('code_name', self::CODE)->value('id');

        DB::table('security_module_access')->insert([
            'user_id' => $userId,
            'module_id' => $moduleId,
            'access_level' => $tier,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $user = User::find($userId);

        // Granted directly rather than through a role: this test is about navigation, and routing
        // permission assignment through the role system would make a role-seeding change able to
        // fail it for reasons that have nothing to do with hubs.
        $user->syncPermissions(Permission::where('name', 'like', 'procgov.%')->pluck('name')->all());

        Cache::flush();

        return $user->fresh();
    }
}
