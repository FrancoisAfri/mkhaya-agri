<?php

namespace Tests\Feature\Manufacturing;

use App\Modules\Operations\Manufacturing\Models\Bom;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;

/**
 * The engineering process end to end, through real requests as real roles.
 *
 * Direct service calls would prove the logic; they would not prove that a button reaches it, that
 * the right person is allowed to press it, or that the wrong person is refused. The separation of
 * duties is the point: the engineer drafts, a different person approves.
 */
class BomLifecycleFlowTest extends ManufacturingTestCase
{
    private function bomFor(string $code): Bom
    {
        return $this->asCompany($this->company, fn () => Bom::query()
            ->whereHas('item.stockItem', fn ($q) => $q->where('code', $code))
            ->orderByDesc('revision')->firstOrFail());
    }

    public function test_the_whole_engineering_process_from_product_to_default_recipe(): void
    {
        $stock = $this->material('CHAIR');
        $oak = $this->material('OAK');
        $glue = $this->material('GLUE');

        // 1. The engineer registers the product against the existing stock item.
        $this->actingAs($this->engineer)
            ->post(route('manufacturing.engineering.products.store'), [
                'stock_item_id' => $stock->id, 'kind' => 'FINISHED',
                'default_uom_id' => $this->uom('ea')->id,
            ])
            ->assertRedirect();

        $item = $this->asCompany($this->company, fn () => Item::where('stock_item_id', $stock->id)->firstOrFail());
        $this->assertSame('FINISHED', $item->kind);

        // 2. They draft a recipe for it.
        $this->actingAs($this->engineer)
            ->post(route('manufacturing.engineering.boms.store'), [
                'item_uuid' => $item->uuid, 'yield_quantity' => 1, 'yield_uom_id' => $this->uom('ea')->id,
            ])
            ->assertRedirect();

        $bom = $this->bomFor('CHAIR');
        $this->assertSame(Bom::DRAFT, $bom->status);
        $this->assertSame(1, (int) $bom->revision);

        // 3. Components.
        foreach ([[$oak, 4.0, 5], [$glue, 0.2, 0]] as [$component, $qty, $scrap]) {
            $this->actingAs($this->engineer)
                ->post(route('manufacturing.engineering.boms.lines.store', $bom->uuid), [
                    'component_stock_item_id' => $component->id, 'quantity_per' => $qty,
                    'uom_id' => $this->uom('kg')->id, 'scrap_percent' => $scrap, 'line_kind' => 'COMPONENT',
                ])
                ->assertRedirect();
        }

        $this->assertSame(2, $bom->fresh()->lines()->count());

        // 4. Submit it.
        $this->actingAs($this->engineer)->post(route('manufacturing.engineering.boms.submit', $bom->uuid))
            ->assertSessionHas('success');

        // 5. The engineer cannot sign off their own work: they do not hold the permission.
        $this->actingAs($this->engineer)->post(route('manufacturing.engineering.boms.approve', $bom->uuid))
            ->assertForbidden();
        $this->assertSame(Bom::DRAFT, $bom->fresh()->status);

        // 6. A different person does.
        $this->actingAs($this->approver)->post(route('manufacturing.engineering.boms.approve', $bom->uuid))
            ->assertSessionHas('success');

        $bom->refresh();
        $this->assertSame(Bom::APPROVED, $bom->status);
        $this->assertTrue((bool) $bom->is_default);
        $this->assertSame($this->approver->id, (int) $bom->approved_by);

        // 7. The product now reports an approved recipe.
        $page = $this->actingAs($this->planner)
            ->get(route('manufacturing.engineering.products.show', $item->uuid))
            ->assertOk()->viewData('page')['props'];

        $this->assertSame(1, $page['item']['approved_boms']);
    }

    public function test_an_approved_recipe_offers_revise_and_refuses_edits(): void
    {
        $bom = $this->approvedBom($this->product('DESK'), [[$this->material('PINE'), 3, 0, 'kg']]);
        $line = $bom->lines()->first();

        // The screen is told, by the payload, that this cannot be edited and can be revised.
        $props = $this->actingAs($this->engineer)
            ->get(route('manufacturing.engineering.boms.show', $bom->uuid))
            ->assertOk()->viewData('page')['props'];

        $this->assertFalse($props['can']['edit']);
        $this->assertTrue($props['can']['revise']);
        $this->assertFalse($props['bom']['is_editable']);

        // And the server agrees if somebody posts anyway.
        $this->actingAs($this->engineer)
            ->patch(route('manufacturing.engineering.boms.lines.update', [$bom->uuid, $line->uuid]), ['quantity_per' => 99])
            ->assertForbidden();

        $this->actingAs($this->engineer)
            ->patch(route('manufacturing.engineering.boms.update', $bom->uuid), ['yield_quantity' => 50])
            ->assertForbidden();

        $this->assertEquals(3, $line->fresh()->quantity_per);
    }

    public function test_revise_then_approve_replaces_the_default_and_keeps_the_original(): void
    {
        $item = $this->product('SHELF');
        $original = $this->approvedBom($item, [[$this->material('BIRCH'), 6, 0, 'kg']]);

        $this->actingAs($this->engineer)
            ->post(route('manufacturing.engineering.boms.revise', $original->uuid), ['reason' => 'Thicker board'])
            ->assertRedirect();

        $draft = $this->bomFor('SHELF');
        $this->assertSame(2, (int) $draft->revision);
        $this->assertSame(Bom::DRAFT, $draft->status);

        $this->actingAs($this->approver)
            ->post(route('manufacturing.engineering.boms.approve', $draft->uuid))
            ->assertSessionHas('success');

        $this->assertSame(Bom::APPROVED, $draft->fresh()->status);
        $this->assertSame(Bom::SUPERSEDED, $original->fresh()->status);
        $this->assertFalse((bool) $original->fresh()->is_default);
        $this->assertSame(1, $original->fresh()->lines()->count(), 'The superseded revision keeps its recipe.');
    }

    public function test_the_person_who_drafted_a_recipe_cannot_approve_it_even_if_granted_the_permission(): void
    {
        $this->engineer->givePermissionTo(RoleCatalog::APPROVE_BOMS);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $bom = $this->draftBom($this->product('TABLE'), [[$this->material('ASH'), 5, 0, 'kg']]);

        $this->actingAs($this->engineer->fresh())
            ->post(route('manufacturing.engineering.boms.approve', $bom->uuid))
            ->assertSessionHas('error');

        $this->assertSame(Bom::DRAFT, $bom->fresh()->status, 'Self-approval went through.');
    }

    public function test_a_recipe_with_no_components_cannot_be_submitted_or_approved(): void
    {
        $bom = $this->draftBom($this->product('EMPTY'), []);

        $this->actingAs($this->engineer)
            ->post(route('manufacturing.engineering.boms.submit', $bom->uuid))
            ->assertSessionHas('error');

        $this->actingAs($this->approver)
            ->post(route('manufacturing.engineering.boms.approve', $bom->uuid))
            ->assertSessionHas('error');

        $this->assertSame(Bom::DRAFT, $bom->fresh()->status);
    }

    public function test_a_bought_in_item_cannot_carry_a_recipe(): void
    {
        $raw = $this->product('COPPER-ROD', 'RAW_MATERIAL');

        $this->actingAs($this->engineer)
            ->post(route('manufacturing.engineering.boms.store'), ['item_uuid' => $raw->uuid, 'yield_quantity' => 1])
            ->assertSessionHas('error');

        $this->assertSame(0, $this->asCompany($this->company, fn () => Bom::where('item_id', $raw->id)->count()));
    }

    public function test_a_product_cannot_become_a_bought_item_while_it_has_a_live_recipe(): void
    {
        $item = $this->product('LAMP');
        $this->approvedBom($item, [[$this->material('BRASS'), 1, 0, 'kg']]);

        $this->actingAs($this->engineer)
            ->patch(route('manufacturing.engineering.products.update', $item->uuid), ['kind' => 'RAW_MATERIAL'])
            ->assertSessionHas('error');

        $this->assertSame('FINISHED', $item->fresh()->kind);
    }

    public function test_a_component_cannot_be_the_product_itself(): void
    {
        $item = $this->product('LOOPY');
        $bom = $this->draftBom($item, [[$this->material('OKAY'), 1, 0, 'kg']]);

        $this->actingAs($this->engineer)
            ->post(route('manufacturing.engineering.boms.lines.store', $bom->uuid), [
                'component_stock_item_id' => $item->stock_item_id, 'quantity_per' => 1, 'line_kind' => 'COMPONENT',
            ])
            ->assertSessionHas('error');

        $this->assertSame(1, $bom->fresh()->lines()->count());
    }

    public function test_the_explosion_page_reports_a_loop_instead_of_failing(): void
    {
        $a = $this->product('PH-A', 'SUBASSEMBLY', 'ea');
        $b = $this->product('PH-B', 'SUBASSEMBLY', 'ea');
        $bomA = $this->approvedBom($a, [[$b->stockItem, 1, 0, 'ea', ['is_phantom' => true]]]);
        $this->approvedBom($b, [[$a->stockItem, 1, 0, 'ea', ['is_phantom' => true]]]);

        $props = $this->actingAs($this->planner)
            ->get(route('manufacturing.engineering.boms.explode', $bomA->uuid).'?quantity=1')
            ->assertOk()->viewData('page')['props'];

        $this->assertNull($props['result']);
        $this->assertStringContainsString('loops', $props['error']);
    }

    public function test_the_explosion_page_shows_whole_batches(): void
    {
        $bom = $this->approvedBom($this->product('PAINTED'), [[$this->material('LACQUER'), 4, 0, 'kg']], 10);

        $props = $this->actingAs($this->planner)
            ->get(route('manufacturing.engineering.boms.explode', $bom->uuid).'?quantity=25&whole_batches=1')
            ->assertOk()->viewData('page')['props'];

        $this->assertSame(3, $props['result']['batches']);
        $this->assertEqualsWithDelta(12.0, $props['result']['requirements'][0]['quantity'], 0.0001);
    }

    public function test_two_revisions_of_different_products_cannot_be_compared(): void
    {
        $one = $this->approvedBom($this->product('ONE'), [[$this->material('M1'), 1, 0, 'kg']]);
        $two = $this->approvedBom($this->product('TWO'), [[$this->material('M2'), 1, 0, 'kg']]);

        $this->actingAs($this->planner)
            ->get(route('manufacturing.engineering.boms.compare', [$one->uuid, $two->uuid]))
            ->assertNotFound();
    }

    public function test_a_substitution_needs_approval_by_someone_who_holds_the_permission(): void
    {
        $item = $this->product('SEAT');
        $alt = $this->material('FOAM-B');

        $this->actingAs($this->engineer)
            ->post(route('manufacturing.engineering.products.substitutes.store', $item->uuid), [
                'substitute_stock_item_id' => $alt->id, 'ratio' => 1.2, 'requires_approval' => true,
            ])
            ->assertSessionHas('success');

        $sub = $item->substitutes()->first();
        $this->assertFalse($sub->isUsable(), 'An unapproved substitution must not be usable.');

        // The engineer holds mfg.item.manage but not mfg.substitution.approve.
        $this->actingAs($this->engineer)
            ->post(route('manufacturing.engineering.products.substitutes.approve', [$item->uuid, $sub->uuid]))
            ->assertForbidden();

        $this->actingAs($this->approver)
            ->post(route('manufacturing.engineering.products.substitutes.approve', [$item->uuid, $sub->uuid]))
            ->assertSessionHas('success');

        $this->assertTrue($sub->fresh()->isUsable());
    }

    public function test_a_document_record_must_point_at_something(): void
    {
        $item = $this->product('GATE');

        $this->actingAs($this->engineer)
            ->post(route('manufacturing.engineering.products.documents.store', $item->uuid), [
                'kind' => 'DRAWING', 'title' => 'GA drawing',
            ])
            ->assertSessionHas('error');

        $this->actingAs($this->engineer)
            ->post(route('manufacturing.engineering.products.documents.store', $item->uuid), [
                'kind' => 'DRAWING', 'title' => 'GA drawing', 'external_reference' => 'CUST-DWG-4471',
            ])
            ->assertSessionHas('success');

        $this->assertSame(1, $item->documents()->count());
    }

    public function test_approving_an_engineering_version_supersedes_the_previous_one(): void
    {
        $item = $this->product('VALVE');

        foreach (['A', 'B'] as $version) {
            $this->actingAs($this->engineer)
                ->post(route('manufacturing.engineering.products.versions.store', $item->uuid), [
                    'version' => $version, 'change_reason' => "Issue {$version}",
                ])->assertSessionHas('success');

            $uuid = $item->versions()->where('version', $version)->value('uuid');

            $this->actingAs($this->engineer)
                ->post(route('manufacturing.engineering.products.versions.approve', [$item->uuid, $uuid]))
                ->assertSessionHas('success');
        }

        $this->assertSame('SUPERSEDED', $item->versions()->where('version', 'A')->value('status'));
        $this->assertSame('APPROVED', $item->versions()->where('version', 'B')->value('status'));
        $this->assertNotNull($item->versions()->where('version', 'A')->value('effective_to'));
    }
}
