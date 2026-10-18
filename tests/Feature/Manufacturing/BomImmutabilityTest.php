<?php

namespace Tests\Feature\Manufacturing;

use App\Modules\Operations\Manufacturing\Models\Bom;
use App\Modules\Operations\Manufacturing\Models\BomApproval;
use App\Modules\Operations\Manufacturing\Services\BomVersioning;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * MFG-002 acceptance criteria:
 *
 *   "An approved BOM revision cannot be silently overwritten. Historical production orders must
 *    retain the BOM version used when they were released."
 *
 * The first half is enforced by database triggers, and so is tested at the database -- through
 * the query builder, deliberately bypassing every model, policy and controller. A guarantee that
 * only holds when callers behave is not the guarantee the requirement asks for.
 */
class BomImmutabilityTest extends ManufacturingTestCase
{
    /**
     * Assert the database refuses a write, and leave the connection usable afterwards.
     *
     * A caught QueryException does not restore a Postgres connection: the failed statement aborts
     * the whole transaction, and RefreshDatabase runs every test inside one. Wrapping the write in
     * DB::transaction() makes it a savepoint, which is the only thing that recovers.
     */
    private function assertRefused(callable $write, string $expectedFragment, string $context = ''): void
    {
        try {
            DB::transaction($write);
        } catch (QueryException $e) {
            $this->assertStringContainsString($expectedFragment, $e->getMessage(), $context);

            return;
        }

        $this->fail('The database accepted a write it should have refused. '.$context);
    }

    private function approved(): Bom
    {
        $product = $this->product('WIDGET');
        $steel = $this->material('STEEL');
        $paint = $this->material('PAINT');

        return $this->approvedBom($product, [[$steel, 2.5, 0, 'kg'], [$paint, 0.1, 0, 'kg']]);
    }

    public function test_a_line_of_an_approved_bom_cannot_be_changed_even_by_a_raw_query(): void
    {
        $bom = $this->approved();
        $line = DB::table('mfg_bom_lines')->where('bom_id', $bom->id)->first();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('is approved; create a new revision');

        DB::table('mfg_bom_lines')->where('id', $line->id)->update(['quantity_per' => 99]);
    }

    public function test_a_line_cannot_be_added_to_or_removed_from_an_approved_bom(): void
    {
        $bom = $this->approved();
        $line = DB::table('mfg_bom_lines')->where('bom_id', $bom->id)->first();
        $extra = $this->material('BOLT');

        $this->assertRefused(fn () => DB::table('mfg_bom_lines')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'company_id' => $this->company->id,
            'bom_id' => $bom->id, 'line_no' => 90, 'component_stock_item_id' => $extra->id,
            'quantity_per' => 1, 'scrap_percent' => 0, 'line_kind' => 'COMPONENT',
            'is_phantom' => false, 'is_optional' => false, 'created_at' => now(), 'updated_at' => now(),
        ]), 'is approved', 'insert');

        $this->assertRefused(
            fn () => DB::table('mfg_bom_lines')->where('id', $line->id)->delete(),
            'is approved', 'delete'
        );

        $this->assertSame(2, DB::table('mfg_bom_lines')->where('bom_id', $bom->id)->count());
    }

    public function test_the_recipe_fields_of_an_approved_header_are_refused_but_retirement_is_not(): void
    {
        $bom = $this->approved();

        foreach (['yield_quantity' => 500, 'batch_size' => 50, 'process_loss_percent' => 30, 'revision' => 9] as $column => $value) {
            $this->assertRefused(
                fn () => DB::table('mfg_boms')->where('id', $bom->id)->update([$column => $value]),
                'its recipe cannot be edited', $column
            );
        }

        // Retiring is how a revision is put away, and must stay possible.
        DB::table('mfg_boms')->where('id', $bom->id)->update(['status' => 'OBSOLETE', 'is_default' => false]);
        $this->assertSame('OBSOLETE', DB::table('mfg_boms')->where('id', $bom->id)->value('status'));
    }

    public function test_a_draft_can_still_be_edited_freely(): void
    {
        $product = $this->product('GADGET');
        $bom = $this->draftBom($product, [[$this->material('COPPER'), 1, 0, 'kg']]);

        DB::table('mfg_boms')->where('id', $bom->id)->update(['yield_quantity' => 12]);
        DB::table('mfg_bom_lines')->where('bom_id', $bom->id)->update(['quantity_per' => 3]);

        $this->assertEquals(12, DB::table('mfg_boms')->where('id', $bom->id)->value('yield_quantity'));
        $this->assertEquals(3, DB::table('mfg_bom_lines')->where('bom_id', $bom->id)->value('quantity_per'));
    }

    public function test_revising_copies_the_recipe_into_a_new_draft_and_leaves_the_original_untouched(): void
    {
        $original = $this->approved();
        $before = DB::table('mfg_bom_lines')->where('bom_id', $original->id)->orderBy('line_no')->get(['quantity_per', 'line_no']);

        $draft = $this->asCompany($this->company, fn () => app(BomVersioning::class)
            ->revise($original, $this->engineer->id, 'Supplier changed the gauge'));

        $this->assertSame(2, (int) $draft->revision);
        $this->assertSame(Bom::DRAFT, $draft->status);
        $this->assertFalse((bool) $draft->is_default);
        $this->assertSame(2, $draft->lines()->count(), 'The draft must carry the original components.');

        $original->refresh();
        $this->assertSame(Bom::APPROVED, $original->status);
        $this->assertTrue((bool) $original->is_default, 'Revising must not take the default away from the live revision.');

        $after = DB::table('mfg_bom_lines')->where('bom_id', $original->id)->orderBy('line_no')->get(['quantity_per', 'line_no']);
        $this->assertEquals($before, $after);

        // And the history says where the draft came from.
        $this->assertTrue(
            BomApproval::withoutGlobalScopes()->where('bom_id', $draft->id)->where('action', BomApproval::REVISED)->exists()
        );
    }

    public function test_approving_the_revision_supersedes_the_original_and_keeps_it_on_the_record(): void
    {
        $original = $this->approved();
        $versioning = app(BomVersioning::class);

        $draft = $this->asCompany($this->company, fn () => $versioning->revise($original, $this->engineer->id));

        // A different person signs it off -- the engineer drafted it.
        $approved = $this->asCompany($this->company, fn () => $versioning->approve($draft, $this->approver->id));

        $this->assertSame(Bom::APPROVED, $approved->status);
        $this->assertTrue((bool) $approved->is_default);

        $original->refresh();
        $this->assertSame(Bom::SUPERSEDED, $original->status);
        $this->assertFalse((bool) $original->is_default);
        $this->assertSame($approved->id, (int) $original->superseded_by_id);
        $this->assertNotNull($original->effective_to, 'A retired revision must say when it stopped applying.');

        // Still readable, still carrying its components: a released order is costed against it.
        $this->assertSame(2, $original->lines()->count());
    }

    public function test_a_product_has_at_most_one_default_approved_bom(): void
    {
        $original = $this->approved();
        $second = $this->draftBom($original->item, [[$this->material('ZINC'), 1, 0, 'kg']]);

        // Forcing a second approved default for the same product must be refused by the index.
        $this->assertRefused(
            fn () => DB::table('mfg_boms')->where('id', $second->id)->update(['status' => 'APPROVED', 'is_default' => true]),
            'mfg_bom_one_default'
        );
    }
}
