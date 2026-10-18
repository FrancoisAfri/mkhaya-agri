<?php

namespace Tests\Unit\Assets;

use App\Modules\Assets\Models\AssetCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetCategoryHierarchyTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_child_relationship(): void
    {
        $parent = AssetCategory::factory()->create(['name' => 'Computers']);
        $child  = AssetCategory::factory()->create([
            'name'      => 'Laptops',
            'parent_id' => $parent->id,
        ]);

        $this->assertSame($parent->id, $child->parent->id);
        $this->assertTrue($parent->children->contains('id', $child->id));
    }

    public function test_root_category_has_null_parent(): void
    {
        $root = AssetCategory::factory()->create(['parent_id' => null]);

        $this->assertNull($root->parent_id);
        $this->assertNull($root->parent);
    }

    public function test_multiple_children_under_same_parent(): void
    {
        $parent = AssetCategory::factory()->create();
        $laptops = AssetCategory::factory()->create(['parent_id' => $parent->id]);
        $desktops = AssetCategory::factory()->create(['parent_id' => $parent->id]);
        $tablets = AssetCategory::factory()->create(['parent_id' => $parent->id]);

        $children = $parent->children()->pluck('id')->all();
        $this->assertCount(3, $children);
        $this->assertContains($laptops->id, $children);
        $this->assertContains($desktops->id, $children);
        $this->assertContains($tablets->id, $children);
    }

    public function test_deep_hierarchy_three_levels(): void
    {
        $level1 = AssetCategory::factory()->create(['name' => 'IT']);
        $level2 = AssetCategory::factory()->create(['name' => 'Computers', 'parent_id' => $level1->id]);
        $level3 = AssetCategory::factory()->create(['name' => 'Laptops', 'parent_id' => $level2->id]);

        $this->assertSame($level2->id, $level3->parent->id);
        $this->assertSame($level1->id, $level3->parent->parent->id);
        $this->assertNull($level3->parent->parent->parent);
    }

    public function test_category_can_have_no_children(): void
    {
        $leaf = AssetCategory::factory()->create();
        $this->assertCount(0, $leaf->children);
    }
}
