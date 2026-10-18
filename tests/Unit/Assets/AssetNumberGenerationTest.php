<?php

namespace Tests\Unit\Assets;

use App\Modules\Assets\Models\AssetSetting;
use Database\Factories\Assets\AssetSettingFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetNumberGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        AssetSettingFactory::seedDefaults();
    }

    public function test_next_asset_number_uses_configured_prefix(): void
    {
        AssetSetting::set('asset_number_prefix', 'EQP', 'string');

        $first = AssetSetting::nextAssetNumber();
        $this->assertStringStartsWith('EQP-', $first);
    }

    public function test_next_asset_number_increments_sequentially(): void
    {
        AssetSetting::set('asset_number_prefix', 'AST', 'string');
        AssetSetting::set('asset_number_sequence', 0, 'integer');

        $n1 = AssetSetting::nextAssetNumber();
        $n2 = AssetSetting::nextAssetNumber();
        $n3 = AssetSetting::nextAssetNumber();

        $this->assertSame('AST-00001', $n1);
        $this->assertSame('AST-00002', $n2);
        $this->assertSame('AST-00003', $n3);
    }

    public function test_number_is_zero_padded_to_five_digits(): void
    {
        AssetSetting::set('asset_number_prefix', 'AST', 'string');
        AssetSetting::set('asset_number_sequence', 42, 'integer');

        $this->assertSame('AST-00043', AssetSetting::nextAssetNumber());
    }

    public function test_concurrent_generation_does_not_collide(): void
    {
        AssetSetting::set('asset_number_prefix', 'TST', 'string');
        AssetSetting::set('asset_number_sequence', 0, 'integer');

        $numbers = [];
        for ($i = 0; $i < 25; $i++) {
            $numbers[] = AssetSetting::nextAssetNumber();
        }

        $this->assertCount(25, array_unique($numbers));
    }

    public function test_sequence_persists_across_lookups(): void
    {
        AssetSetting::set('asset_number_prefix', 'XYZ', 'string');
        AssetSetting::set('asset_number_sequence', 0, 'integer');

        AssetSetting::nextAssetNumber();
        AssetSetting::nextAssetNumber();

        $this->assertSame(2, AssetSetting::get('asset_number_sequence'));
    }
}
