<?php

namespace Tests\Feature\Manufacturing;

use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\ItemCategory;
use App\Modules\Tenancy\Models\Company;

/**
 * A product's category is chosen from a list the company maintains, not typed.
 *
 * Typed categories drift within a week -- "Brackets", "brackets", "BRKT" -- and a filter or a report
 * by category then means nothing.
 */
class ProductCategoryTest extends ManufacturingTestCase
{
    private function category(string $name, ?Company $company = null): ItemCategory
    {
        $company ??= $this->company;

        return $this->asCompany($company, fn () => ItemCategory::create([
            'company_id' => $company->id, 'name' => $name, 'is_active' => true,
        ]));
    }

    public function test_the_add_product_screen_offers_the_category_list(): void
    {
        $this->category('Brackets');
        $this->category('Fasteners');

        $props = $this->actingAs($this->engineer)
            ->get(route('manufacturing.engineering.products.index'))->assertOk()->viewData('page')['props'];

        $this->assertEqualsCanonicalizing(['Brackets', 'Fasteners'], array_column($props['categories'], 'name'));
    }

    public function test_a_product_is_filed_under_a_chosen_category(): void
    {
        $brackets = $this->category('Brackets');
        $stock = $this->material('L-BRACKET');

        $this->actingAs($this->engineer)->post(route('manufacturing.engineering.products.store'), [
            'stock_item_id' => $stock->id, 'kind' => 'FINISHED', 'category_id' => $brackets->id,
        ])->assertSessionHasNoErrors();

        $item = $this->asCompany($this->company, fn () => Item::where('stock_item_id', $stock->id)->firstOrFail());
        $this->assertSame($brackets->id, $item->category_id);

        $page = $this->actingAs($this->planner)
            ->get(route('manufacturing.engineering.products.show', $item->uuid))->viewData('page')['props']['item'];
        $this->assertSame('Brackets', $page['category']);
        $this->assertSame($brackets->id, $page['category_id']);
    }

    public function test_free_text_is_no_longer_accepted_as_a_category(): void
    {
        $stock = $this->material('TYPED');

        $this->actingAs($this->engineer)->post(route('manufacturing.engineering.products.store'), [
            'stock_item_id' => $stock->id, 'kind' => 'FINISHED', 'category_id' => 'Brackets',
        ])->assertSessionHasErrors('category_id');
    }

    public function test_another_tenants_category_cannot_be_chosen(): void
    {
        $rival = Company::create(['name' => 'Rival']);
        $theirs = $this->category('Their category', $rival);

        $this->actingAs($this->engineer)->post(route('manufacturing.engineering.products.store'), [
            'stock_item_id' => $this->material('X1')->id, 'kind' => 'FINISHED', 'category_id' => $theirs->id,
        ])->assertSessionHasErrors('category_id');
    }

    public function test_the_list_filters_by_category(): void
    {
        $brackets = $this->category('Brackets');
        $a = $this->product('BRK-1');
        $this->product('OTHER-1');
        $this->asCompany($this->company, fn () => $a->update(['category_id' => $brackets->id]));

        $codes = array_column($this->actingAs($this->planner)
            ->get(route('manufacturing.engineering.products.index').'?category='.$brackets->uuid)
            ->viewData('page')['props']['items']['data'], 'code');

        $this->assertSame(['BRK-1'], $codes);
    }

    public function test_categories_are_maintained_from_the_screen_and_names_do_not_repeat_by_case(): void
    {
        $this->actingAs($this->engineer)->post(route('manufacturing.engineering.categories.store'), ['name' => 'Brackets'])
            ->assertSessionHasNoErrors();

        // "brackets" is the same category to anybody reading a report.
        $this->actingAs($this->engineer)->post(route('manufacturing.engineering.categories.store'), ['name' => 'brackets'])
            ->assertSessionHasErrors('name');

        $category = $this->asCompany($this->company, fn () => ItemCategory::firstOrFail());

        $this->actingAs($this->engineer)
            ->patch(route('manufacturing.engineering.categories.update', $category->uuid), ['name' => 'Wall brackets'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Wall brackets', $category->fresh()->name);
    }

    public function test_a_category_in_use_is_retired_not_deleted(): void
    {
        $used = $this->category('Used');
        $unused = $this->category('Unused');
        $item = $this->product('FILED');
        $this->asCompany($this->company, fn () => $item->update(['category_id' => $used->id]));

        $this->actingAs($this->engineer)->delete(route('manufacturing.engineering.categories.destroy', $used->uuid));
        $this->actingAs($this->engineer)->delete(route('manufacturing.engineering.categories.destroy', $unused->uuid));

        $this->assertFalse((bool) $used->fresh()->is_active, 'A category in use was not retired.');
        $this->assertSame($used->id, $item->fresh()->category_id, 'Retiring the category took it off the product.');
        $this->assertNull(ItemCategory::withoutGlobalScopes()->find($unused->id), 'An unused category was kept.');
    }

    public function test_only_someone_who_manages_products_may_change_the_list(): void
    {
        $category = $this->category('Guarded');

        foreach ([$this->planner, $this->configurator, $this->outsider] as $user) {
            $this->actingAs($user)->post(route('manufacturing.engineering.categories.store'), ['name' => 'Nope'])
                ->assertForbidden();
            $this->actingAs($user)->delete(route('manufacturing.engineering.categories.destroy', $category->uuid))
                ->assertForbidden();
        }
    }
}
