<?php

namespace Tests\Unit\Assets;

use App\Modules\Assets\Models\AssetSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_returns_default_when_key_missing(): void
    {
        $result = AssetSetting::get('nonexistent_key', 'default_value');

        $this->assertEquals('default_value', $result);
    }

    public function test_set_and_get_string_value(): void
    {
        AssetSetting::set('asset_number_prefix', 'EQP', 'string');

        $this->assertEquals('EQP', AssetSetting::get('asset_number_prefix'));
    }

    public function test_set_and_get_integer_value(): void
    {
        AssetSetting::set('some_int_setting', 42, 'integer');

        $result = AssetSetting::get('some_int_setting');

        $this->assertSame(42, $result);
        $this->assertIsInt($result);
    }

    public function test_set_and_get_boolean_value(): void
    {
        AssetSetting::set('feature_flag', true, 'boolean');

        $result = AssetSetting::get('feature_flag');

        $this->assertTrue($result);
        $this->assertIsBool($result);
    }

    public function test_set_and_get_float_value(): void
    {
        AssetSetting::set('threshold', 50000.50, 'float');

        $result = AssetSetting::get('threshold');

        $this->assertEqualsWithDelta(50000.50, $result, 0.01);
    }

    public function test_set_and_get_json_array(): void
    {
        $approvers = [1, 2, 3];
        AssetSetting::set('approvers', $approvers, 'json');

        $result = AssetSetting::get('approvers');

        $this->assertIsArray($result);
        $this->assertEquals($approvers, $result);
    }

    public function test_next_asset_number_increments_sequence(): void
    {
        AssetSetting::firstOrCreate(['key' => 'asset_number_prefix'],  ['value' => 'TST', 'cast' => 'string']);
        AssetSetting::firstOrCreate(['key' => 'asset_number_sequence'], ['value' => '0',   'cast' => 'integer']);

        $first  = AssetSetting::nextAssetNumber();
        $second = AssetSetting::nextAssetNumber();
        $third  = AssetSetting::nextAssetNumber();

        $this->assertStringStartsWith('TST-', $first);
        $this->assertNotEquals($first, $second);
        $this->assertNotEquals($second, $third);
    }

    public function test_update_or_create_overwrites_existing(): void
    {
        AssetSetting::set('my_key', 'initial');
        AssetSetting::set('my_key', 'updated');

        $this->assertEquals('updated', AssetSetting::get('my_key'));
        $this->assertDatabaseCount('asset_settings', 1);
    }
}
