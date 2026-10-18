<?php

namespace Tests\Feature\Tenancy;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Models\BillingAddon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The screen that prices add-ons prices all of them.
 *
 * It listed two types by name — backlinks and websites — because those were the add-ons that
 * existed when it was written. Everything sold since was invisible on the one screen whose job is
 * pricing: extra users, non-login employees, AI packs, storage, and extra trading businesses.
 *
 * Nothing failed. The page rendered, the rows it did show worked, and the missing ones simply
 * were not there to notice — which is why this asserts against the catalogue itself rather than
 * against a list of types. A list of types is what caused it.
 */
class AddOnPricingScreenTest extends TestCase
{
    use DatabaseTransactions;

    public function test_every_sellable_add_on_can_be_priced(): void
    {
        $admin = User::withoutGlobalScopes()->where('is_platform_admin', true)->first()
            ?? User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->saveQuietly();

        $props = $this->actingAs($admin)
            ->withoutMiddleware([\App\Http\Middleware\EnforceCrudPermissions::class])
            ->get(route('tenancy.modules.index'))
            ->assertOk()
            ->viewData('page')['props'];

        $shown = collect($props['addons'] ?? [])->pluck('code')->sort()->values();
        $catalogue = BillingAddon::query()->pluck('code')->sort()->values();

        $this->assertTrue($catalogue->isNotEmpty(), 'There is no add-on catalogue to check.');

        $missing = $catalogue->diff($shown)->values();

        $this->assertTrue(
            $missing->isEmpty(),
            'These add-ons exist but cannot be priced: '.$missing->implode(', ')
        );
    }

    /** The one that prompted this: an extra trading business is sold, so it must be priceable. */
    public function test_the_extra_business_add_on_is_on_the_screen(): void
    {
        $admin = User::withoutGlobalScopes()->where('is_platform_admin', true)->first()
            ?? User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->saveQuietly();

        $props = $this->actingAs($admin)
            ->withoutMiddleware([\App\Http\Middleware\EnforceCrudPermissions::class])
            ->get(route('tenancy.modules.index'))
            ->assertOk()
            ->viewData('page')['props'];

        $entities = collect($props['addons'] ?? [])->where('type', 'entities');

        $this->assertTrue(
            $entities->isNotEmpty(),
            'The extra-business add-on cannot be priced from the pricing screen.'
        );

        foreach ($entities as $addon) {
            $this->assertArrayHasKey('price_monthly', $addon);
            $this->assertArrayHasKey('units', $addon);
        }
    }
}
