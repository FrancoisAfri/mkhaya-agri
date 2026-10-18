<?php

namespace Tests\Feature\Manufacturing;

use App\Core\Role\Models\Role;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockMovement;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\Manufacturing\Models\AccountingIssue;
use App\Modules\Operations\Manufacturing\Models\AccountingRule;
use App\Modules\Operations\Manufacturing\Models\CostRecord;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\FactoryPolicy;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Models\WorkCentre;
use App\Modules\Operations\Manufacturing\Services\ManufacturingAccounting;
use App\Modules\Operations\Manufacturing\Services\ProductionExecution;
use App\Modules\Operations\Manufacturing\Services\ProductionOrderLifecycle;
use App\Modules\Operations\Manufacturing\Services\ProductionOrders;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;
use Illuminate\Support\Facades\DB;

/**
 * MFG-021: every event under an approved treatment; the cost subledger follows each order with its
 * source documents; WIP reconciles to the ledger; closed periods refuse; reversals leave the
 * original on record.
 */
class AccountingControlsTest extends ManufacturingTestCase
{
    private Factory $plant;

    private FinWarehouse $store;

    private FinStockItem $steel;

    private Item $frame;

    private $prod;

    private $controller;

    private $auditor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();

        $prod = Role::firstOrCreate(['name' => 'mfg test production lead', 'guard_name' => 'web']);
        $prod->syncPermissions(array_values(array_diff(RoleCatalog::all(), [RoleCatalog::VIEW_ACCOUNTING, RoleCatalog::APPROVE_ACCOUNTING, RoleCatalog::ADJUST_ACCOUNTING])));
        $fin = Role::firstOrCreate(['name' => 'mfg test financial controller', 'guard_name' => 'web']);
        $fin->syncPermissions([RoleCatalog::VIEW_DASHBOARD, RoleCatalog::VIEW_ACCOUNTING, RoleCatalog::APPROVE_ACCOUNTING, RoleCatalog::ADJUST_ACCOUNTING]);
        $this->prod = $this->makeUser('acct-prod@example.test', $prod);
        $this->controller = $this->makeUser('acct-controller@example.test', $fin);
        $this->auditor = $this->makeUser('acct-auditor@example.test', $fin);
        foreach ([$this->prod, $this->controller, $this->auditor] as $u) {
            $this->grantModuleTier($u);
        }

        $this->plant = $this->factory('PLT');
        $this->store = $this->warehouse('STORE');
        $this->asCompany($this->company, fn () => FactoryPolicy::for($this->plant->id, $this->company->id)->update([
            'require_material_for_approval' => false, 'require_qc_before_putaway' => false, 'allow_over_issue' => true, 'overhead_percent' => 10]));
        $centre = $this->asCompany($this->company, fn () => WorkCentre::create(['company_id' => $this->company->id, 'factory_id' => $this->plant->id,
            'code' => 'WLD', 'name' => 'Weld', 'cost_per_hour' => 150, 'labour_rate_per_hour' => 90, 'is_active' => true, 'concurrent_jobs' => 1]));
        $this->steel = $this->stockItem('STEEL', 'kg', 10);
        $this->frame = $this->asCompany($this->company, fn () => Item::create(['company_id' => $this->company->id,
            'stock_item_id' => $this->stockItem('FRAME', 'ea', 0)->id, 'kind' => 'FINISHED', 'default_uom_id' => $this->uom('ea')->id, 'is_active' => true]));
        $this->approvedBom($this->frame, [[$this->steel, 2, 0, 'kg']]);
        $this->routing($this->frame, [['Weld', 0, 6, ['work_centre_id' => $centre->id]]], [], true);
        $this->asCompany($this->company, fn () => app(StockLedger::class)->receive($this->steel->fresh(), 200, 10, $this->store, ['reference' => 'GRN']));
    }

    private function released(): PO
    {
        return $this->asCompany($this->company, function () {
            $o = app(ProductionOrders::class)->create(['item' => $this->frame, 'quantity' => 10, 'due_date' => now()->addDays(5)->toDateString(),
                'factory' => $this->plant, 'warehouse' => $this->store], $this->prod);
            $life = app(ProductionOrderLifecycle::class);
            $life->plan($o, $this->prod);
            $life->approve($o->fresh(), $this->prod);
            $life->release($o->fresh(), $this->prod);

            return $o->fresh();
        });
    }

    /** 22 kg issued against 20 needed, 60 minutes of labour, 10 frames received, closed. */
    private function runAndClose(PO $o): PO
    {
        return $this->asCompany($this->company, function () use ($o) {
            $exec = app(ProductionExecution::class);
            $life = app(ProductionOrderLifecycle::class);
            $exec->issue($o->fresh(), $o->components()->firstOrFail(), 22, $this->prod, ['override_reason' => 'Bent sheet']);
            $exec->completeOperation($o->fresh(), $o->operations()->firstOrFail(), ['completed' => 10, 'machine_minutes' => 60, 'labour_minutes' => 60], $this->prod);
            $exec->receiveOutput($o->fresh(), 10, $this->prod);
            $life->sendToQuality($o->fresh(), $this->prod);
            $life->complete($o->fresh(), $this->prod);
            $life->close($o->fresh(), $this->prod);

            return $o->fresh();
        });
    }

    private function records(PO $o)
    {
        return $this->asCompany($this->company, fn () => CostRecord::query()->where('production_order_id', $o->id)->get()->keyBy('event_type'));
    }

    public function test_each_event_is_in_the_subledger_with_its_source_and_wip_reconciles_to_the_ledger(): void
    {
        $o = $this->runAndClose($this->released());
        $r = $this->records($o);

        $this->assertSame(10, $this->asCompany($this->company, fn () => AccountingRule::query()->count()), 'Every event has a treatment.');
        $this->assertEquals(220.0, $r['MATERIAL_ISSUE']->amount);
        $this->assertNotNull($r['MATERIAL_ISSUE']->journal_id, 'The issue carries its journal.');
        $this->assertNotNull($r['MATERIAL_ISSUE']->stock_movement_id);
        $this->assertEquals(200.0, $r['OUTPUT_RECEIPT']->amount, '10 frames at the standard material cost of 20');
        // T-007: 2 kg more than standard, and the 20 it cost goes to Production Variance on close.
        $this->assertEquals(20.0, $r['ORDER_CLOSE']->amount);
        $this->assertSame((int) $o->close_journal_id, (int) $r['ORDER_CLOSE']->journal_id);
        $variance = DB::table('fin_journal_lines')->join('fin_accounts', 'fin_accounts.id', '=', 'fin_journal_lines.account_id')
            ->where('fin_journal_lines.journal_id', $o->close_journal_id)->where('fin_accounts.code', '5600')->sum('debit');
        $this->assertEquals(20.0, (float) $variance);
        // Labour and overhead are calculated and kept as memo -- not posted.
        $this->assertEquals(90.0, $r['LABOUR_ABSORPTION']->amount, '60 minutes at 90 an hour');
        $this->assertEquals(24.0, $r['OVERHEAD_ABSORPTION']->amount, '10% of 90 labour and 150 machine');
        $this->assertSame('MEMO', $r['LABOUR_ABSORPTION']->treatment);
        $this->assertNull($r['LABOUR_ABSORPTION']->journal_id);

        $report = $this->asCompany($this->company, fn () => app(ManufacturingAccounting::class)->reconcile($this->company->id));
        $this->assertEquals(0.0, $report['wip']['difference']);
        $this->assertSame(0, $report['open_exceptions']);

        // The subledger is a record: it cannot be edited.
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('mfg_cost_records')->where('id', $r['MATERIAL_ISSUE']->id)->update(['amount' => 1]);
    }

    public function test_an_event_waits_for_an_approved_treatment_and_the_changer_cannot_approve_it(): void
    {
        $o = $this->released();
        $rule = $this->asCompany($this->company, fn () => app(ManufacturingAccounting::class)->rule($this->company->id, 'MATERIAL_ISSUE'));

        $this->actingAs($this->controller)->post(route('manufacturing.accounting.rules.act', [$rule->id, 'suspend']), ['reason' => 'Review of WIP mapping'])->assertSessionHas('success');
        try {
            $this->asCompany($this->company, fn () => app(ProductionExecution::class)->issue($o->fresh(), $o->components()->firstOrFail(), 5, $this->prod));
            $this->fail('Material was issued under a suspended treatment.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('suspended', $e->getMessage());
        }

        $this->actingAs($this->controller)->post(route('manufacturing.accounting.rules.act', [$rule->id, 'update']), [
            'treatment' => 'POST', 'debit_account_code' => '1310', 'credit_account_code' => 'INVENTORY'])->assertSessionHas('success');
        $this->assertSame('DRAFT', $rule->fresh()->status);
        $this->actingAs($this->controller)->post(route('manufacturing.accounting.rules.act', [$rule->id, 'approve']))->assertSessionHas('error');
        $this->actingAs($this->prod)->post(route('manufacturing.accounting.rules.act', [$rule->id, 'approve']))->assertForbidden();
        $this->actingAs($this->auditor)->post(route('manufacturing.accounting.rules.act', [$rule->id, 'approve']))->assertSessionHas('success');

        $this->asCompany($this->company, fn () => app(ProductionExecution::class)->issue($o->fresh(), $o->components()->firstOrFail(), 5, $this->prod));
        $this->assertEquals(5.0, (float) $o->components()->first()->issued_qty);
        $this->assertTrue(DB::table('activity_log')->where('description', 'manufacturing.ACCOUNTING_RULE_APPROVED')->where('causer_id', $this->auditor->id)->exists());
    }

    public function test_absorption_posts_only_after_finance_approves_its_accounts(): void
    {
        $this->asCompany($this->company, function () {
            $acc = app(ManufacturingAccounting::class);
            $rule = $acc->updateRule($acc->rule($this->company->id, 'LABOUR_ABSORPTION'),
                ['treatment' => 'POST', 'debit_account_code' => '1310', 'credit_account_code' => '5600'], $this->controller);
            $acc->approveRule($rule, $this->auditor);
        });
        $o = $this->runAndClose($this->released());
        $labour = $this->records($o)['LABOUR_ABSORPTION'];
        $this->assertSame('POST', $labour->treatment);
        $this->assertNotNull($labour->journal_id);
        $this->assertEquals(90.0, (float) DB::table('fin_journal_lines')->where('journal_id', $labour->journal_id)->sum('debit'));
    }

    public function test_a_closed_period_refuses_posting_and_queues_an_exception(): void
    {
        $o = $this->released();
        FinFiscalPeriod::create(['company_id' => $this->company->id, 'fiscal_year' => (int) now()->year, 'period_no' => (int) now()->month,
            'start_date' => now()->startOfMonth(), 'end_date' => now()->endOfMonth(), 'status' => 'soft_close']);

        try {
            $this->asCompany($this->company, fn () => app(ProductionExecution::class)->issue($o->fresh(), $o->components()->firstOrFail(), 5, $this->prod));
            $this->fail('Stock was issued into a closed period.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('period-correction', $e->getMessage());
        }
        $this->assertSame(0, FinStockMovement::withoutGlobalScopes()->where('source_id', $o->id)->where('source_type', PO::SOURCE_TYPE)->count());
        $issue = $this->asCompany($this->company, fn () => AccountingIssue::query()->where('kind', 'PERIOD_CLOSED')->firstOrFail());
        $this->assertSame($o->id, (int) $issue->production_order_id);
    }

    public function test_an_authorised_reversal_keeps_the_original_and_balances_still_reconcile(): void
    {
        $o = $this->released();
        $movement = $this->asCompany($this->company, fn () => app(ProductionExecution::class)->issue($o->fresh(), $o->components()->firstOrFail(), 7, $this->prod)[0]);

        $this->actingAs($this->prod)->post(route('manufacturing.accounting.movements.reverse', $movement->uuid), ['reason' => 'Wrong order'])->assertForbidden();
        $this->actingAs($this->controller)->post(route('manufacturing.accounting.movements.reverse', $movement->uuid), ['reason' => 'Issued to the wrong order'])->assertSessionHas('success');

        $this->assertNotNull(FinStockMovement::withoutGlobalScopes()->find($movement->id), 'The original stays.');
        $reversal = FinStockMovement::withoutGlobalScopes()->where('reversal_of_id', $movement->id)->firstOrFail();
        $this->assertEquals(7.0, (float) $reversal->qty);
        $this->assertEquals(0.0, (float) $o->components()->first()->issued_qty);
        $this->assertEquals(200.0, (float) $this->steel->fresh()->qty_on_hand);
        $rev = $this->asCompany($this->company, fn () => CostRecord::query()->where('event_type', 'STOCK_REVERSAL')->firstOrFail());
        $this->assertNotNull($rev->reversal_of_id);
        $this->assertNotNull($rev->journal_id);

        $this->actingAs($this->controller)->post(route('manufacturing.accounting.movements.reverse', $movement->uuid), ['reason' => 'Again'])->assertSessionHas('error');
        $report = $this->asCompany($this->company, fn () => app(ManufacturingAccounting::class)->reconcile($this->company->id));
        $this->assertEquals(0.0, $report['wip']['difference']);
    }

    public function test_reconciliation_queues_what_does_not_agree_and_clears_it_when_it_does(): void
    {
        $o = $this->released();
        $this->asCompany($this->company, fn () => app(ProductionExecution::class)->issue($o->fresh(), $o->components()->firstOrFail(), 4, $this->prod));
        // A movement posted straight through the stock ledger, past manufacturing.
        $stray = $this->asCompany($this->company, fn () => app(StockLedger::class)->issueToProduction($this->steel->fresh(), 3, $this->store,
            ['source_type' => PO::SOURCE_TYPE, 'source_id' => $o->id, 'reference' => $o->number]));

        $this->actingAs($this->controller)->get(route('manufacturing.accounting.reconciliation'))->assertOk();
        $gap = $this->asCompany($this->company, fn () => AccountingIssue::query()->where('kind', 'SUBLEDGER_GAP')->where('status', 'OPEN')->firstOrFail());
        $this->assertSame($o->id, (int) $gap->production_order_id);

        $this->asCompany($this->company, fn () => app(ManufacturingAccounting::class)->recordMovement($stray, $o, $this->controller));
        $this->asCompany($this->company, fn () => app(ManufacturingAccounting::class)->reconcile($this->company->id));
        $this->assertSame('RESOLVED', $gap->fresh()->status);

        $page = $this->actingAs($this->controller)->get(route('manufacturing.accounting.transactions', ['order' => $o->number]))->assertOk()->viewData('page');
        $this->assertCount(2, $page['props']['records']);
        $this->assertNotNull($page['props']['records'][0]['journal']);
        $this->actingAs($this->controller)->get(route('manufacturing.accounting.rules'))->assertOk();
        $this->actingAs($this->prod)->get(route('manufacturing.accounting.reconciliation'))->assertForbidden();
        $this->artisan('mfg:reconcile-accounts', ['--company' => $this->company->id])->assertSuccessful();
    }
}
