<?php

namespace Tests\Feature\Assets;

use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Assets\Models\AssetLocation;
use App\Modules\Assets\Models\Assets;

class AssetControllerTest extends AssetTestCase
{
    // ── index ────────────────────────────────────────────────────────────────

    public function test_index_renders_inertia_page_with_assets(): void
    {
        Assets::factory()->count(3)->create();

        $this->actingAs($this->assetManager)
            ->get(route('assets.index'))
            ->assertOk()
            ->assertInertia(fn($page) =>
                $page->component('Modules/Assets/Index')
                     ->has('assets.data', 3)
                     ->has('stats')
                     ->has('statusOptions')
                     ->has('categories')
            );
    }

    public function test_index_requires_authentication(): void
    {
        $this->get(route('assets.index'))
            ->assertRedirect(route('login'));
    }

    public function test_index_filters_by_status(): void
    {
        Assets::factory()->active()->count(2)->create();
        Assets::factory()->underMaintenance()->create();

        $this->actingAs($this->assetManager)
            ->get(route('assets.index', ['status' => 'active']))
            ->assertInertia(fn($page) =>
                $page->component('Modules/Assets/Index')
                     ->has('assets.data', 2)
            );
    }

    public function test_index_filters_by_search(): void
    {
        Assets::factory()->create(['name' => 'Dell Laptop XPS']);
        Assets::factory()->create(['name' => 'HP Monitor']);

        $this->actingAs($this->assetManager)
            ->get(route('assets.index', ['search' => 'Dell']))
            ->assertInertia(fn($page) =>
                $page->component('Modules/Assets/Index')
                     ->has('assets.data', 1)
            );
    }

    public function test_index_filters_by_category(): void
    {
        $cat = AssetCategory::factory()->create();
        Assets::factory()->create(['asset_category_id' => $cat->id]);
        Assets::factory()->create(['asset_category_id' => null]);

        $this->actingAs($this->assetManager)
            ->get(route('assets.index', ['category' => $cat->id]))
            ->assertInertia(fn($page) =>
                $page->component('Modules/Assets/Index')
                     ->has('assets.data', 1)
            );
    }

    // ── create ───────────────────────────────────────────────────────────────

    public function test_create_renders_form_with_lookup_data(): void
    {
        AssetCategory::factory()->create();
        AssetLocation::factory()->create();

        $this->actingAs($this->assetManager)
            ->get(route('assets.create'))
            ->assertOk()
            ->assertInertia(fn($page) =>
                $page->component('Modules/Assets/Create')
                     ->has('categories')
                     ->has('locations')
                     ->has('assetTypes')
            );
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_asset_and_redirects_to_show(): void
    {
        $payload = [
            'name'          => 'Dell Latitude 5540',
            'serial_number' => 'SN-12345678',
            'condition'     => 'new',
            'purchase_cost' => 25000,
            'purchase_date' => '2026-01-15',
        ];

        $response = $this->actingAs($this->assetManager)
            ->post(route('assets.store'), $payload);

        $response->assertRedirect();

        $this->assertDatabaseHas('assets', [
            'name'          => 'Dell Latitude 5540',
            'serial_number' => 'SN-12345678',
            'asset_status'  => 'unallocated',
        ]);
    }

    public function test_store_requires_name(): void
    {
        $this->actingAs($this->assetManager)
            ->post(route('assets.store'), [])
            ->assertSessionHasErrors(['name']);
    }

    public function test_store_validates_condition_values(): void
    {
        $this->actingAs($this->assetManager)
            ->post(route('assets.store'), [
                'name'      => 'Test Asset',
                'condition' => 'broken', // invalid
            ])
            ->assertSessionHasErrors(['condition']);
    }

    public function test_store_validates_warranty_after_purchase_date(): void
    {
        $this->actingAs($this->assetManager)
            ->post(route('assets.store'), [
                'name'            => 'Test Asset',
                'purchase_date'   => '2026-01-01',
                'warranty_expiry' => '2025-12-01', // before purchase
            ])
            ->assertSessionHasErrors(['warranty_expiry']);
    }

    public function test_store_requires_authentication(): void
    {
        $this->post(route('assets.store'), ['name' => 'Test'])
            ->assertRedirect(route('login'));
    }

    // ── show ──────────────────────────────────────────────────────────────────

    public function test_show_renders_inertia_page_with_asset_data(): void
    {
        $asset = Assets::factory()->create();

        $this->actingAs($this->assetManager)
            ->get(route('assets.show', $asset->uuid))
            ->assertOk()
            ->assertInertia(fn($page) =>
                $page->component('Modules/Assets/Show')
                     ->has('asset')
                     ->has('assignments')
                     ->has('maintenance')
                     ->has('incidents')
                     ->has('custody_log')
                     ->has('locations')
            );
    }

    public function test_show_returns_404_for_unknown_uuid(): void
    {
        $this->actingAs($this->assetManager)
            ->get(route('assets.show', 'non-existent-uuid'))
            ->assertNotFound();
    }

    // ── update ───────────────────────────────────────────────────────────────

    public function test_update_patches_asset_fields(): void
    {
        $asset = Assets::factory()->create(['condition' => 'new']);

        $this->actingAs($this->assetManager)
            ->patch(route('assets.update', $asset->uuid), [
                'condition' => 'good',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('assets', [
            'id'        => $asset->id,
            'condition' => 'good',
        ]);
    }

    public function test_update_rejects_invalid_condition(): void
    {
        $asset = Assets::factory()->create();

        $this->actingAs($this->assetManager)
            ->patch(route('assets.update', $asset->uuid), ['condition' => 'exploded'])
            ->assertSessionHasErrors(['condition']);
    }
}
