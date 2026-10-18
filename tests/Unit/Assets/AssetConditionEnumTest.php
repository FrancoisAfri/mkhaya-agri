<?php

namespace Tests\Unit\Assets;

use App\Modules\Assets\Enums\AssetCondition;
use PHPUnit\Framework\TestCase;

class AssetConditionEnumTest extends TestCase
{
    public function test_all_cases_have_labels(): void
    {
        foreach (AssetCondition::cases() as $condition) {
            $this->assertNotEmpty($condition->label(), "Condition {$condition->value} has no label");
        }
    }

    public function test_new_label(): void
    {
        $this->assertEquals('New', AssetCondition::New->label());
    }

    public function test_irreparable_label(): void
    {
        $this->assertEquals('Irreparable', AssetCondition::Irreparable->label());
    }

    public function test_values_cover_all_expected_states(): void
    {
        $values = collect(AssetCondition::cases())->pluck('value')->all();

        $this->assertContains('new',        $values);
        $this->assertContains('good',       $values);
        $this->assertContains('fair',       $values);
        $this->assertContains('poor',       $values);
        $this->assertContains('damaged',    $values);
        $this->assertContains('irreparable',$values);
    }
}
