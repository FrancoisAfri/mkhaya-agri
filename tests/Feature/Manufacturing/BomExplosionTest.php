<?php

namespace Tests\Feature\Manufacturing;

use App\Modules\Operations\Manufacturing\Models\Bom;
use App\Modules\Operations\Manufacturing\Models\BomLine;
use App\Modules\Operations\Manufacturing\Services\BomExploder;
use DomainException;

/**
 * What it takes to make a quantity.
 *
 * The failure mode here is a plausible wrong number: a requirement off by the density, by a batch,
 * or by a thousand looks perfectly reasonable on a screen. Every figure below is worked out by
 * hand in the comment above it, so a regression has to disagree with arithmetic rather than with
 * another piece of code.
 */
class BomExplosionTest extends ManufacturingTestCase
{
    private function explode(Bom $bom, float $quantity, bool $wholeBatches = true): array
    {
        return $this->asCompany($this->company, fn () => app(BomExploder::class)
            ->explode($bom->fresh(), $quantity, $wholeBatches));
    }

    /** @return array<string, float> item code => quantity */
    private function byCode(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[$r['item_code']] = round($r['quantity'], 6);
        }

        return $out;
    }

    public function test_a_flat_recipe_needs_its_stated_quantities(): void
    {
        // 2.5 kg steel + 0.1 kg paint per 1 unit.
        $bom = $this->approvedBom($this->product('W1'), [
            [$this->material('STEEL'), 2.5, 0, 'kg'],
            [$this->material('PAINT'), 0.1, 0, 'kg'],
        ]);

        $r = $this->explode($bom, 1);

        $this->assertSame(1, $r['batches']);
        $this->assertSame(['STEEL' => 2.5, 'PAINT' => 0.1], $this->byCode($r['requirements']));
    }

    public function test_scrap_is_added_to_the_requirement_not_taken_from_the_yield(): void
    {
        // 100 kg net at 10% scrap: to have 100 kg survive you must issue 100 / 0.9 = 111.111111 kg.
        // Subtracting instead would issue 110 kg and be 1 kg short after the loss.
        $bom = $this->approvedBom($this->product('W2'), [[$this->material('ALLOY'), 100, 10, 'kg']]);

        $this->assertEqualsWithDelta(111.111111, $this->explode($bom, 1)['requirements'][0]['quantity'], 0.0001);
    }

    public function test_whole_batches_round_up_because_a_process_cannot_be_run_part_way(): void
    {
        // Yield 10 per batch, make 25: ceil(25/10) = 3 batches, 30 produced, material x3 not x2.5.
        $bom = $this->approvedBom($this->product('W3'), [[$this->material('RESIN'), 4, 0, 'kg']], 10);

        $whole = $this->explode($bom, 25, true);
        $this->assertSame(3, $whole['batches']);
        $this->assertEquals(30, $whole['produced']);
        $this->assertEqualsWithDelta(12.0, $whole['requirements'][0]['quantity'], 0.0001); // 4 kg x 3

        // A costing question wants the linear figure: 25 / 10 = 2.5 batches' worth.
        $linear = $this->explode($bom, 25, false);
        $this->assertEqualsWithDelta(10.0, $linear['requirements'][0]['quantity'], 0.0001); // 4 kg x 2.5
    }

    public function test_batch_size_can_differ_from_yield(): void
    {
        // Recipe yields 10, but the vessel runs 50 at a time. Make 120: ceil(120/50) = 3 batches,
        // 150 produced, so material is x(150/10) = x15, not x12.
        $bom = $this->approvedBom(
            $this->product('W4'), [[$this->material('SOLVENT'), 2, 0, 'kg']], 10, ['batch_size' => 50]
        );

        $r = $this->explode($bom, 120);

        $this->assertSame(3, $r['batches']);
        $this->assertEquals(150, $r['produced']);
        $this->assertEqualsWithDelta(30.0, $r['requirements'][0]['quantity'], 0.0001); // 2 kg x 15
    }

    public function test_a_phantom_is_exploded_through_and_never_raised_as_a_requirement(): void
    {
        // Kit needs 2 x PREMIX per unit. PREMIX is a phantom; one PREMIX is 3 kg DYE + 1 kg BASE.
        // So the kit needs 6 kg DYE and 2 kg BASE, and no PREMIX at all.
        $dye = $this->material('DYE');
        $base = $this->material('BASE');
        $premix = $this->product('PREMIX', 'SUBASSEMBLY', 'ea');
        $this->approvedBom($premix, [[$dye, 3, 0, 'kg'], [$base, 1, 0, 'kg']]);

        $kit = $this->approvedBom($this->product('KIT'), [
            [$premix->stockItem, 2, 0, 'ea', ['is_phantom' => true]],
        ]);

        $codes = $this->byCode($this->explode($kit, 1)['requirements']);

        $this->assertSame(['DYE' => 6.0, 'BASE' => 2.0], $codes);
        $this->assertArrayNotHasKey('PREMIX', $codes, 'A phantom is never stocked, so it must never be a requirement.');
    }

    public function test_a_stocked_subassembly_is_issued_not_exploded(): void
    {
        // The same subassembly, NOT marked phantom: it has its own production order, so the parent
        // asks for it rather than for what it is made of.
        $premix = $this->product('PREMIX2', 'SUBASSEMBLY', 'ea');
        $this->approvedBom($premix, [[$this->material('DYE2'), 3, 0, 'kg']]);

        $kit = $this->approvedBom($this->product('KIT2'), [[$premix->stockItem, 2, 0, 'ea']]);

        $this->assertSame(['PREMIX2' => 2.0], $this->byCode($this->explode($kit, 1)['requirements']));
    }

    public function test_a_phantom_with_nothing_behind_it_is_an_error_not_a_material(): void
    {
        $orphan = $this->material('GHOST', 'ea');
        $kit = $this->approvedBom($this->product('KIT3'), [[$orphan, 1, 0, 'ea', ['is_phantom' => true]]]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('phantom');

        $this->explode($kit, 1);
    }

    public function test_a_loop_is_refused_by_name_rather_than_followed(): void
    {
        // A contains phantom B, B contains phantom A.
        $a = $this->product('LOOP-A', 'SUBASSEMBLY', 'ea');
        $b = $this->product('LOOP-B', 'SUBASSEMBLY', 'ea');

        $bomA = $this->approvedBom($a, [[$b->stockItem, 1, 0, 'ea', ['is_phantom' => true]]]);
        $this->approvedBom($b, [[$a->stockItem, 1, 0, 'ea', ['is_phantom' => true]]]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('loops');

        $this->explode($bomA, 1);
    }

    public function test_a_by_product_is_reported_but_not_demanded(): void
    {
        $bom = $this->approvedBom($this->product('W5'), [
            [$this->material('ORE'), 10, 0, 'kg'],
            [$this->material('SLAG'), 2, 0, 'kg', ['line_kind' => BomLine::BY_PRODUCT]],
        ]);

        $r = $this->explode($bom, 1);

        $this->assertSame(['ORE' => 10.0], $this->byCode($r['requirements']));
        $this->assertSame(['SLAG' => 2.0], $this->byCode($r['by_products']));
    }

    public function test_a_phantom_asked_for_in_another_unit_is_converted_before_dividing(): void
    {
        // The kit calls for 3 kg of PREMIX3, whose own recipe yields 500 g per batch and uses 1 kg
        // of BINDER per batch. 3 kg = 3000 g = 6 batches, so 6 kg BINDER.
        // Without the unit conversion this divides 3 by 500 and reports 0.006 kg -- a figure that
        // is wrong by 1000x and looks completely plausible on a screen.
        $binder = $this->material('BINDER');
        $premix = $this->product('PREMIX3', 'SUBASSEMBLY', 'g');
        $this->approvedBom($premix, [[$binder, 1, 0, 'kg']], 500); // yields 500 (g)

        $kit = $this->approvedBom($this->product('KIT4'), [
            [$premix->stockItem, 3, 0, 'kg', ['is_phantom' => true]],
        ]);

        $this->assertEqualsWithDelta(6.0, $this->explode($kit, 1)['requirements'][0]['quantity'], 0.0001);
    }

    public function test_the_same_material_on_two_lines_adds_up(): void
    {
        $steel = $this->material('STEEL2');
        $bom = $this->approvedBom($this->product('W6'), [[$steel, 2, 0, 'kg'], [$steel, 3, 0, 'kg']]);

        $r = $this->explode($bom, 1);

        $this->assertCount(1, $r['requirements']);
        $this->assertEqualsWithDelta(5.0, $r['requirements'][0]['quantity'], 0.0001);
    }

    public function test_a_hundred_percent_scrap_is_refused_because_it_has_no_finite_requirement(): void
    {
        $bom = $this->approvedBom($this->product('W7'), [[$this->material('DUST'), 1, 100, 'kg']]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('100%');

        $this->explode($bom, 1);
    }

    public function test_exploding_a_zero_quantity_is_refused(): void
    {
        $bom = $this->approvedBom($this->product('W8'), [[$this->material('NIL'), 1, 0, 'kg']]);

        $this->expectException(DomainException::class);

        $this->explode($bom, 0);
    }

    public function test_comparison_reports_what_was_added_removed_and_changed_by_component(): void
    {
        $product = $this->product('W9');
        $keep = $this->material('KEEP');
        $drop = $this->material('DROP');
        $edit = $this->material('EDIT');
        $add = $this->material('ADD');

        $left = $this->draftBom($product, [[$keep, 1, 0, 'kg'], [$drop, 2, 0, 'kg'], [$edit, 3, 0, 'kg']]);
        $right = $this->draftBom($product, [[$keep, 1, 0, 'kg'], [$edit, 4, 0, 'kg'], [$add, 5, 0, 'kg']]);

        $diff = $this->asCompany($this->company, fn () => app(BomExploder::class)->compare($left, $right));

        $this->assertSame(['ADD'], array_column($diff['added'], 'item_code'));
        $this->assertSame(['DROP'], array_column($diff['removed'], 'item_code'));
        $this->assertSame(['EDIT'], array_column($diff['changed'], 'item_code'));
        $this->assertSame(1, $diff['unchanged']);
        $this->assertEquals(
            ['from' => 3.0, 'to' => 4.0],
            $diff['changed'][0]['differences']['quantity_per']
        );
    }

    public function test_where_used_reports_live_revisions_only_unless_asked(): void
    {
        $shared = $this->material('SHARED');
        $live = $this->approvedBom($this->product('USER1'), [[$shared, 1, 0, 'kg']]);

        $retired = $this->draftBom($this->product('USER2'), [[$shared, 1, 0, 'kg']]);
        \Illuminate\Support\Facades\DB::table('mfg_boms')->where('id', $retired->id)->update(['status' => 'OBSOLETE']);

        $exploder = app(BomExploder::class);

        $liveOnly = $this->asCompany($this->company, fn () => $exploder->whereUsed($shared->id));
        $this->assertCount(1, $liveOnly);
        $this->assertSame($live->uuid, $liveOnly[0]['bom_uuid']);

        $all = $this->asCompany($this->company, fn () => $exploder->whereUsed($shared->id, false));
        $this->assertCount(2, $all);
    }
}
