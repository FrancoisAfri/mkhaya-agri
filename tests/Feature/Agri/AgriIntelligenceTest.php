<?php

namespace Tests\Feature\Agri;

use App\Modules\Agri\Models\AgriAiDraft;
use App\Modules\Agri\Models\AgriPrediction;
use App\Modules\Agri\Models\AgriReference;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\FarmMachine;
use App\Modules\Agri\Models\FarmWorkOrder as WO;
use App\Modules\Agri\Models\ProduceLot;
use App\Modules\Agri\Services\AgriPredictions;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 §10: the farm-aware assistant — answers that state their period, sources and
 * assumptions; predictions written down and checked against what happened; proposals a person
 * accepts, and actions it never takes; advice only from approved references, asking for what it
 * lacks — and the section's acceptance test: someone without access to a farm gets nothing of that
 * farm's through the assistant, the reports, the documents or an indirect route.
 */
class AgriIntelligenceTest extends AgriTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['agri.assistant_narration' => false]);
        $this->catalogue();
    }

    private function ask($user, string $q, array $o = []): array
    {
        return $this->actingAs($user)->postJson(route('agri.assistant.ask'), ['question' => $q] + $o)->assertOk()->json();
    }

    private function planted($farm, $field, string $code = 'MZ-1', array $o = []): CropPlan
    {
        $this->actingAs($this->admin)->post(route('agri.crops.plans.store'), $o + ['farm_id' => $farm->uuid, 'field_id' => $field->uuid, 'season_id' => $this->seasonId(),
            'crop_type_id' => $this->cropTypeId(), 'code' => $code, 'planned_area' => 10, 'area_unit' => 'ha', 'planned_planting_on' => today()->subMonths(4)->toDateString(),
            'planned_harvest_on' => today()->addWeeks(4)->toDateString(), 'expected_yield' => 6000, 'yield_unit' => 'kg'])->assertSessionHasNoErrors();
        $plan = CropPlan::withoutGlobalScopes()->where('code', $code)->firstOrFail();
        $this->actingAs($this->admin)->post(route('agri.crops.plans.transition', $plan->uuid), ['action' => 'approve'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('agri.crops.plans.transition', $plan->uuid), ['action' => 'plant'])->assertSessionHasNoErrors();

        return $plan->fresh();
    }

    private function machine($farm, string $code = 'T-01'): FarmMachine
    {
        return $this->asCompany($this->company, fn () => app(\App\Modules\Agri\Services\Machinery::class)->register($farm,
            ['code' => $code, 'name' => 'Tractor', 'machine_type' => 'tractor', 'hours_reading' => 900, 'service_interval_hours' => 250]));
    }

    public function test_10_1_the_farm_questions_are_routed_and_every_answer_states_its_period_sources_and_assumptions(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $this->planted($farm, $this->field($farm));
        $this->actingAs($this->manager)->post(route('agri.work-orders.store'), ['farm_id' => $farm->uuid, 'category' => 'weeding', 'title' => 'Weed L1', 'priority' => 'normal',
            'planned_start' => now()->subDays(3)->toDateTimeString(), 'planned_end' => now()->subDays(2)->toDateTimeString()])->assertSessionHasNoErrors();
        $wo = WO::withoutGlobalScopes()->sole();
        $this->actingAs($this->admin)->post(route('agri.work-orders.transition', $wo->uuid), ['to' => WO::APPROVED])->assertSessionHasNoErrors();

        $expect = [
            'Which fields are likely to fall below their yield target?' => 'yield_risk',
            'What is the estimated cost of harvesting the remaining crop?' => 'harvest_cost',
            'Which stock items may run out before harvest?' => 'stock_before_harvest',
            'Which work orders are behind schedule?' => 'late_work_orders',
            'What is the labour cost per kilogram this season?' => 'labour_per_kg',
            'Which buyers have outstanding payments?' => 'buyer_payments',
            'Which livestock groups require scheduled care?' => 'livestock_care',
            'Which machines are overdue for service?' => 'machinery',
        ];
        foreach ($expect as $q => $topic) {
            $a = $this->ask($this->manager, $q);
            $this->assertSame($topic, $a['topic'], $q);
            $this->assertFalse($a['refused'], $q);
            $this->assertNotEmpty($a['period'], $q);
            $this->assertArrayHasKey('assumptions', $a, $q);
            $this->assertNotEmpty($a['summary'], $q);
        }
        $late = $this->ask($this->manager, 'Which work orders are behind schedule?');
        $this->assertSame($wo->reference, $late['rows'][0]['work_order']);
        $this->assertNotEmpty($late['sources']);
        $this->assertNotEmpty($late['assumptions']);
    }

    public function test_10_3_it_proposes_drafts_a_person_accepts_with_their_own_permissions_and_refuses_the_actions_it_never_takes(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $this->member($this->worker, $farm, 'worker');
        $tractor = $this->machine($farm);
        $this->asCompany($this->company, fn () => $tractor->update(['hours_reading' => 1400, 'next_service_on' => today()->subMonth()]));   // well past its service

        foreach (['Please pay the Agri Supplies invoice' => 'payment', 'Start the tractor for me' => 'machine_activation', 'change payroll for the harvest crew' => 'payroll_change',
            'Dispose of the old seed' => 'stock_disposal', 'apply pesticide on L1 now' => 'pesticide_application'] as $q => $kind) {
            $a = $this->ask($this->manager, $q);
            $this->assertTrue($a['refused'], $q);
            $this->assertSame($kind, $a['action_refused'], $q);
        }

        $a = $this->ask($this->manager, 'Which machines should be inspected first?');
        $this->assertSame('machine_risk', $a['topic']);
        $this->assertSame('inspection', $a['drafts'][0]['kind']);
        $this->assertSame(0, WO::withoutGlobalScopes()->count(), 'A proposal does nothing by itself.');
        $draft = AgriAiDraft::withoutGlobalScopes()->sole();

        // Only the person it was proposed to sees it; accepting needs the action's own permission.
        $this->actingAs($this->worker)->post(route('agri.assistant.drafts.decide', $draft->uuid), ['accept' => true])->assertStatus(403);
        $this->actingAs($this->manager)->post(route('agri.assistant.drafts.decide', $draft->uuid), ['accept' => true])->assertSessionHasNoErrors();
        $wo = WO::withoutGlobalScopes()->sole();
        $this->assertSame(WO::DRAFT, $wo->status, 'What it makes is a draft, approved the usual way.');
        $this->assertSame('machinery_service', $wo->category);
        $this->assertSame($tractor->id, (int) $wo->machine_id);
        $this->assertSame('accepted', $draft->fresh()->status);

        // An answer kept as a management report, once accepted.
        $this->actingAs($this->manager)->post(route('agri.assistant.report'), ['question' => 'Which machines are due for a service?'])->assertSessionHasNoErrors();
        $report = AgriAiDraft::withoutGlobalScopes()->where('kind', 'report')->sole();
        $this->actingAs($this->manager)->post(route('agri.assistant.drafts.decide', $report->uuid), ['accept' => true])->assertSessionHasNoErrors();
        // Kept as a farm record in Document Management.
        $doc = \App\Modules\Operations\DocumentManagement\Models\Document::withoutGlobalScopes()->findOrFail($report->fresh()->result_id);
        $bytes = \Illuminate\Support\Facades\Storage::disk('documents')->get(DB::table('document_versions')->where('id', $doc->current_version_id)->value('path'));
        $this->assertStringContainsString('T-01', $bytes);
        $this->assertStringContainsString('Sources:', $bytes);
    }

    public function test_10_4_advice_asks_for_the_crop_place_and_stage_and_quotes_only_approved_references(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $plan = $this->planted($farm, $this->field($farm));

        $a = $this->ask($this->manager, 'What should I spray for rust?');
        $this->assertTrue($a['advisory']);
        $this->assertEqualsCanonicalizing(['crop', 'location', 'growth_stage'], $a['needs']);
        $this->assertSame([], $a['references']);

        $a = $this->ask($this->manager, 'What should I spray for rust?', ['plan_id' => $plan->uuid]);
        $this->assertSame(['growth_stage'], $a['needs'], 'The crop and farm come from the plan; the stage is not recorded yet.');
        $this->actingAs($this->manager)->post(route('agri.crops.stages.store', $plan->uuid), ['stage' => 'vegetative', 'observed_on' => today()->toDateString()])->assertSessionHasNoErrors();

        $a = $this->ask($this->manager, 'What should I spray for rust?', ['plan_id' => $plan->uuid]);
        $this->assertSame([], $a['needs']);
        $this->assertSame([], $a['references']);
        $this->assertStringContainsString('do not give general', $a['summary']);

        // A reference is written, approved by someone else, and only then quoted.
        $this->actingAs($this->manager)->post(route('agri.assistant.references.store'), ['title' => 'Maize rust control', 'kind' => 'agronomic', 'crop_type_id' => $plan->crop_type_id,
            'stages' => ['vegetative', 'flowering'], 'topics' => ['rust', 'fungicide'], 'source' => 'ARC Grain Crops guide, 2025 ed., p. 41',
            'content' => 'Common rust on maize is managed with resistant cultivars first. Where pustules exceed the threshold before flowering, a registered triazole fungicide may be applied at label rate. Observe the label withholding period.'])->assertSessionHasNoErrors();
        $ref = AgriReference::withoutGlobalScopes()->sole();
        $a = $this->ask($this->manager, 'What should I spray for rust?', ['plan_id' => $plan->uuid]);
        $this->assertSame([], $a['references'], 'A draft reference is not quoted.');
        $this->actingAs($this->manager)->post(route('agri.assistant.references.approve', $ref->uuid))->assertSessionHasErrors('reference');
        $this->actingAs($this->admin)->post(route('agri.assistant.references.approve', $ref->uuid))->assertSessionHasNoErrors();
        $a = $this->ask($this->manager, 'What should I spray for rust?', ['plan_id' => $plan->uuid]);
        $this->assertSame('Maize rust control', $a['references'][0]['title']);
        $this->assertStringContainsString('ARC Grain Crops', $a['references'][0]['source']);
        $this->assertStringContainsString('fungicide', $a['references'][0]['excerpt']);
        $this->assertSame('vegetative', $a['context']['growth_stage']);

        $this->actingAs($this->manager)->get(route('agri.assistant.index'))->assertOk();
    }

    public function test_10_2_predictions_are_written_down_once_a_day_and_scored_against_what_happened(): void
    {
        $this->openBooks();
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $plan = $this->planted($farm, $this->field($farm));
        $tractor = $this->machine($farm);
        $p = app(AgriPredictions::class);

        $made = $this->asCompany($this->company, fn () => $p->snapshot($this->company->id));
        $this->assertGreaterThanOrEqual(3, $made);   // yield, harvest readiness, machine risk
        $this->assertSame(0, $this->asCompany($this->company, fn () => $p->snapshot($this->company->id)), 'Once a day.');
        $yield = AgriPrediction::withoutGlobalScopes()->where('model', 'yield')->sole();
        $this->assertEquals(60000, $yield->predicted_value);
        $this->assertSame('low', $yield->confidence);
        $this->assertSame('expected yield × area', $yield->basis['source']);

        // The crop comes in at 54 000 kg and is completed; the tractor breaks down.
        $item = $this->stockItem('MZ', 'kg', 3);
        $this->actingAs($this->manager)->post(route('agri.crops.harvests.store', $plan->uuid), ['harvested_on' => today()->toDateString(), 'quantity' => 54000, 'unit' => 'kg',
            'destination' => 'store', 'stock_item_id' => $item->id, 'unit_value' => 3])->assertSessionHasNoErrors();
        $this->asCompany($this->company, fn () => CropPlan::withoutGlobalScopes()->whereKey($plan->id)->update(['status' => CropPlan::COMPLETED]));
        $this->asCompany($this->company, fn () => app(\App\Modules\Agri\Services\MachineMaintenance::class)->report($tractor, 'critical', 'Engine seized', true, $this->manager));
        $this->travel(31)->days();

        $scored = $this->asCompany($this->company, fn () => $p->score($this->company->id));
        $this->assertGreaterThanOrEqual(3, $scored);
        $this->assertEquals(-6000, $yield->fresh()->error);
        $ready = AgriPrediction::withoutGlobalScopes()->where('model', 'harvest_ready')->sole();
        $this->assertEquals(-28, $ready->fresh()->error, 'Harvested four weeks before the planned date.');
        $this->assertEquals(1, AgriPrediction::withoutGlobalScopes()->where('model', 'machine_breakdown')->sole()->actual_value);

        $cards = collect($this->asCompany($this->company, fn () => $p->evaluate($this->company->id)))->keyBy('model');
        $this->assertEquals(10.0, $cards['yield']['metric']['mean_abs_error_pct']);
        $this->assertSame('low', $cards['yield']['confidence']);
        $this->assertStringContainsString('Fewer than ten', $cards['yield']['limitations']);
        $this->assertNotNull($cards['yield']['data_as_of']);
        $this->artisan('agri:predictions', ['--company' => $this->company->id])->assertSuccessful();
    }

    public function test_acceptance_without_access_to_a_farm_nothing_of_it_comes_back_through_the_assistant_reports_documents_or_indirect_routes(): void
    {
        $this->openBooks();
        $mine = $this->farm('NORTH');
        $other = $this->farm('SOUTH');
        $this->member($this->manager, $mine);   // the manager's role reaches only the farms they are on
        $myPlan = $this->planted($mine, $this->field($mine, 'N1'), 'N-MZ');
        $theirPlan = $this->planted($other, $this->field($other, 'S1'), 'S-MZ', ['expected_price' => 4]);
        $theirMachine = $this->machine($other, 'S-T1');
        $this->asCompany($this->company, fn () => $theirMachine->update(['next_service_on' => today()->subMonth()]));
        $item = $this->stockItem('MZ', 'kg', 3);
        $this->actingAs($this->admin)->post(route('agri.crops.harvests.store', $theirPlan->uuid), ['harvested_on' => today()->toDateString(), 'quantity' => 9000, 'unit' => 'kg',
            'destination' => 'store', 'stock_item_id' => $item->id, 'unit_value' => 3])->assertSessionHasNoErrors();
        $theirLot = ProduceLot::withoutGlobalScopes()->where('farm_id', $other->id)->sole();
        $this->actingAs($this->admin)->post(route('agri.finance.transactions.store'), ['farm_id' => $other->uuid, 'kind' => 'expense', 'category' => 'repairs',
            'transacted_on' => today()->toDateString(), 'description' => 'South pump', 'amount' => 999, 'settled_by' => 'payable'])->assertSessionHasNoErrors();
        $theirJournal = (int) DB::table('farm_transactions')->where('farm_id', $other->id)->value('journal_id');
        $theirDoc = $this->fileDocument($other, 'lease', 'South lease', today()->addDays(5)->toDateString(), $this->admin);

        // The assistant: only the manager's own farm, whatever is asked and however.
        foreach (['How are the crops doing this season?', 'Which machines are due for a service?', 'Which machines should be inspected first?', 'Which fields are likely to fall below their yield target?',
            'What is the estimated cost of harvesting the remaining crop?', 'What needs my attention on the farms today?', 'Tell me about SOUTH and S-MZ and S-T1'] as $q) {
            $a = $this->ask($this->manager, $q);
            $this->assertNotContains($other->name, $a['farms'] ?? [], $q);
            $flat = json_encode($a);
            $this->assertStringNotContainsString('S-MZ', $flat, $q);
            $this->assertStringNotContainsString('S-T1', $flat, $q);
        }
        $a = $this->ask($this->manager, 'What should I spray for rust?', ['plan_id' => $theirPlan->uuid]);
        $this->assertNull($a['context']['plan'], 'Another farm\'s plan is simply not found.');
        $this->assertContains('crop', $a['needs']);
        $this->assertSame(0, AgriAiDraft::withoutGlobalScopes()->whereNotNull('farm_id')->where('farm_id', $other->id)->count());

        // Reports: naming the other farm shows the manager's own instead.
        $this->actingAs($this->manager)->get(route('agri.finance.intelligence', ['tab' => 'costs', 'farm' => $other->uuid]))->assertOk()
            ->assertInertia(fn ($p) => $p->where('farm.id', $mine->uuid));
        $this->actingAs($this->manager)->get(route('agri.packhouse', ['tab' => 'trace', 'farm' => $other->uuid, 'lot' => $theirLot->code]))->assertOk()
            ->assertInertia(fn ($p) => $p->where('farm.id', $mine->uuid)->where('data.backward', null));
        // The cash flow forecast leaves the other farm's harvest out.
        $flow = $this->actingAs($this->manager)->get(route('agri.finance.intelligence', ['tab' => 'cashflow']))->assertOk()->viewData('page')['props']['data']['forecast'];
        $this->assertSame(0.0, (float) collect($flow['weeks'])->sum(fn ($w) => $w['in']['harvest'] ?? 0));
        // Documents and indirect routes: not found.
        // Their farm's records are not among the manager's (farm records are reached through the farms a person can open).
        $this->actingAs($this->manager);
        $this->assertNotContains($theirDoc->id, $this->asCompany($this->company, fn () => \App\Modules\Agri\Services\FarmDocuments::expiring(
            \App\Modules\Agri\Models\Farm::query()->pluck('id')->all())->pluck('document.id')->all()));
        $this->actingAs($this->manager)->getJson(route('agri.finance.journals.trace', $theirJournal))->assertNotFound();
        $this->actingAs($this->manager)->getJson(route('agri.packhouse.lots.trace', $theirLot->uuid))->assertNotFound();
        $this->actingAs($this->manager)->getJson(route('agri.machinery.costs', $theirMachine->uuid))->assertNotFound();
        $this->actingAs($this->manager)->getJson(route('agri.machinery.affected', $theirMachine->uuid))->assertNotFound();
        $this->actingAs($this->manager)->get(route('agri.crops.plans.show', $theirPlan->uuid))->assertNotFound();

        // The same questions from someone who may reach every farm do see it — the walls are the access rules, not missing data.
        $this->assertContains($other->name, $this->ask($this->admin, 'How are the crops doing this season?')['farms']);
    }
}
