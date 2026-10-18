<?php

namespace Tests\Unit;

use App\Support\Hubs\HubRegistry;
use App\Support\Hubs\Modules\DisciplinaryHubs;
use PHPUnit\Framework\TestCase;

/**
 * The sidebar highlights/keeps open the branch returned by ribbonForPath(). Hub cards are not
 * ribbons of their own, so if this mapping breaks the menu silently collapses whenever a user
 * opens a card.
 */
class HubRibbonResolutionTest extends TestCase
{
    public function test_a_hub_card_resolves_to_its_hub_ribbon(): void
    {
        $this->assertSame(
            'disciplinary/cases-hub',
            DisciplinaryHubs::ribbonForPath('disciplinary/corrective-actions'),
        );
    }

    public function test_a_sub_page_of_a_card_resolves_to_the_same_hub_ribbon(): void
    {
        $this->assertSame(
            'disciplinary/cases-hub',
            DisciplinaryHubs::ribbonForPath('disciplinary/cases/5/edit'),
        );
    }

    public function test_a_hub_and_a_direct_page_resolve_to_themselves(): void
    {
        $this->assertSame('disciplinary/cases-hub', DisciplinaryHubs::ribbonForPath('disciplinary/cases-hub'));
        $this->assertSame('disciplinary/my-dashboard', DisciplinaryHubs::ribbonForPath('disciplinary/my-dashboard'));
    }

    public function test_a_path_from_another_module_does_not_match(): void
    {
        $this->assertNull(DisciplinaryHubs::ribbonForPath('settings/roles'));
        $this->assertNull(DisciplinaryHubs::ribbonForPath('platform/companies'));
    }

    public function test_every_registered_module_maps_its_own_cards_to_a_real_ribbon(): void
    {
        foreach (HubRegistry::all() as $code => $hubs) {
            $ribbons = array_column($hubs::navRibbons(), 'path');

            foreach ($hubs::ribbonPathMap() as $path => $ribbon) {
                $this->assertContains(
                    $ribbon,
                    $ribbons,
                    "[$code] {$path} resolves to {$ribbon}, which is not a nav ribbon.",
                );
            }
        }
    }

    public function test_hub_landing_paths_are_unique_across_every_module(): void
    {
        $seen = [];
        foreach (HubRegistry::all() as $code => $hubs) {
            foreach ($hubs::hubs() as $group => $hub) {
                $path = trim($hub['path'], '/');
                $this->assertArrayNotHasKey(
                    $path,
                    $seen,
                    "{$code}.{$group} reuses hub path {$path}, already claimed by " . ($seen[$path] ?? '?') . '.',
                );
                $seen[$path] = "{$code}.{$group}";
            }
        }
    }

    public function test_a_hub_never_demands_a_higher_tier_than_the_cards_it_shows(): void
    {
        foreach (HubRegistry::all() as $code => $hubs) {
            foreach ($hubs::hubs() as $group => $hub) {
                $hubPath = trim($hub['path'], '/');

                foreach ($hub['cards'] as $card) {
                    /*
                     * A card that lives outside its hub's path is reached directly.
                     *
                     * The rule below is about cards you can only get to by opening the hub first —
                     * for those, a tier under the hub's own makes the card unreachable. A card
                     * whose path is elsewhere is a different thing: PM's subcontract card sits at
                     * `project-management/contracts`, under the Subcontractors hub for navigation
                     * but addressed on its own, deliberately at READ so the other party to the
                     * contract — a portal user with no write tier — can open it and sign.
                     */
                    $cardPath = trim($card['path'] ?? '', '/');
                    $reachedThroughHub = $cardPath === $hubPath
                        || str_starts_with($cardPath, $hubPath . '/');

                    if (! $reachedThroughHub) {
                        continue;
                    }

                    $this->assertGreaterThanOrEqual(
                        $hub['tier'],
                        $card['tier'],
                        "[{$code}.{$group}] card {$card['key']} is below its hub's tier, so nobody who "
                        . 'can see the card can reach the hub.',
                    );
                }
            }
        }
    }

    /**
     * Every module has somewhere for a retired ribbon's how-tos to land.
     *
     * This used to demand a direct entry specifically, because `hubs:sync` read `direct()[0]` and
     * nothing else. That was a fact about the command rather than about navigation, and it stopped
     * a module from putting all of its entries inside hubs — which is exactly what Company Settings
     * wanted, having offered the org structure twice: once directly and once as a card in the hub
     * covering the same subject.
     *
     * The command now falls back to the first hub, so what has to hold is the thing that actually
     * matters: a module has at least one anchor of some kind. A module with neither leaves how-tos
     * pointing at deleted ribbons, and the assistant stops being able to answer questions it knows
     * the answer to.
     */
    public function test_every_module_has_somewhere_to_anchor_retired_ribbons(): void
    {
        foreach (HubRegistry::all() as $code => $hubs) {
            $this->assertNotEmpty(
                array_merge($hubs::direct(), array_values($hubs::hubs())),
                "[{$code}] has neither a direct entry nor a hub, so hubs:sync has nothing to "
                . 're-point retired ribbons at.',
            );
        }
    }

    /**
     * Company Settings offers one route to the organisation structure, not two.
     *
     * It sat in the sidebar as a direct entry AND as a card inside the Structure hub — the same
     * page, one level apart, with nothing to say why both were there. Positions is the other half
     * of the same subject and only ever lived in the hub.
     */
    public function test_company_settings_does_not_offer_the_org_structure_twice(): void
    {
        $hubs = \App\Support\Hubs\Modules\CompanySettingsHubs::class;

        $direct = array_column($hubs::direct(), 'path');
        $cards = [];
        foreach ($hubs::hubs() as $hub) {
            $cards = array_merge($cards, array_column($hub['cards'], 'path'));
        }

        $this->assertContains('settings/organization', $cards,
            'The organisation structure is no longer reachable at all.');
        $this->assertNotContains('settings/organization', $direct,
            'The organisation structure is offered both directly and inside the Structure hub.');
    }
}
