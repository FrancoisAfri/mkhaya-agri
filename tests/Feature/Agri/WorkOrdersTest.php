<?php

namespace Tests\Feature\Agri;

use App\Modules\Agri\Models\ActivityInput;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\FarmMachine;
use App\Modules\Agri\Models\FarmWorkOrder as WO;
use App\Modules\Agri\Models\FieldActivity;
use App\Modules\Agri\Services\WorkOrders;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Phase 2 §2: the work-order lifecycle, its controls, and costing without double counting. */
class WorkOrdersTest extends AgriTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->catalogue();
    }

    private function order($farm, array $o = []): WO
    {
        $this->actingAs($this->manager)->post(route('agri.work-orders.store'), $o + ['farm_id' => $farm->uuid, 'category' => 'spraying',
            'title' => 'Fungicide on L1', 'priority' => 'high', 'planned_start' => now()->addHour()->toDateTimeString(),
            'planned_end' => now()->addHours(6)->toDateTimeString()])->assertSessionHasNoErrors();

        return WO::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    private function to(WO $wo, $user, string $to, array $extra = [])
    {
        return $this->actingAs($user)->post(route('agri.work-orders.transition', $wo->uuid), ['to' => $to] + $extra);
    }

    private function machine($farm, string $code = 'S-01'): FarmMachine
    {
        return $this->asCompany($this->company, fn () => app(\App\Modules\Agri\Services\Machinery::class)->register($farm,
            ['code' => $code, 'name' => 'Sprayer', 'machine_type' => 'sprayer', 'hours_reading' => 100, 'hourly_rate' => 500]));
    }

    public function test_a_job_moves_only_along_its_lifecycle_with_reasons_and_separate_approvers(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $this->member($this->supervisor, $farm, 'supervisor');
        $wo = $this->order($farm);
        $this->assertMatchesRegularExpression('/^WO-\d{4}-0001$/', $wo->reference);

        $this->to($wo, $this->manager, WO::APPROVED)->assertSessionHasErrors('status');      // created it
        $this->to($wo, $this->manager, WO::COMPLETED)->assertSessionHasErrors('status');     // not from draft
        $this->to($wo, $this->admin, WO::APPROVED)->assertSessionHasNoErrors();
        $this->to($wo, $this->supervisor, WO::IN_PROGRESS)->assertSessionHasNoErrors();
        $this->to($wo, $this->supervisor, WO::PAUSED)->assertSessionHasErrors('reason');
        $this->to($wo, $this->supervisor, WO::PAUSED, ['reason' => 'Wind too strong'])->assertSessionHasNoErrors();
        $this->to($wo, $this->supervisor, WO::IN_PROGRESS)->assertSessionHasNoErrors();
        $this->to($wo, $this->supervisor, WO::VERIFICATION)->assertSessionHasNoErrors();
        $this->to($wo, $this->supervisor, WO::COMPLETED)->assertSessionHasErrors('status');  // submitted it
        $this->to($wo, $this->admin, WO::REJECTED, ['reason' => 'Headlands missed'])->assertSessionHasNoErrors();
        $this->to($wo, $this->supervisor, WO::IN_PROGRESS)->assertSessionHasNoErrors();
        $this->to($wo, $this->supervisor, WO::VERIFICATION)->assertSessionHasNoErrors();
        $this->to($wo, $this->admin, WO::COMPLETED)->assertSessionHasNoErrors();

        $this->assertSame(WO::COMPLETED, $wo->fresh()->status);
        $this->assertSame(['draft', 'approved', 'in_progress', 'paused', 'in_progress', 'verification', 'rejected', 'in_progress', 'verification', 'completed'],
            DB::table('work_order_status_changes')->where('work_order_id', $wo->id)->orderBy('id')->pluck('to_status')->all());
    }

    public function test_completion_issues_materials_once_records_the_crop_activity_and_costs_without_double_counting(): void
    {
        $this->openBooks();
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $field = $this->field($farm);
        $plan = $this->asCompany($this->company, fn () => CropPlan::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $farm->id,
            'field_id' => $field->id, 'season_id' => $this->seasonId(), 'crop_type_id' => $this->cropTypeId(), 'code' => 'MZ', 'planned_area' => 40,
            'status' => 'planted', 'planned_planting_on' => today()->subMonth(), 'planned_harvest_on' => today()->addMonths(3)]));
        $chem = $this->stockItem('FUNG', 'l', 400);
        $this->stockOnFarm($farm, $chem, 100, 400);
        $sprayer = $this->machine($farm);
        $wo = $this->order($farm, ['crop_plan_id' => $plan->uuid]);
        $this->actingAs($this->manager)->post(route('agri.work-orders.materials', $wo->uuid), ['stock_item_id' => $chem->id, 'planned_quantity' => 20,
            'withholding_days' => 21])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.work-orders.machines', $wo->uuid), ['machine_id' => $sprayer->uuid,
            'booked_from' => now()->addHour()->toDateTimeString(), 'booked_to' => now()->addHours(5)->toDateTimeString()])->assertSessionHasNoErrors();
        $this->to($wo, $this->admin, WO::APPROVED)->assertSessionHasNoErrors();

        // Approval reserved the chemical: it is no longer free for other work.
        $ledger = app(\App\Modules\Finance\Accounting\Services\StockLedger::class);
        $this->assertEqualsWithDelta(80.0, $ledger->available($chem->fresh(), (int) $farm->fresh()->warehouse_id), 0.001);

        $this->to($wo, $this->manager, WO::IN_PROGRESS)->assertSessionHasNoErrors();
        $m = $wo->materials()->first();
        $log = fn (array $d) => $this->actingAs($this->manager)->post(route('agri.work-orders.log', $wo->uuid), $d)->assertSessionHasNoErrors();
        $log(['kind' => 'material', 'material_id' => $m->id, 'quantity' => 18]);
        $log(['kind' => 'machine', 'machine_id' => $sprayer->id, 'machine_hours' => 3.5]);
        $log(['kind' => 'progress', 'minutes' => 240, 'quantity' => 38, 'note' => '38 ha sprayed']);
        $this->to($wo, $this->manager, WO::VERIFICATION, ['actual_output' => 38])->assertSessionHasNoErrors();
        $this->to($wo, $this->admin, WO::COMPLETED, ['reason' => 'Checked the tank log'])->assertSessionHasNoErrors();

        $wo->refresh();
        $this->assertEqualsWithDelta(82.0, (float) FinStockItem::withoutGlobalScopes()->find($chem->id)->qty_on_hand, 0.001, '18 l used, issued once.');
        $this->assertEqualsWithDelta(82.0, $ledger->available($chem->fresh(), (int) $farm->fresh()->warehouse_id), 0.001, 'The unused reservation is released.');
        $this->assertSame(1, DB::table('fin_stock_movements')->where('source_type', 'agri_work_order')->where('source_id', $wo->id)->count());
        $this->assertEqualsWithDelta(7200.0, $wo->actual_material_cost, 0.01);
        $this->assertEqualsWithDelta(1750.0, $wo->actual_machine_cost, 0.01);
        $this->assertEqualsWithDelta(103.5, (float) $sprayer->fresh()->hours_reading, 0.01);

        // The crop record shows the work and the chemical (for the withholding period) without issuing it again.
        $activity = FieldActivity::withoutGlobalScopes()->findOrFail($wo->field_activity_id);
        $this->assertSame($plan->id, (int) $activity->crop_plan_id);
        $input = ActivityInput::withoutGlobalScopes()->where('activity_id', $activity->id)->sole();
        $this->assertSame('issued', $input->status);
        $this->assertSame(21, (int) $input->withholding_days);
        $this->assertNotNull(app(\App\Modules\Agri\Services\FieldWork::class)->withholdingUntil($plan));
        $this->assertEqualsWithDelta(7200.0, (float) DB::table('fin_journal_lines')->whereRaw("dimensions->>'agri_enterprise' = ?", ['crop_plan:'.$plan->id])->sum('debit'), 0.01);

        // And it cannot be completed again.
        $this->to($wo, $this->admin, WO::COMPLETED)->assertSessionHasErrors('status');
    }

    public function test_a_machine_cannot_be_double_booked_and_a_breakdown_shows_the_jobs_it_affects(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $sprayer = $this->machine($farm);
        $a = $this->order($farm, ['title' => 'Job A']);
        $b = $this->order($farm, ['title' => 'Job B']);
        $book = fn (WO $wo, int $from, int $to) => $this->actingAs($this->manager)->post(route('agri.work-orders.machines', $wo->uuid), ['machine_id' => $sprayer->uuid,
            'booked_from' => now()->addHours($from)->toDateTimeString(), 'booked_to' => now()->addHours($to)->toDateTimeString()]);

        $book($a, 1, 5)->assertSessionHasNoErrors();
        $book($b, 4, 8)->assertSessionHasErrors('machine_id');
        $book($b, 5, 8)->assertSessionHasNoErrors();

        $affected = $this->asCompany($this->company, fn () => app(WorkOrders::class)->affectedBy($sprayer));
        $this->assertEqualsCanonicalizing([$a->reference, $b->reference], array_column($affected, 'reference'));

        $this->asCompany($this->company, fn () => $sprayer->update(['status' => 'disposed']));
        $c = $this->order($farm, ['title' => 'Job C']);
        $book($c, 9, 10)->assertSessionHasErrors('machine_id');
    }

    public function test_only_certified_people_go_on_restricted_jobs(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $this->rules(['certification_policy' => 'block']);
        $wo = $this->order($farm, ['required_certifications' => ['PCO']]);
        $anna = $this->worker($farm, 'Anna');
        $ben = $this->worker($farm, 'Ben');
        $this->competency($ben, 'PCO');

        $this->actingAs($this->manager)->post(route('agri.work-orders.workers', $wo->uuid), ['worker_ids' => [$anna->id], 'accept_warnings' => true])
            ->assertSessionHasErrors('worker_id');
        $this->actingAs($this->manager)->post(route('agri.work-orders.workers', $wo->uuid), ['worker_ids' => [$ben->id]])->assertSessionHasNoErrors();
    }

    public function test_crew_work_from_the_field_is_recorded_once_offline_and_strangers_cannot_touch_the_job(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $this->member($this->worker, $farm, 'worker');
        $me = $this->worker($farm, 'Sipho', $this->worker);
        $wo = $this->order($farm);
        $this->to($wo, $this->admin, WO::APPROVED)->assertSessionHasNoErrors();
        $sync = fn (array $ops) => $this->actingAs($this->worker)->postJson(route('agri.my-work.sync'), ['ops' => $ops])->assertOk()->json('results');
        $start = ['client_id' => (string) Str::uuid(), 'op' => 'wo.start', 'payload' => ['id' => $wo->uuid]];

        $this->assertSame('rejected', $sync([$start])[0]['status'], 'Not on the job yet.');

        $this->actingAs($this->manager)->post(route('agri.work-orders.workers', $wo->uuid), ['worker_ids' => [$me->id]])->assertSessionHasNoErrors();
        $start['client_id'] = (string) Str::uuid();
        $log = ['client_id' => (string) Str::uuid(), 'op' => 'wo.log', 'payload' => ['id' => $wo->uuid, 'kind' => 'progress', 'minutes' => 90, 'quantity' => 12,
            'log_id' => (string) Str::uuid()]];
        $this->assertSame(['applied', 'applied'], array_column($sync([$start, $log]), 'status'));
        $sync([$start, $log]);
        $this->assertSame(1, DB::table('work_order_logs')->where('work_order_id', $wo->id)->where('kind', 'progress')->count());
        $this->assertSame(WO::IN_PROGRESS, $wo->fresh()->status);

        // Another farm's job is not found.
        $south = $this->farm('SOUTH');
        $theirs = $this->asCompany($this->company, fn () => app(WorkOrders::class)->create($south, ['category' => 'fencing', 'title' => 'x', 'priority' => 'low',
            'planned_start' => now(), 'planned_end' => now()->addHour()], $this->admin));
        $this->actingAs($this->manager)->get(route('agri.work-orders.show', $theirs->uuid))->assertNotFound();
    }

    public function test_the_job_page_and_my_work_give_the_screens_what_they_need(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $this->member($this->supervisor, $farm, 'supervisor');
        $field = $this->field($farm);
        $me = $this->worker($farm, 'Thandi', $this->supervisor);
        $this->asCompany($this->company, fn () => \App\Modules\HR\Hr\Attendance\Models\PieceRate::withoutGlobalScopes()->create(['company_id' => $this->company->id,
            'farm_id' => $farm->id, 'activity' => 'harvesting', 'unit' => 'crate', 'rate' => 4.5, 'valid_from' => today()->subDay(), 'status' => 'approved']));
        $wo = $this->order($farm, ['field_id' => $field->uuid]);
        $this->actingAs($this->manager)->post(route('agri.work-orders.workers', $wo->uuid), ['worker_ids' => [$me->id], 'accept_warnings' => true])->assertSessionHasNoErrors();
        $this->to($wo, $this->admin, WO::APPROVED)->assertSessionHasNoErrors();

        $this->actingAs($this->manager)->get(route('agri.work-orders.show', $wo->uuid))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Modules/Agri/WorkOrders/Show')
                ->where('order.field_uuid', $field->uuid)->where('order.farm_id', $farm->uuid)->has('workers', 1));

        // Editing from the page keeps the field it was planned on.
        $this->actingAs($this->manager)->patch(route('agri.work-orders.update', $wo->uuid), ['category' => 'spraying', 'title' => 'Fungicide on L1 (rev)',
            'priority' => 'high', 'field_id' => $field->uuid, 'planned_start' => now()->addHour()->toDateTimeString(),
            'planned_end' => now()->addHours(6)->toDateTimeString()])->assertSessionHasNoErrors();
        $this->assertSame($field->id, (int) $wo->fresh()->field_id);

        $this->actingAs($this->supervisor)->get(route('agri.my-work'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Modules/Agri/MyWork')->where('worker.farm_id', $farm->uuid)
                ->where('rates.0.unit', 'crate')->where('jobs.0.id', $wo->uuid));
    }
}
