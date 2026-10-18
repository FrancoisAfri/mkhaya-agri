<?php

namespace Tests\Feature\Assets;

use App\Modules\Assets\Actions\RegisterAssetAction;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Assets\Models\Assets;

class RegisterAssetActionTest extends AssetTestCase
{
    private RegisterAssetAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = app(RegisterAssetAction::class);
    }

    public function test_creates_asset_with_required_fields(): void
    {
        $result = $this->action->execute([
            'name'      => 'Dell XPS 15',
            'condition' => 'new',
        ], $this->assetManager->id);

        $this->assertInstanceOf(Assets::class, $result);
        $this->assertEquals('Dell XPS 15', $result->name);
        $this->assertEquals('new', $result->condition);
        $this->assertEquals('unallocated', $result->asset_status);
        $this->assertEquals(1, $result->status);
    }

    public function test_auto_assigns_asset_number_with_prefix(): void
    {
        $result = $this->action->execute([
            'name' => 'Test Asset',
        ], $this->assetManager->id);

        $this->assertNotNull($result->asset_number);
        $this->assertStringStartsWith('AST-', $result->asset_number);
    }

    public function test_asset_numbers_are_sequential_and_unique(): void
    {
        $first  = $this->action->execute(['name' => 'Asset One'],   $this->assetManager->id);
        $second = $this->action->execute(['name' => 'Asset Two'],   $this->assetManager->id);
        $third  = $this->action->execute(['name' => 'Asset Three'], $this->assetManager->id);

        $this->assertNotEquals($first->asset_number, $second->asset_number);
        $this->assertNotEquals($second->asset_number, $third->asset_number);
    }

    public function test_sets_retention_until_to_seven_years(): void
    {
        $result = $this->action->execute(['name' => 'SARS Compliant Asset'], $this->assetManager->id);

        $this->assertNotNull($result->retention_until);
        $this->assertGreaterThanOrEqual(
            now()->addYears(6)->toDateString(),
            $result->retention_until->toDateString(),
        );
    }

    public function test_sets_next_impairment_review_to_one_year(): void
    {
        $result = $this->action->execute(['name' => 'IAS36 Asset'], $this->assetManager->id);

        $this->assertNotNull($result->next_impairment_review_at);
        $this->assertGreaterThanOrEqual(
            now()->addMonths(11)->toDateString(),
            $result->next_impairment_review_at->toDateString(),
        );
    }

    public function test_inherits_sars_section_from_category_when_not_provided(): void
    {
        $category = AssetCategory::factory()->create([
            'sars_allowance_section' => 's11e',
        ]);

        $result = $this->action->execute([
            'name'              => 'Category Asset',
            'asset_category_id' => $category->id,
        ], $this->assetManager->id);

        $this->assertDatabaseHas('assets', [
            'id'               => $result->id,
            'asset_category_id'=> $category->id,
        ]);
    }

    public function test_explicit_sars_section_overrides_category_default(): void
    {
        $category = AssetCategory::factory()->create(['sars_allowance_section' => 's11e']);

        $result = $this->action->execute([
            'name'                   => 'Override Asset',
            'asset_category_id'      => $category->id,
            'sars_allowance_section' => 's12c',
        ], $this->assetManager->id);

        $this->assertEquals('s12c', $result->sars_allowance_section->value);
    }

    public function test_writes_custody_log_on_registration(): void
    {
        $result = $this->action->execute(['name' => 'Auditable Asset'], $this->assetManager->id);

        $this->assertDatabaseHas('asset_custody_logs', [
            'asset_id'     => $result->id,
            'action'       => 'registered',
            'performed_by' => $this->assetManager->id,
        ]);
    }

    public function test_persists_financial_fields(): void
    {
        $result = $this->action->execute([
            'name'          => 'Financial Asset',
            'purchase_cost' => 75000.00,
            'purchase_date' => '2026-01-15',
        ], $this->assetManager->id);

        $this->assertDatabaseHas('assets', [
            'id'            => $result->id,
            'purchase_cost' => 75000.00,
            'purchase_date' => '2026-01-15',
        ]);
    }
}
