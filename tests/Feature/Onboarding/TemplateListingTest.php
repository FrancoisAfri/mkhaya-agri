<?php

namespace Tests\Feature\Onboarding;

use App\Core\User\Models\User;
use Database\Seeders\OnboardingTemplateCatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActivatesBoardingModule;
use Tests\TestCase;

/**
 * The templates page must actually list the templates it counts.
 *
 * The KPI cards read 39 while the table below them was empty, which is the worst version of this
 * bug: the page looks like it loaded and simply has nothing in it, so the catalogue appears to have
 * failed to seed rather than failed to render.
 */
class TemplateListingTest extends TestCase
{
    use RefreshDatabase, ActivatesBoardingModule;

    public function test_the_index_returns_templates_in_the_shape_the_page_reads(): void
    {
        $this->activateBoardingModule();
        $this->seed(OnboardingTemplateCatalogueSeeder::class);

        $user = User::factory()->create();
        $this->grantBoardingTier($user);

        $props = $this->actingAs($user->fresh())
            ->get(route('onboarding.templates.index'))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertGreaterThan(0, $props['stats']['total'], 'The catalogue should be counted.');

        // The page reads templates.data — a bare list, or a differently wrapped one, renders empty.
        $this->assertArrayHasKey('data', $props['templates'], 'The page reads templates.data.');
        $this->assertNotEmpty($props['templates']['data'], 'Counted but not listed is the reported bug.');
        $this->assertArrayHasKey('meta', $props['templates'], 'Pagination drives the footer.');
    }
}
