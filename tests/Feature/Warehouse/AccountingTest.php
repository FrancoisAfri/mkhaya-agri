<?php

namespace Tests\Feature\Warehouse;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\WarehouseManagement\Models\AccountingIssue;
use App\Modules\Operations\WarehouseManagement\Models\AccountingRule;
use App\Modules\Operations\WarehouseManagement\Models\ReasonCode;
use App\Modules\Operations\WarehouseManagement\Services\CostReports;
use App\Modules\Operations\WarehouseManagement\Services\ItemCatalogue;
use App\Modules\Operations\WarehouseManagement\Services\LocationService;
use App\Modules\Operations\WarehouseManagement\Services\StockAdjustments;
use App\Modules\Operations\WarehouseManagement\Services\Valuation;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseAccounting;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** WMS-100..103: treatments, posting integrity, reconciliation, landed cost, revaluation, cost reports. */
class AccountingTest extends WarehouseTestCase
{
    private FinWarehouse $wh;

    private FinStockItem $item;

    private FinStockBin $bin;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();
        $this->wh = $this->warehouse('ACC', ['policies' => ['scan_to_confirm' => false, 'carrying_cost_pct' => 20]]);
        $this->asCompany($this->company, function () {
            app(ItemCatalogue::class)->ensureUnits($this->company->id);
            app(StockAdjustments::class)->ensureReasonCodes($this->company->id);
            $this->bin = app(LocationService::class)->create($this->wh, ['code' => 'A1']);
            $this->item = app(ItemCatalogue::class)->create(['company_id' => $this->company->id, 'code' => 'DRILL', 'name' => 'Drill', 'uom' => 'ea', 'standard_cost' => 100]);
            app(StockLedger::class)->receive($this->item->fresh(), 10, 100, $this->wh, ['bin_id' => $this->bin->id]);
            app(WarehouseAccounting::class)->install($this->company->id);
        });
        $this->finance = $this->person('finance', [RoleCatalog::VIEW, RoleCatalog::ACCOUNTING, RoleCatalog::APPROVE_ACCOUNTING]);
        $this->manager->givePermissionTo(RoleCatalog::all());
        $this->actingAs($this->manager);
    }

    private function in(callable $fn): mixed
    {
        return $this->asCompany($this->company, $fn);
    }

    private function accountOf(int $journalId, string $side): array
    {
        return DB::table('fin_journal_lines as l')->join('fin_accounts as a', 'a.id', '=', 'l.account_id')->where('l.journal_id', $journalId)
            ->where("l.{$side}", '>', 0)->pluck('a.code')->all();
    }

    public function test_an_adjustment_posts_to_the_mapped_account_and_a_suspended_treatment_stops_it(): void
    {
        $reason = $this->in(fn () => ReasonCode::query()->where('code', 'DAMAGED')->firstOrFail());
        $adj = $this->in(fn () => app(StockAdjustments::class)->request(['warehouse_id' => $this->wh->id, 'item_id' => $this->item->id, 'bin_id' => $this->bin->id,
            'qty' => -1, 'reason_code_id' => $reason->id], $this->manager));
        $journal = DB::table('fin_stock_movements')->where('id', $adj->movement_id)->value('journal_id');
        $this->assertSame(['5620'], $this->accountOf($journal, 'debit'), 'A write-off did not go to Inventory Adjustments.');

        $rule = $this->in(fn () => AccountingRule::query()->where('event', 'ADJUSTMENT')->firstOrFail());
        $this->in(fn () => app(WarehouseAccounting::class)->suspend($rule, 'Year-end stocktake under way'));
        $this->expectExceptionMessage('suspended');
        $this->in(fn () => app(StockAdjustments::class)->request(['warehouse_id' => $this->wh->id, 'item_id' => $this->item->id, 'bin_id' => $this->bin->id,
            'qty' => -1, 'reason_code_id' => $reason->id], $this->manager));
    }

    public function test_a_landed_cost_waits_for_approval_then_splits_between_stock_and_what_has_gone(): void
    {
        $this->in(fn () => app(StockLedger::class)->issueToProduction($this->item->fresh(), 4, $this->wh, ['bin_id' => $this->bin->id, 'source_type' => 'TEST', 'source_id' => 1]));
        $lc = $this->in(fn () => app(Valuation::class)->draftLandedCost(['lines' => [['item_id' => $this->item->id, 'qty' => 10, 'value' => 1000]],
            'components' => [['kind' => 'FREIGHT', 'amount' => 80], ['kind' => 'DUTY', 'amount' => 20]], 'invoice_reference' => 'FWD-881'], $this->manager));

        try {
            $this->in(fn () => app(Valuation::class)->postLandedCost($lc, $this->finance));
            $this->fail('A landed cost posted before finance approved its treatment.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('approved', $e->getMessage());
        }
        $this->in(fn () => app(WarehouseAccounting::class)->approve(AccountingRule::query()->where('event', 'LANDED_COST')->firstOrFail(), $this->finance));
        $posted = $this->in(fn () => app(Valuation::class)->postLandedCost($lc->fresh(), $this->finance));

        $this->assertEquals(60, (float) $posted->to_inventory, 'Six of ten are still here: 60 of the 100 belongs to stock.');
        $this->assertEquals(40, (float) $posted->to_cogs);
        $this->assertEquals(110, (float) $this->item->fresh()->avg_cost);
        $this->assertSame(['2160'], $this->accountOf($posted->journal_id, 'credit'));
        $this->assertSame(1, DB::table('fin_journals')->where('idempotency_key', "wms_landed_cost:{$lc->id}")->count());
        $this->expectExceptionMessage('posted');
        $this->in(fn () => app(Valuation::class)->postLandedCost($posted->fresh(), $this->finance));
    }

    public function test_a_revaluation_needs_a_second_person_and_keeps_stock_and_books_in_step(): void
    {
        $this->in(fn () => app(WarehouseAccounting::class)->approve(AccountingRule::query()->where('event', 'REVALUATION')->firstOrFail(), $this->finance));
        $r = $this->in(fn () => app(Valuation::class)->requestRevaluation($this->item->fresh(), 90, 'Supplier price list reduced', $this->manager));
        $this->assertEquals(-100, (float) $r->delta);

        try {
            $this->in(fn () => app(Valuation::class)->decideRevaluation($r, true, null, $this->manager));
            $this->fail('The requester approved their own revaluation.');
        } catch (RuntimeException) {
        }
        $done = $this->in(fn () => app(Valuation::class)->decideRevaluation($r->fresh(), true, 'Agreed', $this->finance));
        $this->assertSame('POSTED', $done->status);
        $this->assertEquals(90, (float) $this->item->fresh()->avg_cost);
        $this->assertSame(['5630'], $this->accountOf($done->journal_id, 'debit'));

        $result = $this->in(fn () => app(WarehouseAccounting::class)->reconcile($this->company->id));
        $row = collect($result['valuation'])->firstWhere('account', DB::table('fin_accounts')->where('id', $this->item->inventory_account_id)->value('code'));
        $this->assertEquals(900, $row['stock']);
        $this->assertEquals(0, $row['difference'], 'The revaluation left the books and the stock apart.');
    }

    public function test_reconciliation_finds_a_corrupted_balance_and_an_unposted_movement_and_clears_when_fixed(): void
    {
        // QA-027: the balance is a projection; damage it and the ledger must say so.
        DB::table('fin_stock_balances')->where('item_id', $this->item->id)->update(['qty' => 13]);
        $movement = DB::table('fin_stock_movements')->where('item_id', $this->item->id)->first();
        DB::table('fin_stock_movements')->where('id', $movement->id)->update(['journal_id' => null]);

        $this->in(fn () => app(WarehouseAccounting::class)->reconcile($this->company->id));
        $kinds = $this->in(fn () => AccountingIssue::query()->where('status', 'OPEN')->pluck('kind')->all());
        $this->assertContains('BALANCE_NOT_LEDGER', $kinds);
        $this->assertContains('POSITIONS_NOT_BALANCE', $kinds);
        $this->assertContains('MOVEMENT_NOT_POSTED', $kinds);

        // Running again does not duplicate; putting it right clears it.
        $this->in(fn () => app(WarehouseAccounting::class)->reconcile($this->company->id));
        $this->assertSame(count($kinds), $this->in(fn () => AccountingIssue::query()->where('status', 'OPEN')->count()));
        DB::table('fin_stock_balances')->where('item_id', $this->item->id)->update(['qty' => 10]);
        $this->in(fn () => app(WarehouseAccounting::class)->reconcile($this->company->id));
        $this->assertNotContains('BALANCE_NOT_LEDGER', $this->in(fn () => AccountingIssue::query()->where('status', 'OPEN')->pluck('kind')->all()));
    }

    public function test_the_cost_report_shows_value_carrying_cost_and_slow_stock(): void
    {
        $r = $this->in(fn () => app(CostReports::class)->report($this->wh->id, now()->startOfMonth()->toDateString(), now()->toDateString(), 30));

        $this->assertEquals(1000, $r['total_value']);
        $this->assertNotNull($r['carrying_cost']);
        $this->assertSame('DRILL', $r['slow_moving']['items'][0]['item'], 'Stock never issued is not shown as slow.');
    }

    public function test_the_accounting_screens_are_for_accounting_people(): void
    {
        $this->actingAs($this->viewer)->get(route('warehouse.accounting.index'))->assertForbidden();
        $this->actingAs($this->finance)->get(route('warehouse.accounting.index'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Accounting/Index')->has('rules', count(WarehouseAccounting::EVENTS)));
        $this->actingAs($this->finance)->get(route('warehouse.accounting.costs'))->assertOk();
        $this->actingAs($this->finance)->post(route('warehouse.accounting.reconcile'))->assertSessionHasNoErrors();
        $this->actingAs($this->viewer)->post(route('warehouse.accounting.revaluations.store'), ['item' => $this->item->uuid, 'new_cost' => 1, 'reason' => 'x'])->assertForbidden();
    }
}
