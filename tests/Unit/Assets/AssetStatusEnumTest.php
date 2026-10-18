<?php

namespace Tests\Unit\Assets;

use App\Modules\Assets\Enums\AssetStatus;
use PHPUnit\Framework\TestCase;

class AssetStatusEnumTest extends TestCase
{
    public function test_all_cases_have_labels(): void
    {
        foreach (AssetStatus::cases() as $status) {
            $this->assertNotEmpty($status->label(), "Status {$status->value} has no label");
        }
    }

    public function test_active_label(): void
    {
        $this->assertEquals('Active', AssetStatus::Active->label());
    }

    public function test_unallocated_label(): void
    {
        $this->assertEquals('Unallocated', AssetStatus::UnAllocated->label());
    }

    public function test_maintenance_label(): void
    {
        $this->assertEquals('Under Maintenance', AssetStatus::Maintenance->label());
    }

    public function test_select_options_returns_keyed_array(): void
    {
        $options = AssetStatus::selectOptions();

        $this->assertIsArray($options);
        $this->assertArrayHasKey('active', $options);
        $this->assertArrayHasKey('disposed', $options);
        $this->assertEquals('Active', $options['active']);
    }

    public function test_try_from_returns_null_for_unknown_value(): void
    {
        $this->assertNull(AssetStatus::tryFrom('nonexistent'));
    }

    public function test_all_select_option_values_match_case_values(): void
    {
        $options = AssetStatus::selectOptions();

        foreach (AssetStatus::cases() as $status) {
            $this->assertArrayHasKey($status->value, $options);
            $this->assertEquals($status->label(), $options[$status->value]);
        }
    }
}
