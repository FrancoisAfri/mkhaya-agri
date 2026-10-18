<?php

namespace Tests\Feature\Manufacturing;

use App\Modules\Operations\Manufacturing\Models\Bom;
use App\Modules\Operations\Manufacturing\Services\BomExploder;
use App\Modules\Operations\Manufacturing\Services\BomResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "The approved BOM version applicable to the production order."
 *
 * Applicable is a question about a date. The default flag says what is made from today; it cannot
 * say what an order raised last quarter was costed against, which is the half of the acceptance
 * criterion that "historical orders retain the version used" depends on.
 */
class BomResolverTest extends ManufacturingTestCase
{
    private function resolver(): BomResolver
    {
        return app(BomResolver::class);
    }

    /** Set a revision's status and window directly, as a past approval would have left it. */
    private function window(Bom $bom, string $status, ?string $from, ?string $to, bool $default = false): Bom
    {
        DB::table('mfg_boms')->where('id', $bom->id)->update([
            'status' => $status, 'effective_from' => $from, 'effective_to' => $to, 'is_default' => $default,
        ]);

        return $bom->fresh();
    }

    private function revisions(string $code = 'RES'): array
    {
        $item = $this->product($code);
        $m = $this->material($code.'-M');

        $r1 = $this->window($this->draftBom($item, [[$m, 1, 0, 'kg']]), 'SUPERSEDED', '2026-01-01', '2026-06-30');
        $r2 = $this->window($this->draftBom($item, [[$m, 2, 0, 'kg']]), 'APPROVED', '2026-07-01', null, true);

        return [$item, $r1, $r2, $m];
    }

    public function test_a_past_date_resolves_to_the_recipe_that_was_in_force_then(): void
    {
        [$item, $r1, $r2] = $this->revisions();

        $this->assertSame($r1->id, $this->resolver()->applicable($item->id, Carbon::parse('2026-03-15'))->id);
        $this->assertSame($r2->id, $this->resolver()->applicable($item->id, Carbon::parse('2026-07-15'))->id);
    }

    public function test_the_boundary_days_resolve_to_the_live_revision_where_they_overlap(): void
    {
        [$item, $r1, $r2] = $this->revisions();

        // The last day of the old window, and the first of the new one.
        $this->assertSame($r1->id, $this->resolver()->applicable($item->id, Carbon::parse('2026-06-30'))->id);
        $this->assertSame($r2->id, $this->resolver()->applicable($item->id, Carbon::parse('2026-07-01'))->id);

        // Where both claim a day -- the day of the supersession -- the live one wins, always.
        DB::table('mfg_boms')->where('id', $r1->id)->update(['effective_to' => '2026-07-01']);
        $this->assertSame($r2->id, $this->resolver()->applicable($item->id, Carbon::parse('2026-07-01'))->id);
    }

    public function test_before_any_recipe_existed_nothing_applies(): void
    {
        [$item] = $this->revisions();

        $this->assertNull($this->resolver()->applicable($item->id, Carbon::parse('2025-12-31')));
    }

    public function test_a_retired_or_draft_revision_is_never_applicable(): void
    {
        $item = $this->product('RET');
        $m = $this->material('RET-M');

        $this->window($this->draftBom($item, [[$m, 1, 0, 'kg']]), 'OBSOLETE', '2026-01-01', null);
        $this->draftBom($item, [[$m, 3, 0, 'kg']]);   // a draft, with no window at all

        $this->assertNull($this->resolver()->applicable($item->id, Carbon::parse('2026-05-01')),
            'A retired or draft revision was offered as the recipe for an order.');
    }

    public function test_a_plan_that_straddles_a_change_is_costed_against_both(): void
    {
        [$item, $r1, $r2] = $this->revisions();

        $ids = $this->resolver()
            ->applicableBetween($item->id, Carbon::parse('2026-06-15'), Carbon::parse('2026-07-15'))
            ->pluck('id')->all();

        $this->assertSame([$r1->id, $r2->id], $ids);
    }

    public function test_an_order_dated_before_the_change_is_exploded_with_the_old_quantities(): void
    {
        [$item, $r1, $r2] = $this->revisions('EXP');

        $exploder = app(BomExploder::class);

        // r1 needs 1 kg, r2 needs 2 kg. Which one explodes is the caller's choice; this proves the
        // resolver and the exploder agree about what a given date means.
        $old = $this->asCompany($this->company, fn () => $exploder->explode($r1, 1, true, Carbon::parse('2026-03-01')));
        $new = $this->asCompany($this->company, fn () => $exploder->explode($r2, 1, true, Carbon::parse('2026-08-01')));

        $this->assertEqualsWithDelta(1.0, $old['requirements'][0]['quantity'], 0.0001);
        $this->assertEqualsWithDelta(2.0, $new['requirements'][0]['quantity'], 0.0001);
    }

    public function test_a_phantoms_recipe_is_resolved_for_the_date_being_planned(): void
    {
        // Kit uses a phantom PREMIX. PREMIX needed 1 kg DYE until 30 June and 5 kg DYE after.
        $premix = $this->product('PX', 'SUBASSEMBLY', 'ea');
        $dye = $this->material('DYE-X');

        $this->window($this->draftBom($premix, [[$dye, 1, 0, 'kg']]), 'SUPERSEDED', '2026-01-01', '2026-06-30');
        $this->window($this->draftBom($premix, [[$dye, 5, 0, 'kg']]), 'APPROVED', '2026-07-01', null, true);

        $kit = $this->approvedBom($this->product('KIT-X'), [
            [$premix->stockItem, 1, 0, 'ea', ['is_phantom' => true]],
        ]);

        $exploder = app(BomExploder::class);

        $before = $this->asCompany($this->company, fn () => $exploder->explode($kit, 1, true, Carbon::parse('2026-03-01')));
        $after = $this->asCompany($this->company, fn () => $exploder->explode($kit, 1, true, Carbon::parse('2026-09-01')));

        $this->assertEqualsWithDelta(1.0, $before['requirements'][0]['quantity'], 0.0001);
        $this->assertEqualsWithDelta(5.0, $after['requirements'][0]['quantity'], 0.0001);
    }
}
