<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Support\ProjectHubs;
use App\Modules\Operations\ProjectManagement\Models\EvidenceEvent;
use App\Modules\Operations\ProjectManagement\Support\RoleCatalog;
use Illuminate\Support\Facades\Route;

/**
 * The sidebar is only as good as the three things it depends on, and all three fail quietly.
 *
 * A ribbon whose path has no route is a dead menu entry — the user clicks and gets a 404 on a link
 * we put there ourselves. A card whose label key is missing renders the raw key. And a ribbon that
 * `hubs:sync` never wrote is simply absent from the menu, which looks like the feature was never
 * built. None of these throw, and none of them fail any other test in this suite.
 */
use Tests\Support\ShipsLocales;
class NavigationTest extends ProjectManagementTestCase
{
    use ShipsLocales;

    private const LOCALES = ['en', 'af', 'fr', 'zu', 'xh', 'nso', 'st', 'tn', 'ss', 've', 'ts', 'nr'];

    /** Every direct page and every hub landing points at a real GET route. */
    public function test_every_navigable_path_resolves_to_a_route(): void
    {
        foreach ($this->navigablePaths() as $path) {
            $this->assertTrue(
                $this->routeExists($path),
                "The sidebar offers /{$path}, but nothing serves it — that is a dead menu entry."
            );
        }
    }

    /** Every card inside a hub points at a real GET route too. */
    public function test_every_hub_card_resolves_to_a_route(): void
    {
        $cards = 0;

        foreach (ProjectHubs::hubs() as $hub => $definition) {
            foreach ($definition['cards'] as $card) {
                $cards++;

                $this->assertTrue(
                    $this->routeExists($card['path']),
                    "The {$hub} hub cards /{$card['path']}, but nothing serves it."
                );
            }
        }

        $this->assertGreaterThan(0, $cards, 'No hub cards found — has ProjectHubs::hubs() drifted?');
    }

    /**
     * Hub and card labels resolve in all twelve languages.
     *
     * Cards carry i18n keys rather than words, so a card added without its two strings renders
     * "project_management.cards.foo.title" in the menu — in English as much as in isiZulu.
     *
     * Checked against the JSON rather than `__()`: the menu is drawn by vue-i18n in the browser,
     * so the JSON is the file that decides whether the user sees a word or a key. lang/ carries
     * only the sections the server itself renders, and asserting there would pass while the menu
     * was broken.
     */
    public function test_every_hub_and_card_label_resolves_in_every_locale(): void
    {
        $keys = [];

        foreach (ProjectHubs::hubs() as $definition) {
            $keys[] = $definition['title'];
            $keys[] = $definition['subtitle'];

            foreach ($definition['cards'] as $card) {
                $keys[] = $card['title'];
                $keys[] = $card['desc'];
            }
        }

        $keys = array_unique($keys);

        $this->assertNotEmpty($keys, 'No hub labels found — has ProjectHubs::hubs() drifted?');

        foreach ($this->shippedLocales(self::LOCALES) as $locale) {
            $messages = $this->browserMessages($locale);

            foreach ($keys as $key) {
                $this->assertNotNull(
                    $this->lookup($messages, $key),
                    "'{$key}' is missing from the {$locale} JSON, so the menu would show the key itself."
                );
            }
        }
    }

    /**
     * The sidebar's third level is translated by mapping a ribbon path to the page's own title key,
     * so an entry can never drift from the heading it leads to. A mapping pointing at a key that
     * does not exist silently falls back to the English DB name, which is the bug this catches.
     */
    public function test_the_sidebar_ribbon_labels_map_to_real_translation_keys(): void
    {
        $nav = file_get_contents(resource_path('js/utils/locales/nav.ts'));

        preg_match_all(
            "/^\s*(project_management[a-z_]*):\s*'(project_management\.[a-z_.]+)',/m",
            $nav,
            $matches,
            PREG_SET_ORDER
        );

        $mapped = [];

        foreach ($matches as [, $slug, $key]) {
            $mapped[$slug] = $key;
        }

        $expected = [];

        foreach ($this->navigablePaths() as $path) {
            $expected[] = str_replace(['-', '/'], '_', $path);
        }

        $this->assertSame(
            [],
            array_diff($expected, array_keys($mapped)),
            'Every PM ribbon needs a label key, or its sidebar entry stays English in all 11 other languages.'
        );

        foreach ($this->shippedLocales(self::LOCALES) as $locale) {
            $messages = $this->browserMessages($locale);

            foreach ($mapped as $slug => $key) {
                $this->assertNotNull(
                    $this->lookup($messages, $key),
                    "{$slug} points at '{$key}', which the {$locale} JSON does not define."
                );
            }
        }
    }

    /** The messages vue-i18n loads for a locale — the ones the menu is actually drawn from. */
    private function browserMessages(string $locale): array
    {
        return json_decode(
            file_get_contents(resource_path("js/utils/locales/modules/{$locale}.json")),
            true
        );
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

    /** Nothing is carded that the tier system would then refuse to show. */
    public function test_setup_pages_are_admin_tier_all_the_way_down(): void
    {
        $setup = ProjectHubs::hubs()['setup'];

        $this->assertSame(ProjectHubs::ADMIN, $setup['tier']);

        foreach ($setup['cards'] as $card) {
            $this->assertSame(
                ProjectHubs::ADMIN,
                $card['tier'],
                "The setup card /{$card['path']} is below ADMIN, so a reader would be offered a page they cannot open."
            );
        }

        $this->assertSame(
            ProjectHubs::ADMIN,
            ProjectHubs::guards()['project-management/setup'] ?? null,
            'The /setup/* guard is what stops a future un-carded settings page falling back to READ.'
        );
    }

    /**
     * A card whose page asks a policy on top of its tier declares that policy, so the hub can hide
     * it from someone the page would refuse. Without this, a module admin who may not verify the
     * evidence chain is shown an Evidence audit card that 403s — a broken link of our own making.
     */
    public function test_a_card_whose_page_checks_a_policy_declares_that_policy(): void
    {
        $audit = collect(ProjectHubs::hubs()['evidence']['cards'])->firstWhere('key', 'evidence_audit');

        $this->assertNotNull($audit, 'The evidence audit card has gone missing.');
        $this->assertSame(
            ['ability' => 'verify', 'subject' => EvidenceEvent::class],
            $audit['gate'],
            'EvidenceController@audit authorises "verify", so the card has to name the same check.'
        );

        // And the controller really does check it — otherwise this test guards a gate that moved.
        $controller = file_get_contents(
            app_path('Modules/Operations/ProjectManagement/Http/Controllers/EvidenceController.php')
        );

        $this->assertStringContainsString("authorize('verify', EvidenceEvent::class)", $controller);
    }

    /**
     * And the gate actually changes what the hub renders.
     *
     * The manager role deliberately holds no pm.evidence.verify (see ProjectManagementTestCase) yet
     * sits at the top module tier, so it is exactly the person the tier would card the page for and
     * the policy would then turn away.
     */
    public function test_the_evidence_hub_hides_the_audit_card_from_someone_who_cannot_verify(): void
    {
        $this->assertFalse(
            $this->manager->can(RoleCatalog::VERIFY_EVIDENCE),
            'This test only means something while the manager role lacks pm.evidence.verify.'
        );

        $paths = $this->hubCardPaths($this->manager);

        $this->assertContains('/project-management/evidence/timeline', $paths);
        $this->assertNotContains(
            '/project-management/evidence/audit',
            $paths,
            'The audit card was offered to someone the page itself would refuse with a 403.'
        );
    }

    /** …and still shows it to someone who can. A gate that hides the card from everyone is no use. */
    public function test_the_evidence_hub_still_shows_the_audit_card_to_a_verifier(): void
    {
        $this->manager->givePermissionTo(RoleCatalog::VERIFY_EVIDENCE);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertContains('/project-management/evidence/audit', $this->hubCardPaths($this->manager->fresh()));
    }


    /**
     * The generalisation of the test above, and the one that matters.
     *
     * Module tier and Project Management's own role model are independent: PermissionEvaluator
     * decides from platform-admin flags, overrides, global grants and project memberships, and
     * never reads security_module_access. So "high enough tier to be carded" says nothing about
     * "allowed to open the page", and every card whose controller authorises an ability has to
     * name that ability or the hub offers a 403.
     *
     * Checking one card by hand missed twenty. This reads every card's own route.
     */
    public function test_every_card_that_authorises_a_policy_declares_the_same_gate(): void
    {
        $checked = 0;

        foreach (ProjectHubs::hubs() as $group => $hub) {
            foreach ($hub['cards'] as $card) {
                $authorised = $this->abilityAuthorisedBy($card['path']);

                if ($authorised === null) {
                    continue; // the page asks nothing beyond its tier
                }

                $checked++;

                $this->assertNotEmpty(
                    $card['gate'] ?? null,
                    "The {$group} card /{$card['path']} authorises '{$authorised['ability']}' but declares no gate, "
                    . 'so the hub offers it to people the page then refuses with a 403.'
                );

                $this->assertSame(
                    $authorised,
                    ['ability' => $card['gate']['ability'], 'subject' => $card['gate']['subject']],
                    "The {$group} card /{$card['path']} names a different check from the one its own page makes."
                );
            }
        }

        $this->assertGreaterThan(15, $checked, 'Almost every card authorises something — this read too few to be right.');
    }

    /**
     * Each hub group owns its landing URL.
     *
     * The Subcontractors hub was defined with three cards but its path was never added to the
     * landing loop in routes/modules/project-management.php, so the register answered that URL
     * instead. The ribbon opened the register, the hub never rendered, and the invoice queue and
     * the contracts register — carded nowhere else and linked from no page — were unreachable.
     */
    public function test_every_hub_group_has_a_landing_of_its_own(): void
    {
        foreach (ProjectHubs::hubs() as $group => $hub) {
            $route = collect(Route::getRoutes()->getRoutes())
                ->first(fn ($r) => $r->uri() === $hub['path'] && in_array('GET', $r->methods(), true));

            $this->assertNotNull($route, "The {$group} hub has no landing route at all.");

            $this->assertSame(
                \App\Http\Controllers\ModuleHubController::class . '@show',
                $route->getActionName(),
                "/{$hub['path']} is served by a feature page rather than the {$group} hub, so the hub never "
                . 'renders and any card reachable only from it is reachable from nowhere.'
            );

            $this->assertSame($group, $route->defaults['group'] ?? null);
        }
    }

    /** The Subcontractors hub really does offer all three of its pages. */
    public function test_the_subcontractors_hub_offers_the_register_the_invoices_and_the_contracts(): void
    {
        $paths = $this->hubCardPaths($this->manager, 'contractors');

        $this->assertContains('/project-management/contractors/register', $paths);
        $this->assertContains('/project-management/contractors/invoices', $paths);
        $this->assertContains('/project-management/contracts', $paths);
    }

    /**
     * The gap the gates close, end to end.
     *
     * The manager sits at the top module tier and deliberately holds no pm.settings.manage, so
     * every Setup card passes the tier filter and every Setup page would refuse them.
     */
    public function test_the_setup_hub_hides_every_card_from_an_admin_who_cannot_manage_settings(): void
    {
        $this->assertFalse(
            $this->manager->can(RoleCatalog::MANAGE_SETTINGS),
            'This test only means something while the manager role lacks pm.settings.manage.'
        );

        $this->assertSame(
            ProjectHubs::SUPERUSER,
            \App\Support\Hubs\HubAccess::level($this->manager, ProjectHubs::code()),
            'The manager must be at a tier that cards every Setup page, or the gate is not what is hiding them.'
        );

        $offered = $this->hubCardPaths($this->manager, 'setup');

        foreach (ProjectHubs::hubs()['setup']['cards'] as $card) {
            if (($card['gate']['ability'] ?? null) !== 'manageSettings') {
                continue; // readable by design — see the templates library below
            }

            $this->assertNotContains(
                '/' . $card['path'],
                $offered,
                "The {$card['key']} card was offered to someone the page itself would answer with a 403."
            );
        }

        // Not simply an empty hub: the template library authorises only viewAny and hides its own
        // write controls behind a can.manage flag, so browsing it is allowed and the card stays.
        $this->assertSame(['/project-management/setup/templates'], $offered);
    }

    /** …and the same person sees them once the grant arrives. */
    public function test_the_setup_hub_shows_its_cards_to_an_administrator(): void
    {
        $this->manager->givePermissionTo(RoleCatalog::MANAGE_SETTINGS);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $paths = $this->hubCardPaths($this->manager->fresh(), 'setup');

        $this->assertContains('/project-management/setup/calendars', $paths);
        $this->assertContains('/project-management/setup/integrations', $paths);
    }

    /**
     * The ability a card's own page authorises, read from its controller.
     *
     * @return array{ability: string, subject: class-string}|null
     */
    private function abilityAuthorisedBy(string $path): ?array
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === $path && in_array('GET', $r->methods(), true));

        $this->assertNotNull($route, "The card /{$path} has no GET route.");

        [$class, $method] = explode('@', $route->getActionName());

        $reflection = new \ReflectionMethod($class, $method);
        $source = implode('', array_slice(
            file($reflection->getFileName()),
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));

        if (! preg_match('/\$this->authorize\(\s*\'([a-zA-Z]+)\'\s*,\s*([A-Za-z_\\\\]+)::class/', $source, $matches)) {
            return null;
        }

        $subject = $matches[2];
        $imported = $this->importedClass($reflection->getFileName(), $subject);

        return ['ability' => $matches[1], 'subject' => $imported];
    }

    /** Resolve a short class name used in a controller to its fully qualified name. */
    private function importedClass(string $file, string $short): string
    {
        $short = ltrim($short, '\\');

        if (str_contains($short, '\\')) {
            return $short;
        }

        if (preg_match('/^use\s+([A-Za-z0-9_\\\\]+\\\\' . preg_quote($short, '/') . ')\s*;/m', file_get_contents($file), $m)) {
            return $m[1];
        }

        return $short;
    }

    /** The card paths a hub actually renders for a user. */
    private function hubCardPaths(\App\Core\User\Models\User $user, string $group = 'evidence'): array
    {
        $response = $this->actingAs($user)->get('/project-management/' . $group);

        $response->assertOk();

        return array_column($response->viewData('page')['props']['cards'], 'path');
    }

    /** @return list<string> */
    private function navigablePaths(): array
    {
        $paths = array_column(ProjectHubs::direct(), 'path');

        foreach (ProjectHubs::hubs() as $definition) {
            $paths[] = $definition['path'];
        }

        return $paths;
    }

    private function routeExists(string $path): bool
    {
        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === $path && in_array('GET', $route->methods(), true)) {
                return true;
            }
        }

        return false;
    }
}
