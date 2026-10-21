<?php

namespace Tests\Feature\Agri;

use App\Modules\Agri\Models\ActivityInput;
use App\Modules\Agri\Models\AgriExportedJournal;
use App\Modules\Agri\Models\AgriFinanceExport;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\FarmBudgetLine;
use App\Modules\Agri\Models\FarmBudgetRevision;
use App\Modules\Agri\Models\FarmTransaction;
use App\Modules\Agri\Models\FieldActivity;
use App\Modules\Agri\Services\FarmBudgets;
use App\Modules\Agri\Services\FarmCashFlow;
use App\Modules\Agri\Services\FinanceIntegration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 §6: budgets with owners, approval and revisions; the cash-flow forecast with its
 * assumptions and scenarios; and the section's acceptance test — the farm's reports reconcile with
 * the ledger, every cost is traceable to its source, and a correction keeps the audit trail.
 */
class FinanceIntelligenceTest extends AgriTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->catalogue();
        $this->openBooks();
    }

    private function plan($farm, $field, array $o = []): CropPlan
    {
        $this->actingAs($this->manager)->post(route('agri.crops.plans.store'), $o + [
            'farm_id' => $farm->uuid, 'field_id' => $field->uuid, 'season_id' => $this->seasonId(), 'crop_type_id' => $this->cropTypeId(),
            'code' => 'MZ-1', 'planned_area' => 30, 'area_unit' => 'ha', 'planned_planting_on' => today()->toDateString(),
            'planned_harvest_on' => today()->addDays(70)->toDateString(), 'expected_yield' => 8, 'yield_unit' => 't', 'budget_amount' => 50000,
        ])->assertSessionHasNoErrors();
        $plan = CropPlan::withoutGlobalScopes()->where('code', $o['code'] ?? 'MZ-1')->firstOrFail();
        $this->actingAs($this->admin)->post(route('agri.crops.plans.transition', $plan->uuid), ['action' => 'approve'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.crops.plans.transition', $plan->uuid), ['action' => 'plant'])->assertSessionHasNoErrors();

        return $plan->fresh();
    }

    public function test_6_1_a_budget_counts_only_once_someone_else_approves_it_and_a_revision_keeps_its_history(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $start = today()->startOfMonth()->toDateString();
        $end = today()->endOfMonth()->toDateString();
        $this->actingAs($this->manager)->post(route('agri.finance.budgets.store'), ['farm_id' => $farm->uuid, 'category' => 'repairs', 'description' => 'Tractor upkeep',
            'amount' => 10000, 'period_start' => $start, 'period_end' => $end])->assertSessionHasNoErrors();
        $line = FarmBudgetLine::withoutGlobalScopes()->sole();
        $this->assertSame('draft', $line->status);
        $this->assertSame($this->manager->id, (int) $line->owner_id);

        // A repair is spent against it.
        $this->actingAs($this->admin)->post(route('agri.finance.transactions.store'), ['farm_id' => $farm->uuid, 'kind' => 'expense', 'category' => 'repairs',
            'transacted_on' => today()->toDateString(), 'description' => 'Hydraulic hose', 'amount' => 2500, 'settled_by' => 'payable'])->assertSessionHasNoErrors();

        // Submitted by its owner, who cannot approve it; someone else does.
        $this->actingAs($this->manager)->post(route('agri.finance.budgets.submit', $line->uuid))->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.finance.budgets.decide', $line->uuid), ['approve' => true])->assertSessionHasErrors('status');
        $this->actingAs($this->worker)->post(route('agri.finance.budgets.decide', $line->uuid), ['approve' => true])->assertNotFound();   // not on the farm: the line is not there for them
        $this->actingAs($this->admin)->post(route('agri.finance.budgets.decide', $line->uuid), ['approve' => true])->assertSessionHasNoErrors();
        $this->assertSame('approved', $line->fresh()->status);
        $this->assertSame($this->admin->id, (int) $line->fresh()->approved_by);

        // An approved line is not deleted; it is revised, and the approved amount stands until the revision is approved.
        $this->actingAs($this->manager)->delete(route('agri.finance.budgets.destroy', $line->uuid))->assertStatus(422);
        $this->actingAs($this->manager)->post(route('agri.finance.budgets.revise', $line->uuid), ['amount' => 14000, 'reason' => 'Gearbox rebuild'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.finance.budgets.revise', $line->uuid), ['amount' => 15000, 'reason' => 'Again'])->assertSessionHasErrors('amount');
        $this->assertEqualsWithDelta(10000.0, (float) $line->fresh()->amount, 0.01);
        $rev = FarmBudgetRevision::withoutGlobalScopes()->sole();
        $this->actingAs($this->manager)->post(route('agri.finance.revisions.decide', $rev->uuid), ['approve' => true])->assertSessionHasErrors('status');
        $this->actingAs($this->admin)->post(route('agri.finance.revisions.decide', $rev->uuid), ['approve' => true])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(14000.0, (float) $line->fresh()->amount, 0.01);
        $this->assertSame(2, (int) $line->fresh()->version);

        $cmp = $this->asCompany($this->company, fn () => app(FarmBudgets::class)->comparison($farm, $start, $end))[0];
        $this->assertEqualsWithDelta(14000.0, $cmp['budget'], 0.01);
        $this->assertEqualsWithDelta(2500.0, $cmp['actual'], 0.01, 'Actual is read from the posted journals.');
        $this->assertNotEmpty($cmp['basis']);
        $this->assertSame('approved', $cmp['history'][0]['status']);

        $this->actingAs($this->manager)->get(route('agri.finance.intelligence', ['tab' => 'budgets', 'farm' => $farm->uuid]))->assertOk();
    }

    public function test_6_4_cash_flow_shows_loans_capital_and_labour_and_a_scenario_moves_the_figures_not_the_records(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $this->actingAs($this->manager)->post(route('agri.finance.loans.store'), ['lender' => 'Land Bank', 'principal' => 500000, 'payment' => 12000,
            'frequency' => 'monthly', 'instalments' => 3, 'first_payment_on' => today()->addWeeks(2)->toDateString()])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.finance.capex.store'), ['description' => 'Centre pivot', 'amount' => 800000,
            'planned_on' => today()->addWeeks(5)->toDateString(), 'status' => 'committed'])->assertSessionHasNoErrors();
        $field = $this->field($farm, 'L1', 30);
        $plan = $this->plan($farm, $field);
        $plan->update(['expected_price' => 4000]);

        $run = fn (array $s = []) => $this->asCompany($this->company, fn () => app(FarmCashFlow::class)->forecast($this->company->id, 26, $s));
        $base = $run();
        $sum = fn ($f, string $dir, string $src) => collect($f['weeks'])->sum(fn ($w) => $w[$dir][$src] ?? 0);
        $this->assertEqualsWithDelta(36000.0, $sum($base, 'out', 'loans'), 0.01);
        $this->assertEqualsWithDelta(800000.0, $sum($base, 'out', 'capital'), 0.01);
        $this->assertEqualsWithDelta(960000.0, $sum($base, 'in', 'harvest'), 0.01, '8 t/ha × 30 ha × R4 000');
        $this->assertNotNull($base['first_shortage'], 'The pivot before the harvest is paid runs the bank short.');
        $this->assertNotEmpty($base['assumptions']);

        // A 20% lower price and a four-week delay: less in, later.
        $worse = $run(['price_change_pct' => -20, 'harvest_delay_weeks' => 4]);
        $this->assertEqualsWithDelta(768000.0, $sum($worse, 'in', 'harvest'), 0.01);
        $week = fn ($f) => collect($f['weeks'])->search(fn ($w) => ($w['in']['harvest'] ?? 0) > 0);
        $this->assertSame($week($base) + 4, $week($worse));
        $this->assertEquals(4000, (float) $plan->fresh()->expected_price, 'A scenario changes nothing recorded.');

        $this->actingAs($this->manager)->get(route('agri.finance.intelligence', ['tab' => 'cashflow', 's' => ['price_change_pct' => -10]]))->assertOk();
        $this->actingAs($this->manager)->get(route('agri.finance.intelligence', ['tab' => 'costs', 'farm' => $farm->uuid]))->assertOk();
    }

    public function test_acceptance_reports_reconcile_with_the_ledger_costs_trace_to_their_source_and_journals_export_once(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $field = $this->field($farm, 'L1', 30);
        $fert = $this->stockItem('LAN', 'kg', 10);
        $this->stockOnFarm($farm, $fert, 2000, 10);
        $plan = $this->plan($farm, $field);

        $this->actingAs($this->manager)->post(route('agri.crops.activities.store'), ['farm_id' => $farm->uuid, 'crop_plan_id' => $plan->uuid,
            'activity_type' => 'fertilising', 'title' => 'Top dress', 'planned_on' => today()->toDateString()])->assertSessionHasNoErrors();
        $activity = FieldActivity::withoutGlobalScopes()->sole();
        $this->actingAs($this->manager)->post(route('agri.crops.inputs.store', $activity->uuid), ['stock_item_id' => $fert->id, 'quantity' => 600])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.crops.activities.status', $activity->uuid), ['action' => 'complete'])->assertSessionHasNoErrors();
        $input = ActivityInput::withoutGlobalScopes()->sole();
        $this->actingAs($this->manager)->post(route('agri.crops.inputs.correct', $input->uuid), ['quantity' => 450, 'reason' => 'Spreader calibration'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('agri.finance.transactions.store'), ['farm_id' => $farm->uuid, 'kind' => 'expense', 'category' => 'contractors',
            'transacted_on' => today()->toDateString(), 'description' => 'Aerial spray', 'amount' => 12000, 'settled_by' => 'payable'])->assertSessionHasNoErrors();

        // Every record is in the books, and the inputs the farm says it used agree with what the ledger carries — net of the correction.
        $from = today()->startOfMonth()->toDateString();
        $to = today()->toDateString();
        $rec = $this->asCompany($this->company, fn () => app(FinanceIntegration::class)->reconcile($farm, $from, $to));
        $this->assertSame([], $rec['untraced']);
        $inputs = collect($rec['by_category'])->firstWhere('category', 'inputs');
        $this->assertEqualsWithDelta(4500.0, $inputs['operational'], 0.01);
        $this->assertEqualsWithDelta(4500.0, $inputs['books'], 0.01);
        $this->assertTrue($rec['ok']);

        // Each cost traces back to where it came from; the correction's reversal points at what it reverses.
        $integration = app(FinanceIntegration::class);
        $issueJournal = (int) DB::table('fin_stock_movements')->where('id', $input->fresh()->stock_movement_id)->value('journal_id');
        $t = $this->asCompany($this->company, fn () => $integration->trace($issueJournal));
        $this->assertSame('field activity', $t['origin']['record']);
        $this->assertSame($activity->id, (int) $t['origin']['id']);
        $txn = FarmTransaction::withoutGlobalScopes()->sole();
        $t = $this->asCompany($this->company, fn () => $integration->trace((int) $txn->journal_id));
        $this->assertSame('farm transaction', $t['origin']['record']);
        $this->assertSame($txn->uuid, $t['origin']['uuid']);
        $reversal = DB::table('fin_stock_movements')->where('reversal_of_id', $input->fresh()->stock_movement_id)->first();
        $this->assertNotNull($reversal, 'The original issue is reversed, not edited.');
        $this->actingAs($this->manager)->getJson(route('agri.finance.journals.trace', $issueJournal))->assertOk()->assertJsonPath('origin.record', 'field activity');

        // Exported once: a second export finds nothing new.
        $this->actingAs($this->manager)->post(route('agri.finance.exports.store'), ['target' => 'Sage', 'from' => $from, 'to' => $to])->assertOk();
        $export = AgriFinanceExport::withoutGlobalScopes()->sole();
        $rows = AgriExportedJournal::withoutGlobalScopes()->where('export_id', $export->id)->get();
        $this->assertGreaterThanOrEqual(4, $rows->count(), 'Opening stock, issue, reversal, corrected issue and the expense.');
        $this->assertSame($rows->count(), $rows->pluck('external_ref')->unique()->count());
        $this->actingAs($this->manager)->post(route('agri.finance.exports.store'), ['target' => 'Sage', 'from' => $from, 'to' => $to])->assertSessionHasErrors('period');

        // The other side accepts all but one; that one goes again, and only it.
        $failed = $rows->first();
        $results = $rows->mapWithKeys(fn ($r) => [$r->external_ref => $r->is($failed) ? ['status' => 'failed', 'error' => 'Account 5100 not mapped'] : ['status' => 'acknowledged', 'target_id' => 'SG-'.$r->id]])->all();
        $this->actingAs($this->manager)->post(route('agri.finance.exports.acknowledge', $export->uuid), ['results' => $results])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('partly_failed', $export->fresh()->status);
        $this->assertSame('Account 5100 not mapped', $failed->fresh()->error);
        $this->actingAs($this->manager)->post(route('agri.finance.exports.store'), ['target' => 'Sage', 'from' => $from, 'to' => $to])->assertOk();
        $second = AgriFinanceExport::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame(1, $second->journals);
        $this->assertSame(2, (int) $failed->fresh()->attempts);
        $this->assertSame('sent', $failed->fresh()->status);

        foreach (['reconcile', 'exports'] as $tab) {
            $this->actingAs($this->manager)->get(route('agri.finance.intelligence', ['tab' => $tab, 'farm' => $farm->uuid]))->assertOk();
        }
    }
}
