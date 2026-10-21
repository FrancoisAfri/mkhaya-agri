<?php

namespace Tests\Feature\Agri;

use App\Modules\Agri\Models\ActivityInput;
use App\Modules\Agri\Models\CropObservation;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\FarmAlert;
use App\Modules\Agri\Models\FarmMachine;
use App\Modules\Agri\Models\FarmWorkOrder as WO;
use App\Modules\Agri\Models\FieldActivity;
use App\Modules\Agri\Models\WorkOrderLog;
use App\Modules\Agri\Notifications\FarmWorkNotice;
use App\Modules\Agri\Services\FarmAlerts;
use App\Modules\Agri\Services\WorkScheduler;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Phase 2 §2 beyond Release 01: reopening a completed job without counting anything twice, problems
 * from the field and what they set in motion, documents on a job, and the explainable recommended
 * work order with the manager's override.
 */
class WorkOrdersSectionTwoTest extends AgriTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->catalogue();
    }

    private function order($farm, array $o = []): WO
    {
        $this->actingAs($this->manager)->post(route('agri.work-orders.store'), $o + ['farm_id' => $farm->uuid, 'category' => 'spraying',
            'title' => 'Fungicide on L1', 'priority' => 'normal', 'planned_start' => now()->addHour()->toDateTimeString(),
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

    private function log(WO $wo, array $d, $user = null)
    {
        return $this->actingAs($user ?? $this->manager)->post(route('agri.work-orders.log', $wo->uuid), $d);
    }

    public function test_a_reopened_job_books_only_what_was_added_when_completed_again(): void
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
        $this->actingAs($this->manager)->post(route('agri.work-orders.materials', $wo->uuid), ['stock_item_id' => $chem->id, 'planned_quantity' => 20])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.work-orders.machines', $wo->uuid), ['machine_id' => $sprayer->uuid,
            'booked_from' => now()->addHour()->toDateTimeString(), 'booked_to' => now()->addHours(5)->toDateTimeString()])->assertSessionHasNoErrors();
        $this->to($wo, $this->admin, WO::APPROVED)->assertSessionHasNoErrors();
        $this->to($wo, $this->manager, WO::IN_PROGRESS)->assertSessionHasNoErrors();
        $m = $wo->materials()->first();
        $this->log($wo, ['kind' => 'material', 'material_id' => $m->id, 'quantity' => 18])->assertSessionHasNoErrors();
        $this->log($wo, ['kind' => 'machine', 'machine_id' => $sprayer->id, 'machine_hours' => 3])->assertSessionHasNoErrors();
        $this->to($wo, $this->manager, WO::VERIFICATION)->assertSessionHasNoErrors();
        $this->to($wo, $this->admin, WO::COMPLETED, ['reason' => 'Checked'])->assertSessionHasNoErrors();

        // Reopening needs a reason and an approver or verifier, not the person who did the work.
        $this->to($wo, $this->admin, WO::IN_PROGRESS)->assertSessionHasErrors('reason');
        $this->assertContains($this->to($wo, $this->worker, WO::IN_PROGRESS, ['reason' => 'Headlands'])->status(), [403, 404]);
        $this->assertSame(WO::COMPLETED, $wo->fresh()->status);
        $this->to($wo, $this->admin, WO::IN_PROGRESS, ['reason' => 'Headlands were missed'])->assertSessionHasNoErrors();
        $this->assertSame(1, $wo->fresh()->reopen_count);

        // Four more litres and one more hour, then completed again.
        $this->log($wo, ['kind' => 'material', 'material_id' => $m->id, 'quantity' => 4])->assertSessionHasNoErrors();
        $this->log($wo, ['kind' => 'machine', 'machine_id' => $sprayer->id, 'machine_hours' => 1])->assertSessionHasNoErrors();
        $this->to($wo, $this->manager, WO::VERIFICATION)->assertSessionHasNoErrors();
        $this->to($wo, $this->admin, WO::COMPLETED, ['reason' => 'Headlands done'])->assertSessionHasNoErrors();

        $wo->refresh();
        $moves = DB::table('fin_stock_movements')->where('source_type', 'agri_work_order')->where('source_id', $wo->id)->get();
        $this->assertCount(2, $moves, 'The first 18 l, then only the 4 l added.');
        $this->assertEqualsWithDelta(22.0, $moves->sum(fn ($x) => abs((float) $x->qty)), 0.001);
        $this->assertEqualsWithDelta(78.0, (float) $chem->fresh()->qty_on_hand, 0.001);
        $this->assertEqualsWithDelta(8800.0, $wo->actual_material_cost, 0.01);   // 22 l × R400
        $this->assertEqualsWithDelta(2000.0, $wo->actual_machine_cost, 0.01);    // 4 h × R500
        $this->assertEqualsWithDelta(104.0, (float) $sprayer->fresh()->hours_reading, 0.01, 'Machine hours logged once each.');
        $this->assertSame(1, FieldActivity::withoutGlobalScopes()->where('title', 'like', $wo->reference.'%')->count(), 'One activity on the crop, brought up to date.');
        $this->assertEqualsWithDelta(22.0, (float) ActivityInput::withoutGlobalScopes()->where('activity_id', $wo->field_activity_id)->sum('quantity'), 0.001);
        $this->assertEqualsWithDelta(8800.0, (float) DB::table('fin_journal_lines')->whereRaw("dimensions->>'agri_enterprise' = ?", ['crop_plan:'.$plan->id])->sum('debit'), 0.01);
    }

    public function test_problems_from_the_field_create_the_right_records_and_reach_the_managers(): void
    {
        Notification::fake();
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $this->member($this->supervisor, $farm, 'supervisor');
        $field = $this->field($farm);
        $sprayer = $this->machine($farm);
        $wo = $this->order($farm, ['field_id' => $field->uuid]);
        $this->to($wo, $this->admin, WO::APPROVED)->assertSessionHasNoErrors();
        $this->to($wo, $this->manager, WO::IN_PROGRESS)->assertSessionHasNoErrors();

        $this->log($wo, ['kind' => 'issue', 'issue_type' => 'disease', 'severity' => 'high', 'note' => 'Grey leaf spot on the east pivot'])->assertSessionHasNoErrors();
        $obs = CropObservation::withoutGlobalScopes()->sole();
        $this->assertSame('disease', $obs->kind);
        $this->assertSame($field->id, (int) $obs->field_id);
        $this->assertTrue((bool) $obs->action_required);

        $this->log($wo, ['kind' => 'issue', 'issue_type' => 'equipment_failure', 'machine_id' => $sprayer->id, 'note' => 'Pump seized'])->assertSessionHasNoErrors();
        $this->assertSame(FarmMachine::OUT_OF_SERVICE, $sprayer->fresh()->status);   // §7.3: a fault that stops it, until a repair job takes it in

        $this->log($wo, ['kind' => 'request', 'note' => 'Need 10 l more fungicide'])->assertSessionHasNoErrors();
        Notification::assertSentTo([$this->manager, $this->supervisor], FarmWorkNotice::class, fn ($n) => str_contains($n->subject, 'Request on'));
        Notification::assertSentTo($this->supervisor, FarmWorkNotice::class, fn ($n) => str_contains($n->subject, 'Equipment failure'));

        // Serious problems stay on the farm's alerts until resolved.
        $fail = WorkOrderLog::withoutGlobalScopes()->where('issue_type', 'equipment_failure')->sole();
        $this->asCompany($this->company, fn () => app(FarmAlerts::class)->scan($this->company->id));
        $this->assertTrue(FarmAlert::withoutGlobalScopes()->where('dedupe_key', "field_issue:{$fail->id}")->where('status', 'open')->exists());
        $this->actingAs($this->manager)->post(route('agri.work-orders.resolve', [$wo->uuid, $fail->uuid]), ['resolution' => 'Pump replaced'])->assertSessionHasNoErrors();
        $this->asCompany($this->company, fn () => app(FarmAlerts::class)->scan($this->company->id));
        $this->assertFalse(FarmAlert::withoutGlobalScopes()->where('dedupe_key', "field_issue:{$fail->id}")->where('status', 'open')->exists());
    }

    public function test_a_job_takes_documents_and_serves_only_safe_types_in_the_browser(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $wo = $this->order($farm);
        $this->to($wo, $this->admin, WO::APPROVED)->assertSessionHasNoErrors();
        $this->to($wo, $this->manager, WO::IN_PROGRESS)->assertSessionHasNoErrors();

        $pdf = UploadedFile::fake()->createWithContent('spray-record.pdf', "%PDF-1.4\n%test\n");
        $this->log($wo, ['kind' => 'progress', 'note' => 'Spray record', 'photo' => $pdf])->assertSessionHasNoErrors();
        $l = WorkOrderLog::withoutGlobalScopes()->whereNotNull('photo_name')->sole();
        $this->actingAs($this->manager)->get(route('agri.work-orders.photo', [$wo->uuid, $l->uuid]))->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');

        // A file whose type claims to be a web page is never shown as one.
        $l->update(['photo_mime' => 'text/html']);
        $this->actingAs($this->manager)->get(route('agri.work-orders.photo', [$wo->uuid, $l->uuid]))->assertOk()
            ->assertHeader('Content-Type', 'application/octet-stream');

        // And the phone's queue refuses anything but pictures and PDFs.
        $this->asCompany($this->company, fn () => $wo->workers()->create(['company_id' => $wo->company_id, 'farm_id' => $wo->farm_id,
            'worker_id' => $this->worker($farm, 'Mia', $this->worker)->id]));
        $r = $this->actingAs($this->worker)->postJson(route('agri.my-work.sync'), ['ops' => [['client_id' => (string) Str::uuid(), 'op' => 'wo.log',
            'captured_at' => now()->toIso8601String(), 'payload' => ['id' => $wo->uuid, 'log_id' => (string) Str::uuid(), 'kind' => 'progress',
                'photo_name' => 'x.svg', 'photo_mime' => 'image/svg+xml', 'photo_data' => base64_encode('<svg onload=alert(1)>')]]]])->assertOk();
        $this->assertSame('rejected', $r->json('results.0.status'));
    }

    public function test_the_recommended_order_explains_itself_puts_blocked_jobs_last_and_obeys_a_pin(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $w = $this->worker($farm, 'Anna');
        $ready = $this->order($farm, ['title' => 'Urgent spray', 'priority' => 'urgent']);
        $normal = $this->order($farm, ['title' => 'Routine weeding', 'category' => 'weeding']);
        $nobody = $this->order($farm, ['title' => 'Fence repair', 'category' => 'fencing', 'priority' => 'urgent']);
        foreach ([$ready, $normal] as $o) {
            $this->actingAs($this->manager)->post(route('agri.work-orders.workers', $o->uuid), ['worker_ids' => [$w->id], 'accept_warnings' => true])->assertSessionHasNoErrors();
        }

        $plan = fn () => collect($this->asCompany($this->company, fn () => app(WorkScheduler::class)->recommend($farm->fresh())))->keyBy('reference');
        $p = $plan();
        $this->assertSame(1, $p[$ready->reference]['position']);
        $this->assertSame(3, $p[$nobody->reference]['position'], 'Urgent, but nobody can do it yet.');
        $this->assertContains('Nobody is assigned yet', $p[$nobody->reference]['blockers']);
        $this->assertContains('Urgent priority', array_column($p[$ready->reference]['factors'], 'label'));

        $this->actingAs($this->manager)->post(route('agri.work-orders.schedule', $normal->uuid), ['rank' => 1, 'note' => 'Weeds seeding this week'])->assertSessionHasNoErrors();
        $p = $plan();
        $this->assertSame(1, $p[$normal->reference]['position']);
        $this->assertSame('Weeds seeding this week', $p[$normal->reference]['note']);

        $this->actingAs($this->manager)->get(route('agri.work-orders.index', ['farm' => $farm->uuid, 'view' => 'plan']))->assertOk()
            ->assertInertia(fn ($pg) => $pg->where('plan.jobs.0.reference', $normal->reference)->has('plan.not_connected', 2));
    }
}
