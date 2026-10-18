<?php

namespace Tests\Feature\Warehouse;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\Manufacturing\Models\LossFinding as Finding;
use App\Modules\Operations\Manufacturing\Models\LossRule;
use App\Modules\Operations\Manufacturing\Services\LossInvestigation;
use App\Modules\Operations\WarehouseManagement\Models\ReasonCode;
use App\Modules\Operations\WarehouseManagement\Services\FulfilmentOrders;
use App\Modules\Operations\WarehouseManagement\Services\ItemCatalogue;
use App\Modules\Operations\WarehouseManagement\Services\LocationService;
use App\Modules\Operations\WarehouseManagement\Services\ProtectionCases;
use App\Modules\Operations\WarehouseManagement\Services\RiskDetector;
use App\Modules\Operations\WarehouseManagement\Services\StockAdjustments;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use Illuminate\Support\Facades\DB;

/** WMS-110..114: detectors, explainable findings, the investigation workflow and honest measures. */
class ProtectionTest extends WarehouseTestCase
{
    private FinWarehouse $wh;

    private FinStockItem $item;

    private FinStockBin $bin;

    private User $investigator;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();
        $this->wh = $this->warehouse('PPE', ['opening_time' => '08:00:00', 'closing_time' => '17:00:00', 'timezone' => 'Africa/Johannesburg']);
        $this->asCompany($this->company, function () {
            app(ItemCatalogue::class)->ensureUnits($this->company->id);
            app(StockAdjustments::class)->ensureReasonCodes($this->company->id);
            $this->bin = app(LocationService::class)->create($this->wh, ['code' => 'B-7']);
            $this->item = app(ItemCatalogue::class)->create(['company_id' => $this->company->id, 'code' => 'PHONE', 'name' => 'Phone', 'uom' => 'ea', 'standard_cost' => 500]);
            app(StockLedger::class)->receive($this->item->fresh(), 50, 500, $this->wh, ['bin_id' => $this->bin->id]);
        });
        $this->investigator = $this->person('investigator', [RoleCatalog::VIEW, RoleCatalog::INVESTIGATE]);
        $this->approver = $this->person('approver', [RoleCatalog::VIEW, RoleCatalog::INVESTIGATE, RoleCatalog::APPROVE_LOSS]);
        $this->manager->givePermissionTo(RoleCatalog::all());
        $this->actingAs($this->manager);
    }

    private function in(callable $fn): mixed
    {
        return $this->asCompany($this->company, $fn);
    }

    private function adjust(float $qty, string $code = 'DAMAGED'): \App\Modules\Operations\WarehouseManagement\Models\StockAdjustment
    {
        $reason = $this->in(fn () => ReasonCode::query()->where('code', $code)->firstOrFail());

        return $this->in(fn () => app(StockAdjustments::class)->request(['warehouse_id' => $this->wh->id, 'item_id' => $this->item->id, 'bin_id' => $this->bin->id,
            'qty' => $qty, 'reason_code_id' => $reason->id], $this->manager));
    }

    private function scan(?string $only = null): array
    {
        return $this->in(fn () => app(RiskDetector::class)->scan($this->company->id, $only));
    }

    public function test_repeated_adjustments_raise_one_explained_finding_with_the_rule_it_was_judged_by(): void
    {
        foreach ([-1, -1, -2] as $q) {
            $this->adjust($q);
        }
        $this->assertSame(1, $this->scan('WH_REPEATED_ADJUSTMENTS')['WH_REPEATED_ADJUSTMENTS']);
        $this->assertSame(0, $this->scan('WH_REPEATED_ADJUSTMENTS')['WH_REPEATED_ADJUSTMENTS'], 'The same lead was raised twice.');

        $f = $this->in(fn () => Finding::query()->where('category', 'WH_REPEATED_ADJUSTMENTS')->firstOrFail());
        $this->assertSame(3.0, $f->actual_value);
        $this->assertEquals(2000, (float) $f->estimated_impact);
        $this->assertSame('STOCK_ITEM', $f->subject_type, 'A finding named a person, not the stock.');
        $this->assertEquals(3, $f->rule_snapshot['threshold']);
        $this->assertStringContainsString('ADJ-', $f->evidence['adjustments']);

        // Changing the rule later does not rewrite what the finding was judged against.
        $this->in(fn () => LossRule::query()->where('category', 'WH_REPEATED_ADJUSTMENTS')->update(['threshold' => 10]));
        $this->assertEquals(3, $f->fresh()->rule_snapshot['threshold']);
    }

    public function test_an_unusually_large_adjustment_is_judged_against_the_typical_one_with_its_confidence(): void
    {
        foreach (range(1, 4) as $_) {
            $this->adjust(-1);
        }
        $this->in(fn () => DB::table('fin_stock_items')->where('id', $this->item->id)->update(['avg_cost' => 500]));
        $big = $this->adjust(-20, 'CORRECTION');
        $this->scan('WH_HIGH_VALUE_ADJUSTMENT');

        $f = $this->in(fn () => Finding::query()->where('category', 'WH_HIGH_VALUE_ADJUSTMENT')->firstOrFail());
        $this->assertSame($big->id, $f->subject_id);
        $this->assertEquals(500, $f->baseline_value);
        $this->assertSame('LOW', $f->confidence, 'Five adjustments of history were presented as a firm baseline.');
    }

    public function test_short_picks_dispatch_gaps_and_out_of_hours_corrections_are_found(): void
    {
        foreach (range(1, 3) as $_) {
            $this->in(fn () => app(StockLedger::class)->changeStatus($this->item->fresh(), $this->wh, 1, 'AVAILABLE', 'ON_HOLD', ['bin_id' => $this->bin->id, 'reason' => 'Short pick', 'reason_code' => 'SHORT_PICK']));
        }
        $order = $this->in(function () {
            [$o] = app(FulfilmentOrders::class)->create($this->wh, 'ECOMMERCE', 'MIS-1', [['item_id' => $this->item->id, 'qty' => 2]], [], $this->manager);
            $o->lines()->update(['qty_allocated' => 2, 'qty_picked' => 2, 'qty_packed' => 2, 'qty_dispatched' => 2]);

            return $o;
        });
        foreach (range(1, 3) as $_) {
            $a = $this->adjust(-1);
            // 01:00 in Johannesburg, well outside 08:00-17:00.
            DB::table('wms_stock_adjustments')->where('id', $a->id)->update(['created_at' => now()->setTimezone('Africa/Johannesburg')->setTime(1, 0)->utc()]);
        }

        $raised = $this->scan();
        $this->assertSame(1, $raised['WH_REPEATED_SHORT_PICKS']);
        $this->assertSame(1, $raised['WH_DISPATCH_MISMATCH']);
        $this->assertSame(1, $raised['WH_OUT_OF_HOURS']);
        $mismatch = $this->in(fn () => Finding::query()->where('category', 'WH_DISPATCH_MISMATCH')->firstOrFail());
        $this->assertSame('HIGH', $mismatch->confidence);
        $this->assertStringContainsString($order->number, $mismatch->subject_label);
        $this->assertSame('WAREHOUSE', $this->in(fn () => Finding::query()->where('category', 'WH_OUT_OF_HOURS')->value('subject_type')));
    }

    public function test_a_case_is_investigated_verified_corrected_and_approved_by_somebody_else(): void
    {
        $case = $this->in(fn () => app(ProtectionCases::class)->open(['title' => 'Missing pallet', 'description' => 'Driver reported a pallet short', 'priority' => 'HIGH',
            'warehouse_id' => $this->wh->id, 'estimated_impact' => 5000], $this->investigator));
        $inv = app(LossInvestigation::class);
        $this->in(fn () => $inv->investigate($case, $this->investigator));
        $this->in(fn () => $inv->verify($case->fresh(), 4500, 'CCTV and the count of 12 October', $this->investigator));
        $this->in(fn () => $inv->plan($case->fresh(), 'Seal pallets at loading', null, $this->investigator));
        $this->in(fn () => $inv->result($case->fresh(), 'No shortages since', 1200, 'Shrinkage 3 months before and after', $this->investigator));

        try {
            $this->in(fn () => $inv->approve($case->fresh(), $this->investigator));
            $this->fail('The investigator approved their own saving.');
        } catch (\DomainException) {
        }
        $this->in(fn () => $inv->approve($case->fresh(), $this->approver, 'Agreed'));
        $this->in(fn () => $inv->close($case->fresh(), $this->approver));

        $m = $this->in(fn () => app(ProtectionCases::class)->measures(now()->subDay()->toDateString(), now()->toDateString()));
        $this->assertEquals(4500, $m['impact']['verified_by_investigation']);
        $this->assertEquals(1200, $m['impact']['avoided_cost']);
        $this->assertSame(1, $m['effectiveness']['closed']);
        $this->assertSame(1, $m['effectiveness']['with_corrective_action']);
    }

    public function test_investigations_are_visible_only_to_investigators(): void
    {
        $this->adjust(-1);
        $case = $this->in(fn () => app(ProtectionCases::class)->open(['title' => 'Check', 'description' => 'x'], $this->investigator));

        $this->actingAs($this->viewer)->get(route('warehouse.protection.index'))->assertForbidden();
        $this->actingAs($this->viewer)->get(route('warehouse.protection.show', $case->uuid))->assertForbidden();
        $this->actingAs($this->investigator)->get(route('warehouse.protection.index'))->assertOk()->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Protection/Index'));
        $this->actingAs($this->investigator)->get(route('warehouse.protection.show', $case->uuid))->assertOk()->assertInertia(fn ($p) => $p->where('finding.number', $case->number));
        $this->actingAs($this->investigator)->post(route('warehouse.protection.act', $case->uuid), ['action' => 'dismiss', 'notes' => ''])->assertSessionHasErrors('refused');
        $this->actingAs($this->investigator)->post(route('warehouse.protection.act', $case->uuid), ['action' => 'dismiss', 'notes' => 'Stock found in B-8'])->assertSessionHasNoErrors();
        $this->actingAs($this->investigator)->post(route('warehouse.protection.scan'))->assertForbidden();
        $this->actingAs($this->manager)->get(route('warehouse.protection.rules'))->assertOk();
    }
}
