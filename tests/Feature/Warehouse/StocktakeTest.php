<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\WarehouseManagement\Models\Count;
use App\Modules\Operations\WarehouseManagement\Models\CountEntry;
use App\Modules\Operations\WarehouseManagement\Models\CountLine;
use App\Modules\Operations\WarehouseManagement\Models\CountPlan;
use App\Modules\Operations\WarehouseManagement\Models\ItemPackaging;
use App\Modules\Operations\WarehouseManagement\Models\StockAdjustment;
use App\Modules\Operations\WarehouseManagement\Services\BinTransfers;
use App\Modules\Operations\WarehouseManagement\Services\CountInsights;
use App\Modules\Operations\WarehouseManagement\Services\Counting;
use App\Modules\Operations\WarehouseManagement\Services\ItemCatalogue;
use App\Modules\Operations\WarehouseManagement\Services\LocationService;
use App\Modules\Operations\WarehouseManagement\Services\StockAdjustments;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use App\Core\User\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** WMS-070 to WMS-073: count plans, counting while the floor works, recounts, review, posting and accuracy. */
class StocktakeTest extends WarehouseTestCase
{
    private FinWarehouse $wh;

    private FinStockItem $item;

    private FinStockBin $b1;

    private FinStockBin $b2;

    private User $counter;

    private User $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();
        $this->wh = $this->warehouse('DC1', ['policies' => ['scan_to_confirm' => false]]);
        $this->asCompany($this->company, function () {
            app(ItemCatalogue::class)->ensureUnits($this->company->id);
            app(StockAdjustments::class)->ensureReasonCodes($this->company->id);
            $loc = app(LocationService::class);
            $this->b1 = $loc->create($this->wh, ['code' => 'A-01']);
            $this->b2 = $loc->create($this->wh, ['code' => 'A-02']);
            $this->item = app(ItemCatalogue::class)->create(['company_id' => $this->company->id, 'code' => 'RICE', 'name' => 'Rice 2kg', 'uom' => 'ea', 'standard_cost' => 20]);
        });
        $this->counter = $this->person('counter', [RoleCatalog::VIEW, RoleCatalog::COUNT]);
        $this->second = $this->person('second', [RoleCatalog::VIEW, RoleCatalog::COUNT]);
        $this->manager->givePermissionTo(RoleCatalog::all());
        $this->actingAs($this->manager);
        $this->stock($this->b1, 10);
        $this->stock($this->b2, 4);
    }

    private function stock(FinStockBin $bin, float $qty): void
    {
        $this->asCompany($this->company, fn () => app(StockLedger::class)->receive($this->item->fresh(), $qty, 20, $this->wh, ['bin_id' => $bin->id]));
    }

    private function c(): Counting
    {
        return app(Counting::class);
    }

    private function open(array $extra = []): Count
    {
        return $this->asCompany($this->company, fn () => $this->c()->open($extra + ['warehouse_id' => $this->wh->id], $this->manager));
    }

    private function line(Count $count, FinStockBin $bin): CountLine
    {
        return CountLine::query()->where('count_id', $count->id)->where('bin_id', $bin->id)->firstOrFail();
    }

    private function onHand(FinStockBin $bin): float
    {
        return (float) FinStockPosition::withoutGlobalScopes()->where('bin_id', $bin->id)->sum('qty');
    }

    // ── WMS-070 plans and scope ──────────────────────────────────────────────────────────────

    public function test_a_count_snapshots_every_place_that_holds_stock(): void
    {
        $count = $this->open();

        $this->assertMatchesRegularExpression('/^CNT-\d{4}-00001$/', $count->number);
        $this->assertSame(2, $count->lines()->count());
        $this->assertEquals(10, (float) $this->line($count, $this->b1)->snapshot_qty);
        $this->assertTrue($count->blind, 'Counts are blind unless chosen otherwise.');
    }

    public function test_a_plan_runs_when_due_and_schedules_its_next_run(): void
    {
        $plan = $this->asCompany($this->company, fn () => CountPlan::create([
            'company_id' => $this->company->id, 'name' => 'Weekly A bins', 'warehouse_id' => $this->wh->id, 'frequency' => 'WEEKLY',
            'scope' => 'BINS', 'scope_ids' => [$this->b2->id], 'next_run_at' => now()->subHour(),
        ]));

        $opened = $this->asCompany($this->company, fn () => $this->c()->runDuePlans());

        $this->assertSame(1, $opened);
        $count = Count::query()->where('plan_id', $plan->id)->firstOrFail();
        $this->assertSame([$this->b2->id], $count->lines()->pluck('bin_id')->all(), 'The plan counted outside its scope.');
        $this->assertTrue($plan->fresh()->next_run_at->isAfter(now()->addDays(6)));
    }

    public function test_abc_classification_drives_an_abc_scoped_count(): void
    {
        $slow = $this->asCompany($this->company, fn () => app(ItemCatalogue::class)->create(['company_id' => $this->company->id, 'code' => 'SALT', 'name' => 'Salt', 'uom' => 'ea', 'standard_cost' => 1]));
        $this->asCompany($this->company, fn () => app(StockLedger::class)->receive($slow->fresh(), 5, 1, $this->wh, ['bin_id' => $this->b2->id]));
        $this->asCompany($this->company, fn () => app(StockLedger::class)->issueToProduction($this->item->fresh(), 6, $this->wh, ['bin_id' => $this->b1->id, 'source_type' => 'TEST', 'source_id' => 1]));

        $classes = $this->asCompany($this->company, fn () => app(CountInsights::class)->classify($this->company->id));
        $this->assertSame(1, $classes['A']);

        $count = $this->open(['scope' => 'ABC', 'abc_class' => 'A']);
        $this->assertSame([$this->item->id], $count->lines()->pluck('item_id')->unique()->values()->all());
    }

    // ── WMS-071 counting while the warehouse works, or frozen ─────────────────────────────────

    public function test_a_count_compares_with_the_ledger_at_the_moment_of_counting(): void
    {
        $count = $this->open();
        $this->stock($this->b1, 5); // received after the snapshot

        $entry = $this->asCompany($this->company, fn () => $this->c()->record($this->line($count, $this->b1), 15, $this->counter));

        $line = $this->line($count, $this->b1);
        $this->assertSame(CountLine::MATCHED, $line->status, 'Stock received during the count was reported as a variance.');
        $this->assertEquals(5, (float) $entry->moved_since_snapshot);
        $this->assertSame(Count::COUNTING, $count->fresh()->status);
    }

    public function test_a_frozen_count_stops_movements_in_its_bins_until_it_closes(): void
    {
        $count = $this->open(['freeze' => true, 'scope' => 'BINS', 'scope_ids' => [$this->b1->id]]);

        try {
            $this->stock($this->b1, 1);
            $this->fail('A frozen bin took a receipt.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('frozen for stock count', $e->getMessage());
        }
        $this->stock($this->b2, 1); // other bins carry on

        $this->asCompany($this->company, fn () => $this->c()->cancel($count, $this->manager, 'Planned for the weekend instead'));
        $this->stock($this->b1, 1);
        $this->assertEquals(11, $this->onHand($this->b1));
    }

    public function test_a_retried_entry_is_recorded_once_and_entries_cannot_be_edited(): void
    {
        $count = $this->open();
        $line = $this->line($count, $this->b1);

        $one = $this->asCompany($this->company, fn () => $this->c()->record($line, 10, $this->counter, 'device-7:1'));
        $two = $this->asCompany($this->company, fn () => $this->c()->record($line, 10, $this->counter, 'device-7:1'));
        $this->assertSame($one->id, $two->id);
        $this->assertSame(1, CountEntry::query()->where('count_line_id', $line->id)->count());

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('wms_count_entries')->where('id', $one->id)->update(['qty' => 3]);
    }

    // ── WMS-072 recounts and variance review ─────────────────────────────────────────────────

    public function test_a_variance_goes_to_a_different_counter_and_two_agreeing_counts_stand(): void
    {
        $count = $this->open(['recount_qty' => 1]);
        $line = $this->line($count, $this->b1);

        $this->asCompany($this->company, fn () => $this->c()->record($line, 7, $this->counter));
        $this->assertSame(CountLine::RECOUNT, $line->fresh()->status);
        $this->assertSame(2, $line->fresh()->round);

        try {
            $this->asCompany($this->company, fn () => $this->c()->record($line->fresh(), 7, $this->counter));
            $this->fail('The first counter recounted their own line.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('somebody who has not counted', $e->getMessage());
        }

        $this->asCompany($this->company, fn () => $this->c()->record($line->fresh(), 7, $this->second));
        $line = $line->fresh();
        $this->assertSame(CountLine::VARIANCE, $line->status, 'Two independent counts agreed but a third was demanded.');
        $this->assertEquals(-3, (float) $line->variance_qty);
        $this->assertEquals(-60, (float) $line->variance_value);
    }

    public function test_a_small_variance_inside_tolerance_does_not_need_a_recount(): void
    {
        $count = $this->open(['recount_qty' => 2]);
        $this->asCompany($this->company, fn () => $this->c()->record($this->line($count, $this->b1), 9, $this->counter));

        $this->assertSame(CountLine::VARIANCE, $this->line($count, $this->b1)->status);
    }

    public function test_review_suggests_causes_and_posting_adjusts_through_the_approval_limits(): void
    {
        $count = $this->open(['recount_qty' => 100]);
        // Three units are in A-02 that the ledger has in A-01: the hint should say so.
        $this->asCompany($this->company, fn () => $this->c()->record($this->line($count, $this->b1), 7, $this->counter));
        $this->asCompany($this->company, fn () => $this->c()->record($this->line($count, $this->b2), 7, $this->counter));

        $this->asCompany($this->company, fn () => $this->c()->submit($count, $this->counter));
        $hints = collect($this->line($count, $this->b1)->causes)->pluck('code')->all();
        $this->assertContains('LOCATION', $hints);

        try {
            $this->asCompany($this->company, fn () => $this->c()->post($count->fresh(), $this->counter));
            $this->fail('A counter approved their own count.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cannot also approve', $e->getMessage());
        }

        $this->asCompany($this->company, fn () => $this->c()->post($count->fresh(), $this->manager));

        $this->assertEquals(7, $this->onHand($this->b1));
        $this->assertEquals(7, $this->onHand($this->b2));
        $line = $this->line($count, $this->b1);
        $this->assertSame(CountLine::ADJUSTED, $line->status);
        $adjustment = StockAdjustment::query()->findOrFail($line->adjustment_id);
        $this->assertSame('COUNT', $adjustment->reason->code);
        $this->assertSame(Count::POSTED, $count->fresh()->status);
    }

    public function test_a_variance_above_the_adjustment_limit_waits_for_approval(): void
    {
        DB::table('wms_reason_codes')->where('company_id', $this->company->id)->where('code', 'COUNT')->update(['approval_value' => 50]);
        $count = $this->open(['recount_qty' => 100, 'scope' => 'BINS', 'scope_ids' => [$this->b1->id]]);
        $this->asCompany($this->company, fn () => $this->c()->record($this->line($count, $this->b1), 6, $this->counter));
        $this->asCompany($this->company, fn () => $this->c()->submit($count, $this->counter));

        $this->asCompany($this->company, fn () => $this->c()->post($count->fresh(), $this->manager));

        $line = $this->line($count, $this->b1);
        $this->assertSame(CountLine::ACCEPTED, $line->status);
        $this->assertSame(StockAdjustment::PENDING, $line->adjustment->status);
        $this->assertEquals(10, $this->onHand($this->b1), 'A count posted a loss above the limit without approval.');
    }

    public function test_a_dismissed_variance_needs_an_explanation_and_posts_nothing(): void
    {
        $count = $this->open(['recount_qty' => 100, 'scope' => 'BINS', 'scope_ids' => [$this->b1->id]]);
        $this->asCompany($this->company, fn () => $this->c()->record($this->line($count, $this->b1), 8, $this->counter));
        $this->asCompany($this->company, fn () => $this->c()->submit($count, $this->counter));

        try {
            $this->asCompany($this->company, fn () => $this->c()->decide($this->line($count, $this->b1), 'dismiss', '', $this->manager));
            $this->fail('Dismissed without an explanation.');
        } catch (RuntimeException) {
        }
        $this->asCompany($this->company, fn () => $this->c()->decide($this->line($count, $this->b1), 'dismiss', 'Two units were on the forklift', $this->manager));
        $this->asCompany($this->company, fn () => $this->c()->post($count->fresh(), $this->manager));

        $this->assertEquals(10, $this->onHand($this->b1));
        $this->assertNull($this->line($count, $this->b1)->adjustment_id);
    }

    public function test_unexpected_stock_and_a_pack_sized_variance_are_explained(): void
    {
        $this->asCompany($this->company, fn () => ItemPackaging::create(['company_id' => $this->company->id, 'stock_item_id' => $this->item->id,
            'level' => 'CASE', 'name' => 'case', 'qty_in_base' => 6, 'is_active' => true,
            'uom_id' => DB::table('mfg_uoms')->where('company_id', $this->company->id)->value('id')]));
        $b3 = $this->asCompany($this->company, fn () => app(LocationService::class)->create($this->wh, ['code' => 'A-03']));
        $count = $this->open(['recount_qty' => 100]);

        $this->asCompany($this->company, fn () => $this->c()->record($this->line($count, $this->b1), 16, $this->counter));
        $this->asCompany($this->company, fn () => $this->c()->record($this->line($count, $this->b2), 4, $this->counter));
        $this->asCompany($this->company, fn () => $this->c()->recordUnexpected($count, $b3->id, $this->item->id, null, 2, $this->counter));
        $this->asCompany($this->company, fn () => $this->c()->submit($count, $this->counter));

        $this->assertContains('UOM', collect($this->line($count, $this->b1)->causes)->pluck('code')->all());
        $found = $this->line($count, $b3);
        $this->assertTrue($found->unexpected);
        $this->assertContains('UNEXPECTED', collect($found->causes)->pluck('code')->all());

        $this->asCompany($this->company, fn () => $this->c()->post($count->fresh(), $this->manager));
        $this->assertEquals(2, $this->onHand($b3));
    }

    public function test_a_short_pick_opens_a_count_of_that_bin_and_posting_it_releases_the_hold(): void
    {
        $this->asCompany($this->company, fn () => app(StockLedger::class)->changeStatus($this->item->fresh(), $this->wh, 3, 'AVAILABLE', 'ON_HOLD', ['bin_id' => $this->b1->id, 'reason' => 'Short pick']));

        $count = $this->asCompany($this->company, fn () => $this->c()->countOnEvent($this->b1->fresh(), $this->item, 'SHORT_PICK', 'Short pick on order SO-1'));
        $this->assertNotNull($count);
        $this->assertNull($this->asCompany($this->company, fn () => $this->c()->countOnEvent($this->b1->fresh(), $this->item, 'SHORT_PICK', 'again')), 'A second count was opened for the same bin.');

        // Only one of the three missing units is really gone.
        $this->asCompany($this->company, fn () => $this->c()->record($this->line($count, $this->b1), 9, $this->counter));
        $this->asCompany($this->company, fn () => $this->c()->record($this->line($count, $this->b1)->fresh(), 9, $this->second));
        $this->asCompany($this->company, fn () => $this->c()->submit($count, $this->counter));
        $this->asCompany($this->company, fn () => $this->c()->post($count->fresh(), $this->manager));

        $this->assertEquals(9, (float) FinStockPosition::withoutGlobalScopes()->where('bin_id', $this->b1->id)->where('status', 'AVAILABLE')->sum('qty'));
        $this->assertEquals(0, (float) FinStockPosition::withoutGlobalScopes()->where('bin_id', $this->b1->id)->where('status', 'ON_HOLD')->sum('qty'));
    }

    // ── The screens ──────────────────────────────────────────────────────────────────────────

    public function test_the_count_screens_carry_a_blind_count_from_opening_to_posting(): void
    {
        $this->actingAs($this->manager)->post(route('warehouse.counts.store'), [
            'warehouse' => $this->wh->uuid, 'scope' => 'BINS', 'scope_refs' => [$this->b1->uuid], 'blind' => true, 'recount_qty' => 100,
        ])->assertSessionHasNoErrors();
        $count = Count::query()->latest('id')->firstOrFail();
        $this->assertSame([$this->b1->id], $count->lines()->pluck('bin_id')->all());

        // The counter does not see what the books say; the manager does.
        $this->actingAs($this->counter)->get(route('warehouse.counts.show', $count->uuid))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Counts/Show')->where('reveal', false)->where('lines.0.expected', null)->where('lines.0.snapshot', null));
        $this->actingAs($this->manager)->get(route('warehouse.counts.show', $count->uuid))
            ->assertInertia(fn ($p) => $p->where('reveal', true)->where('lines.0.snapshot', 10));

        $line = $this->line($count, $this->b1);
        $this->actingAs($this->viewer)->post(route('warehouse.counts.record', $count->uuid), ['line_id' => $line->id, 'qty' => 9])->assertForbidden();
        $this->actingAs($this->counter)->post(route('warehouse.counts.record', $count->uuid), ['line_id' => $line->id, 'qty' => 9, 'client_ref' => 'x-1'])->assertSessionHasNoErrors();
        $this->actingAs($this->counter)->post(route('warehouse.counts.act', $count->uuid), ['action' => 'submit'])->assertSessionHasNoErrors();
        $this->actingAs($this->counter)->post(route('warehouse.counts.act', $count->uuid), ['action' => 'post'])->assertForbidden();
        $this->actingAs($this->manager)->post(route('warehouse.counts.act', $count->uuid), ['action' => 'post'])->assertSessionHasNoErrors();

        $this->assertSame(Count::POSTED, $count->fresh()->status);
        $this->assertEquals(9, $this->onHand($this->b1));
        $this->actingAs($this->viewer)->get(route('warehouse.counts.index'))->assertOk()->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Counts/Index'));
        $this->actingAs($this->viewer)->get(route('warehouse.counts.accuracy'))->assertOk()->assertInertia(fn ($p) => $p->where('report.summary.lines', 1));
    }

    public function test_a_plan_is_saved_from_the_screen_and_pausing_it_keeps_its_scope(): void
    {
        $this->actingAs($this->manager)->post(route('warehouse.counts.plans.store'), [
            'name' => 'Aisle A monthly', 'frequency' => 'MONTHLY', 'warehouse' => $this->wh->uuid, 'scope' => 'BINS',
            'scope_refs' => [$this->b1->uuid, $this->b2->uuid], 'blind' => true, 'freeze' => false,
        ])->assertSessionHasNoErrors();
        $plan = CountPlan::query()->where('name', 'Aisle A monthly')->firstOrFail();

        $this->actingAs($this->manager)->patch(route('warehouse.counts.plans.update', $plan->uuid), [
            'name' => 'Aisle A monthly', 'frequency' => 'MONTHLY', 'warehouse' => $this->wh->uuid, 'scope' => 'BINS', 'is_active' => false,
        ])->assertSessionHasNoErrors();

        $plan = $plan->fresh();
        $this->assertFalse($plan->is_active);
        $this->assertEqualsCanonicalizing([$this->b1->id, $this->b2->id], $plan->scope_ids);
        $this->actingAs($this->counter)->post(route('warehouse.counts.plans.store'), ['name' => 'x'])->assertForbidden();
    }

    // ── WMS-073 accuracy ─────────────────────────────────────────────────────────────────────

    public function test_accuracy_reports_first_count_accuracy_and_the_value_found_and_lost(): void
    {
        $count = $this->open(['recount_qty' => 100]);
        $this->asCompany($this->company, fn () => $this->c()->record($this->line($count, $this->b1), 8, $this->counter));
        $this->asCompany($this->company, fn () => $this->c()->record($this->line($count, $this->b2), 4, $this->second));
        $this->asCompany($this->company, fn () => $this->c()->submit($count, $this->counter));
        $this->asCompany($this->company, fn () => $this->c()->post($count->fresh(), $this->manager));

        $report = $this->asCompany($this->company, fn () => app(CountInsights::class)->accuracy($this->wh->id, now()->toDateString(), now()->toDateString()));

        $this->assertSame(2, $report['summary']['lines']);
        $this->assertEquals(50.0, $report['summary']['accuracy']);
        $this->assertEquals(40.0, $report['summary']['losses']);
        $this->assertCount(2, $report['by_counter']);
        $this->assertNotEmpty($report['by_shift']);
        $this->assertSame('A-01', collect($report['by_location'])->firstWhere('accuracy', 0.0)['label']);
    }
}
