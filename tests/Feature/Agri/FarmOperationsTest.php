<?php

namespace Tests\Feature\Agri;

use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmAlert;
use App\Modules\Agri\Models\FarmDocument;
use App\Modules\Agri\Models\FarmMachine;
use App\Modules\Agri\Models\FarmTask;
use App\Modules\Agri\Models\FarmTransaction;
use App\Modules\Agri\Services\FarmAlerts;
use App\Modules\Agri\Services\FarmProfitability;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Spec Modules D–J: farm stores, finance, machinery, tasks, documents and alerts.
 */
class FarmOperationsTest extends AgriTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->catalogue();
    }

    // ── D: stores ────────────────────────────────────────────────────────────────────────────

    public function test_each_farm_has_its_own_store_in_the_shared_inventory(): void
    {
        $this->openBooks();
        $north = $this->farm('NORTH');
        $south = $this->farm('SOUTH');
        $diesel = $this->stockItem('DIESEL', 'l', 21);
        $this->stockOnFarm($north, $diesel, 1000, 21);
        $this->stockOnFarm($south, $diesel, 300, 21);

        $north->refresh();
        $south->refresh();
        $this->assertNotSame($north->warehouse_id, $south->warehouse_id);
        $ledger = app(\App\Modules\Finance\Accounting\Services\StockLedger::class);
        $this->assertEqualsWithDelta(1000.0, $ledger->available($diesel->fresh(), (int) $north->warehouse_id), 0.001);
        // One item, one balance: the catalogue total is the two stores together.
        $this->assertEqualsWithDelta(1300.0, (float) $diesel->fresh()->qty_on_hand, 0.001);
    }

    public function test_a_requisition_is_raised_in_procurement_not_kept_in_agri(): void
    {
        $this->openBooks();
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $urea = $this->stockItem('UREA', 'kg', 11);

        $this->actingAs($this->manager)->post(route('agri.stock.requisitions.store'), [
            'farm_id' => $farm->uuid, 'justification' => 'Top dressing', 'lines' => [['stock_item_id' => $urea->id, 'quantity' => 4000, 'unit_price' => 11.2]],
        ])->assertSessionHasNoErrors();

        $pr = DB::table('purchase_requests')->where('project_code', 'NORTH')->first();
        $this->assertSame('draft', $pr->status);
        $this->assertEqualsWithDelta(44800.0, (float) $pr->estimated_total, 0.01);
        $this->assertSame("fin_stock_item:{$urea->id}", DB::table('purchase_request_items')->where('purchase_request_id', $pr->id)->value('external_ref'));
    }

    // ── E: finance ───────────────────────────────────────────────────────────────────────────

    public function test_profitability_is_read_from_the_ledger_and_matches_what_was_posted(): void
    {
        $this->openBooks();
        $farm = $this->farm('NORTH');
        $field = $this->field($farm);
        $plan = $this->asCompany($this->company, fn () => CropPlan::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'farm_id' => $farm->id, 'field_id' => $field->id, 'season_id' => $this->seasonId(),
            'crop_type_id' => $this->cropTypeId(), 'code' => 'MZ', 'planned_area' => 40, 'status' => 'planted',
            'planned_planting_on' => today()->subMonth(), 'planned_harvest_on' => today()->addMonths(3), 'budget_amount' => 10000,
        ]));
        $post = fn (array $o) => $this->actingAs($this->admin)->post(route('agri.finance.transactions.store'), $o + [
            'farm_id' => $farm->uuid, 'transacted_on' => today()->toDateString(), 'enterprise' => 'crop_plan:'.$plan->uuid,
        ])->assertSessionHasNoErrors();

        $post(['kind' => 'expense', 'category' => 'contractors', 'description' => 'Aerial spray', 'amount' => 12000, 'settled_by' => 'payable']);
        $post(['kind' => 'sale', 'category' => 'produce', 'description' => 'Green mealies', 'amount' => 30000, 'settled_by' => 'receivable']);
        // A sale cannot be "payable".
        $this->actingAs($this->admin)->post(route('agri.finance.transactions.store'), ['farm_id' => $farm->uuid, 'kind' => 'sale', 'category' => 'produce',
            'transacted_on' => today()->toDateString(), 'description' => 'x', 'amount' => 1, 'settled_by' => 'payable'])->assertSessionHasErrors('settled_by');

        $this->assertSame(2, FarmTransaction::withoutGlobalScopes()->whereNotNull('journal_id')->count());
        $report = $this->asCompany($this->company, fn () => app(FarmProfitability::class)->forFarm($farm, today()->startOfYear()->toDateString(), today()->toDateString()));
        $e = collect($report['enterprises'])->firstWhere('key', 'crop_plan:'.$plan->id);
        $this->assertEqualsWithDelta(30000.0, $e['income'], 0.01);
        $this->assertEqualsWithDelta(12000.0, $e['costs'], 0.01);
        $this->assertEqualsWithDelta(18000.0, $e['gross_margin'], 0.01);
        $this->assertEqualsWithDelta(450.0, $e['margin_per_ha'], 0.01);
        $this->assertEqualsWithDelta(-2000.0, $e['budget_variance'], 0.01);

        // The platform's own report by tracking splits the same postings by farm code.
        $byTracking = DB::table('fin_journal_lines')->whereRaw("dimensions->>'tracking' = 'NORTH'")->count();
        $this->assertSame(2, $byTracking);

        // The over-budget plan raises an alert.
        $this->asCompany($this->company, fn () => app(FarmAlerts::class)->scan($this->company->id));
        $this->assertSame(1, FarmAlert::withoutGlobalScopes()->where('kind', 'budget_overrun')->count());

        $this->actingAs($this->admin)->get(route('agri.finance.export', ['farm' => $farm->uuid]))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_produce_sold_from_the_store_leaves_at_cost_and_the_margin_is_sale_less_carrying_value(): void
    {
        $this->openBooks();
        $farm = $this->farm('NORTH');
        $grain = $this->stockItem('WHEAT-T', 't', 0);
        $this->stockOnFarm($farm, $grain, 100, 5000);   // carried at 5 000 a tonne

        $this->actingAs($this->admin)->post(route('agri.stock.sell'), ['farm_id' => $farm->uuid, 'stock_item_id' => $grain->id, 'quantity' => 300,
            'amount' => 1, 'sold_on' => today()->toDateString(), 'description' => 'Too much', 'settled_by' => 'bank'])->assertSessionHasErrors('quantity');
        $this->actingAs($this->admin)->post(route('agri.stock.sell'), ['farm_id' => $farm->uuid, 'stock_item_id' => $grain->id, 'quantity' => 40,
            'amount' => 228000, 'sold_on' => today()->toDateString(), 'description' => '40 t wheat to the mill', 'settled_by' => 'receivable', 'buyer' => 'Mill'])
            ->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(60.0, (float) $grain->fresh()->qty_on_hand, 0.001);
        $report = $this->asCompany($this->company, fn () => app(FarmProfitability::class)->forFarm($farm, today()->startOfYear()->toDateString(), today()->toDateString()));
        $this->assertEqualsWithDelta(228000.0, $report['totals']['income'], 0.01);
        $this->assertEqualsWithDelta(200000.0, $report['totals']['costs'], 0.01, 'Cost of sales at the carrying value travels with the sale.');
        $this->assertEqualsWithDelta(28000.0, $report['totals']['gross_margin'], 0.01);
    }

    public function test_a_farm_transaction_in_error_is_reversed_in_the_ledger_not_deleted(): void
    {
        $this->openBooks();
        $farm = $this->farm('NORTH');
        $this->actingAs($this->admin)->post(route('agri.finance.transactions.store'), ['farm_id' => $farm->uuid, 'kind' => 'expense', 'category' => 'repairs',
            'transacted_on' => today()->toDateString(), 'description' => 'Wrong farm', 'amount' => 9000, 'settled_by' => 'bank'])->assertSessionHasNoErrors();
        $t = FarmTransaction::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($this->admin)->post(route('agri.finance.transactions.reverse', $t->uuid), [])->assertSessionHasErrors('reason');
        $this->actingAs($this->admin)->post(route('agri.finance.transactions.reverse', $t->uuid), ['reason' => 'Belongs to SOUTH'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('agri.finance.transactions.reverse', $t->uuid), ['reason' => 'again'])->assertSessionHasErrors('reason');

        $t->refresh();
        $this->assertNotNull($t->reversed_at);
        $this->assertNotNull($t->reversal_journal_id);
        $report = $this->asCompany($this->company, fn () => app(FarmProfitability::class)->forFarm($farm, today()->startOfYear()->toDateString(), today()->toDateString()));
        $this->assertEqualsWithDelta(0.0, $report['totals']['costs'], 0.01);
        $this->assertSame(2, DB::table('fin_journals')->whereIn('id', [$t->journal_id, $t->reversal_journal_id])->count(), 'Both journals stay in the books.');
    }

    public function test_finance_is_refused_to_people_without_finance_permission(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->worker, $farm, 'worker');
        $this->actingAs($this->worker)->get(route('agri.finance.index'))->assertForbidden();
        $this->actingAs($this->worker)->post(route('agri.finance.transactions.store'), ['farm_id' => $farm->uuid])->assertForbidden();
    }

    // ── F: machinery ─────────────────────────────────────────────────────────────────────────

    public function test_hour_meters_only_go_forward_services_reset_the_due_point_and_disposal_keeps_history(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $this->actingAs($this->manager)->post(route('agri.machinery.store'), [
            'farm_id' => $farm->uuid, 'code' => 'T-01', 'name' => 'John Deere 6120M', 'machine_type' => 'tractor',
            'hours_reading' => 1200, 'service_interval_hours' => 250, 'hourly_rate' => 450,
        ])->assertSessionHasNoErrors();
        $m = FarmMachine::withoutGlobalScopes()->firstOrFail();
        $this->assertEqualsWithDelta(1450.0, (float) $m->next_service_hours, 0.01);

        $this->actingAs($this->manager)->post(route('agri.machinery.usage', $m->uuid), ['used_on' => today()->toDateString(), 'hours_reading_after' => 1100])
            ->assertSessionHasErrors('hours_reading_after');
        $this->actingAs($this->manager)->post(route('agri.machinery.usage', $m->uuid), ['used_on' => today()->toDateString(), 'hours' => 8])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(1208.0, (float) $m->fresh()->hours_reading, 0.01);

        $this->actingAs($this->manager)->post(route('agri.machinery.services', $m->uuid), ['serviced_on' => today()->toDateString(), 'service_type' => 'service',
            'description' => '250h service'])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(1458.0, (float) $m->fresh()->next_service_hours, 0.01);

        $this->actingAs($this->manager)->post(route('agri.machinery.dispose', $m->uuid), ['disposed_on' => today()->toDateString(), 'reason' => 'Traded in'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.machinery.usage', $m->uuid), ['used_on' => today()->toDateString(), 'hours' => 2])->assertSessionHasErrors('machine');
        $this->assertSame(1, DB::table('machine_usage_logs')->where('machine_id', $m->id)->count());
    }

    // ── G: tasks ─────────────────────────────────────────────────────────────────────────────

    public function test_tasks_go_to_farm_members_only_and_nobody_approves_their_own_work(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $this->member($this->worker, $farm, 'worker');

        // The supervisor has no access to NORTH, so cannot be given its work.
        $this->actingAs($this->manager)->post(route('agri.tasks.store'), ['farm_id' => $farm->uuid, 'title' => 'Fix fence', 'priority' => 'normal',
            'assigned_to' => $this->supervisor->id, 'requires_approval' => true])->assertSessionHasErrors('assigned_to');
        $this->actingAs($this->manager)->post(route('agri.tasks.store'), ['farm_id' => $farm->uuid, 'title' => 'Fix fence', 'priority' => 'high',
            'assigned_to' => $this->worker->id, 'due_on' => today()->addDay()->toDateString(), 'requires_approval' => true])->assertSessionHasNoErrors();
        $task = FarmTask::withoutGlobalScopes()->firstOrFail();
        $go = fn ($user, array $d) => $this->actingAs($user)->post(route('agri.tasks.transition', $task->uuid), $d);

        // Only the assignee works it.
        $go($this->manager, ['action' => 'start'])->assertSessionHasErrors('status');
        $go($this->worker, ['action' => 'start'])->assertSessionHasNoErrors();
        $go($this->worker, ['action' => 'submit', 'labour_hours' => 3, 'completion_notes' => 'Done'])->assertSessionHasNoErrors();
        // A worker cannot approve; a reject needs a reason.
        $go($this->worker, ['action' => 'approve'])->assertForbidden();
        $go($this->manager, ['action' => 'reject'])->assertSessionHasErrors('review_note');
        $go($this->manager, ['action' => 'reject', 'review_note' => 'Bottom wire still loose'])->assertSessionHasNoErrors();
        $go($this->worker, ['action' => 'submit'])->assertSessionHasNoErrors();
        $go($this->manager, ['action' => 'approve'])->assertSessionHasNoErrors();
        $this->assertSame(FarmTask::APPROVED, $task->fresh()->status);
    }

    public function test_a_task_cannot_point_at_a_crop_plan_on_another_farm(): void
    {
        $north = $this->farm('NORTH');
        $south = $this->farm('SOUTH');
        $plan = $this->asCompany($this->company, fn () => CropPlan::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'farm_id' => $south->id, 'field_id' => $this->field($south)->id, 'season_id' => $this->seasonId(),
            'crop_type_id' => $this->cropTypeId(), 'code' => 'S1', 'planned_area' => 5, 'planned_planting_on' => today(), 'planned_harvest_on' => today()->addMonths(4),
        ]));
        $this->actingAs($this->admin)->post(route('agri.tasks.store'), ['farm_id' => $north->uuid, 'title' => 'x', 'priority' => 'low', 'crop_plan_id' => $plan->id])
            ->assertSessionHasErrors('crop_plan_id');
    }

    public function test_the_daily_alert_scan_runs_for_every_organisation(): void
    {
        $this->farm('NORTH');
        $this->artisan('agri:scan-alerts')->assertSuccessful();
    }

    public function test_a_supervisor_cannot_approve_work_they_did_themselves(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->supervisor, $farm, 'supervisor');
        $this->actingAs($this->supervisor)->post(route('agri.tasks.store'), ['farm_id' => $farm->uuid, 'title' => 'Check troughs', 'priority' => 'normal',
            'assigned_to' => $this->supervisor->id, 'requires_approval' => true])->assertSessionHasNoErrors();
        $task = FarmTask::withoutGlobalScopes()->firstOrFail();
        $this->actingAs($this->supervisor)->post(route('agri.tasks.transition', $task->uuid), ['action' => 'submit'])->assertSessionHasNoErrors();
        $this->actingAs($this->supervisor)->post(route('agri.tasks.transition', $task->uuid), ['action' => 'approve'])->assertSessionHasErrors('status');
    }

    // ── J: documents and I: alerts ───────────────────────────────────────────────────────────

    public function test_documents_are_stored_in_the_database_and_expiry_raises_one_alert_that_clears_itself(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $this->actingAs($this->manager)->post(route('agri.documents.store'), [
            'farm_id' => $farm->uuid, 'document_type' => 'water_use_licence', 'title' => 'Water use licence — borehole 2',
            'expires_on' => today()->addDays(10)->toDateString(), 'remind_days' => 30,
            'file' => UploadedFile::fake()->createWithContent('licence.pdf', '%PDF-1.4 test licence'),
        ])->assertSessionHasNoErrors();
        $doc = FarmDocument::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('%PDF-1.4 test licence', base64_decode($doc->data));
        $this->actingAs($this->manager)->get(route('agri.documents.download', $doc->uuid))->assertOk();

        $scan = fn () => $this->asCompany($this->company, fn () => app(FarmAlerts::class)->scan($this->company->id));
        $scan();
        $scan();
        $this->assertSame(1, FarmAlert::withoutGlobalScopes()->where('kind', 'document_expiry')->count(), 'A repeated scan does not duplicate the alert.');

        $doc->forceFill(['expires_on' => today()->addYears(5)])->saveQuietly();
        $scan();
        $this->assertSame(FarmAlert::RESOLVED, FarmAlert::withoutGlobalScopes()->where('kind', 'document_expiry')->value('status'));
    }

    public function test_alerts_on_a_farm_the_user_cannot_reach_are_not_shown(): void
    {
        $north = $this->farm('NORTH');
        $south = $this->farm('SOUTH');
        $this->member($this->worker, $north, 'worker');
        $this->asCompany($this->company, function () use ($south) {
            FarmTask::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $south->id, 'title' => 'Late south job',
                'priority' => 'normal', 'due_on' => today()->subDays(3), 'status' => 'open']);
            app(FarmAlerts::class)->scan($this->company->id);
        });
        $this->assertSame(1, FarmAlert::withoutGlobalScopes()->count());
        $this->assertSame([], $this->actingAs($this->worker)->get(route('agri.alerts.index'))->viewData('page')['props']['alerts']);
    }

    public function test_an_archived_farm_takes_no_new_work_in_any_module(): void
    {
        $farm = $this->farm('OLD', null, Farm::ARCHIVED);
        $this->actingAs($this->admin)->post(route('agri.tasks.store'), ['farm_id' => $farm->uuid, 'title' => 'x', 'priority' => 'low'])->assertSessionHasErrors('farm');
        $this->actingAs($this->admin)->post(route('agri.machinery.store'), ['farm_id' => $farm->uuid, 'code' => 'M', 'name' => 'x', 'machine_type' => 'pump'])->assertSessionHasErrors('farm');
        $this->actingAs($this->admin)->post(route('agri.livestock.groups.store'), ['farm_id' => $farm->uuid, 'species_id' => $this->speciesId('SHEEP'),
            'code' => 'G', 'name' => 'x', 'purpose' => 'wool'])->assertSessionHasErrors('farm');
    }
}
