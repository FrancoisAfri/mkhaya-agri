<?php

namespace Tests\Feature\Agri;

use App\Modules\HR\Hr\Attendance\Models\ClockEvent as FarmClockEvent;
use App\Modules\HR\Hr\Attendance\Models\RosteredShift as FarmShift;
use App\Modules\HR\Hr\Attendance\Models\ShiftAssignment as FarmShiftAssignment;
use App\Modules\HR\Hr\Attendance\Models\ShiftChange as FarmShiftChange;
use App\Modules\HR\Hr\Attendance\Models\Timesheet as FarmTimesheet;
use App\Modules\HR\Hr\Attendance\Models\PieceRate;
use App\Modules\HR\Hr\Attendance\Models\PieceworkEntry;
use App\Modules\HR\Hr\Attendance\Services\Attendance;
use App\Modules\Agri\Services\LabourCosting;
use App\Modules\HR\Hr\Attendance\Services\Rostering;
use App\Modules\Agri\Support\RoleCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 2 §1.8 acceptance tests LAB-001 … LAB-012, one test each, named for the scenario.
 */
class LabourAcceptanceTest extends AgriTestCase
{
    private function at(string $when): Carbon
    {
        // Times in these scenarios are the farm's clock (the test farms are in Africa/Johannesburg).
        return Carbon::parse($when, 'Africa/Johannesburg');
    }

    private function clock($worker, string $kind, string $when, array $meta = [])
    {
        return $this->asCompany($this->company, fn () => app(Attendance::class)->clock($worker, $kind, $this->at($when), $meta['method'] ?? 'kiosk', $meta, $this->supervisor));
    }

    private function shiftFor($farm, $worker, string $start, string $end, int $break = 30): FarmShift
    {
        return $this->asCompany($this->company, function () use ($farm, $worker, $start, $end, $break) {
            $r = app(Rostering::class);
            $s = $r->createShift($farm, ['title' => 'Picking', 'starts_at' => $this->at($start), 'ends_at' => $this->at($end), 'break_minutes' => $break], $this->manager);
            $r->assign($s, $worker, $this->manager, true);

            return $s;
        });
    }

    public function test_LAB_001_a_rotating_weekly_roster_puts_every_shift_on_the_right_date_and_worker(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $this->rules();
        $this->template('DAY', '06:00', '14:00');
        $this->template('NIGHT', '22:00', '06:00');
        $a = $this->worker($farm, 'Anna');
        $b = $this->worker($farm, 'Ben');
        $monday = today()->addWeek()->startOfWeek();

        $this->actingAs($this->manager)->post(route('attendance.roster.rotate'), [
            'farm_id' => $farm->uuid, 'cycle' => ['DAY', 'DAY', 'NIGHT', 'NIGHT', 'OFF', 'OFF', 'OFF'], 'from' => $monday->toDateString(), 'days' => 14,
            'workers' => [['id' => $a->id, 'offset' => 0], ['id' => $b->id, 'offset' => 2]],
        ])->assertSessionHasNoErrors();

        $rows = FarmShiftAssignment::withoutGlobalScopes()->join('attendance_rostered_shifts as s', 's.id', '=', 'attendance_shift_assignments.shift_id')
            ->orderBy('s.starts_at')->get(['attendance_shift_assignments.worker_id', 's.starts_at', 's.ends_at']);
        // Stored in UTC; read on the farm's clock.
        $farmTime = fn ($raw) => Carbon::parse($raw, 'UTC')->setTimezone('Africa/Johannesburg');
        $of = fn ($w) => $rows->where('worker_id', $w->id)->map(fn ($r) => $farmTime($r->starts_at)->format('Y-m-d H:i'))->values()->all();

        // Anna: Mon, Tue days; Wed, Thu nights; then three off — twice.
        $this->assertSame([
            $monday->copy()->format('Y-m-d').' 06:00', $monday->copy()->addDay()->format('Y-m-d').' 06:00',
            $monday->copy()->addDays(2)->format('Y-m-d').' 22:00', $monday->copy()->addDays(3)->format('Y-m-d').' 22:00',
            $monday->copy()->addDays(7)->format('Y-m-d').' 06:00', $monday->copy()->addDays(8)->format('Y-m-d').' 06:00',
            $monday->copy()->addDays(9)->format('Y-m-d').' 22:00', $monday->copy()->addDays(10)->format('Y-m-d').' 22:00',
        ], $of($a));
        // Ben starts two steps into the cycle: nights first.
        $this->assertSame($monday->copy()->format('Y-m-d').' 22:00', $of($b)[0]);
        $this->assertCount(8, $of($b));
        // A night shift ends the next morning.
        $night = $rows->first(fn ($r) => $farmTime($r->starts_at)->format('H:i') === '22:00');
        $this->assertSame($farmTime($night->starts_at)->addDay()->format('Y-m-d').' 06:00', $farmTime($night->ends_at)->format('Y-m-d H:i'));
    }

    public function test_LAB_002_overlapping_shifts_are_blocked_or_warned_by_policy(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $w = $this->worker($farm, 'Anna');
        $this->rules(['overlap_policy' => 'block']);
        $day = today()->addDays(3)->toDateString();
        $first = $this->shiftFor($farm, $w, "$day 06:00", "$day 14:00");
        $second = $this->asCompany($this->company, fn () => app(Rostering::class)->createShift($farm, ['title' => 'Packing', 'starts_at' => $this->at("$day 12:00"),
            'ends_at' => $this->at("$day 18:00")], $this->manager));

        $this->actingAs($this->manager)->post(route('attendance.roster.shifts.assign', $second->uuid), ['worker_ids' => [$w->id], 'accept_warnings' => true])
            ->assertSessionHasErrors('worker_id');

        $this->rules(['overlap_policy' => 'warn']);
        $this->actingAs($this->manager)->post(route('attendance.roster.shifts.assign', $second->uuid), ['worker_ids' => [$w->id]])->assertSessionHasErrors('warnings');
        $this->actingAs($this->manager)->post(route('attendance.roster.shifts.assign', $second->uuid), ['worker_ids' => [$w->id], 'accept_warnings' => true])
            ->assertSessionHasNoErrors();
        $this->assertStringContainsString('Overlaps', FarmShiftAssignment::withoutGlobalScopes()->where('shift_id', $second->id)->value('warnings'));
    }

    public function test_LAB_003_clocking_in_after_the_shift_starts_counts_the_lateness(): void
    {
        $farm = $this->farm('NORTH');
        $w = $this->worker($farm, 'Anna');
        $this->rules(['late_grace_minutes' => 5]);
        $d = today()->subDay()->toDateString();
        $this->shiftFor($farm, $w, "$d 07:00", "$d 15:00");

        $this->clock($w, 'in', "$d 07:20");
        $this->clock($w, 'out', "$d 15:00");

        $t = FarmTimesheet::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(20, $t->late_minutes);
        $this->assertContains('late', $t->flags);
        $this->assertSame(430, $t->worked_minutes); // 07:20–15:00 is 460 minutes, less the 30-minute unpaid break
    }

    public function test_LAB_004_an_overnight_shift_is_split_across_its_two_dates(): void
    {
        $farm = $this->farm('NORTH');
        $w = $this->worker($farm, 'Anna');
        $this->rules();
        $sat = today()->previous(Carbon::SATURDAY);
        $sun = $sat->copy()->addDay();
        $this->shiftFor($farm, $w, $sat->format('Y-m-d').' 22:00', $sun->format('Y-m-d').' 06:00');

        $this->clock($w, 'in', $sat->format('Y-m-d').' 22:00');
        $this->clock($w, 'out', $sun->format('Y-m-d').' 06:00');

        $t = FarmTimesheet::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(450, $t->worked_minutes);
        $this->assertSame($sat->toDateString(), $t->work_date->toDateString(), 'The shift belongs to the day it started.');
        $this->assertSame(450, array_sum($t->minutes_by_date));
        $this->assertEqualsWithDelta(112, $t->minutes_by_date[$sat->toDateString()], 1);
        $this->assertEqualsWithDelta(338, $t->minutes_by_date[$sun->toDateString()], 1);
        $this->assertSame($t->minutes_by_date[$sun->toDateString()], $t->sunday_minutes, 'Only the Sunday part is Sunday time.');
        $this->assertGreaterThan(0, $t->night_minutes);
    }

    public function test_LAB_005_attendance_captured_offline_syncs_once_however_often_it_is_sent(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->worker, $farm, 'worker');
        $me = $this->worker($farm, 'Sipho', $this->worker);
        $d = today()->subDay()->toDateString();
        $ops = [
            ['client_id' => (string) Str::uuid(), 'op' => 'clock.in', 'captured_at' => "{$d}T07:01:00", 'payload' => ['event_id' => (string) Str::uuid()]],
            ['client_id' => (string) Str::uuid(), 'op' => 'clock.out', 'captured_at' => "{$d}T15:02:00", 'payload' => ['event_id' => (string) Str::uuid()]],
        ];

        $first = $this->actingAs($this->worker)->postJson(route('agri.my-work.sync'), ['ops' => $ops])->assertOk()->json('results');
        $again = $this->actingAs($this->worker)->postJson(route('agri.my-work.sync'), ['ops' => $ops])->assertOk()->json('results');

        $this->assertSame(['applied', 'applied'], array_column($first, 'status'));
        $this->assertSame([true, true], array_column($again, 'duplicate'));
        $this->assertSame(2, FarmClockEvent::withoutGlobalScopes()->where('worker_id', $me->id)->count());
        $t = FarmTimesheet::withoutGlobalScopes()->where('worker_id', $me->id)->sole();
        $this->assertContains('needs_confirmation', $t->flags, 'Offline time waits for a supervisor.');
    }

    public function test_LAB_006_an_approved_correction_keeps_the_original_clock_out_on_record(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $this->member($this->supervisor, $farm, 'supervisor');
        $w = $this->worker($farm, 'Anna');
        $d = today()->subDay()->toDateString();
        $this->clock($w, 'in', "$d 07:00");
        $wrong = $this->clock($w, 'out', "$d 09:00");
        $t = FarmTimesheet::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($this->supervisor)->post(route('attendance.timesheets.corrections.store', $t->uuid), ['field' => 'clock_out', 'corrected_at' => "$d 16:00",
            'reason' => 'Pressed out instead of break'])->assertSessionHasNoErrors();
        $c = \App\Modules\HR\Hr\Attendance\Models\Correction::withoutGlobalScopes()->firstOrFail();
        $this->actingAs($this->supervisor)->post(route('attendance.timesheets.corrections.review', $c->uuid), ['approve' => true])->assertSessionHasErrors('correction');
        $this->actingAs($this->manager)->post(route('attendance.timesheets.corrections.review', $c->uuid), ['approve' => true])->assertSessionHasNoErrors();

        $t->refresh();
        $this->assertSame('16:00', $t->clock_out_at->format('H:i'));
        $original = FarmClockEvent::withoutGlobalScopes()->find($wrong->id);
        $this->assertNotNull($original, 'The original event is never deleted.');
        $this->assertSame('09:00', $original->occurred_at->format('H:i'));
        $this->assertNotNull($original->superseded_by_id);
        $this->assertSame('correction', FarmClockEvent::withoutGlobalScopes()->find($original->superseded_by_id)->method);
    }

    private function approvedRate(string $activity = 'harvesting', string $unit = 'kg', float $rate = 1.20): PieceRate
    {
        return $this->asCompany($this->company, function () use ($activity, $unit, $rate) {
            $r = PieceRate::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'activity' => $activity, 'unit' => $unit, 'rate' => $rate,
                'valid_from' => today()->subMonth(), 'status' => 'draft']);
            app(\App\Modules\HR\Hr\Attendance\Services\Piecework::class)->approveRate($r, $this->admin);

            return $r;
        });
    }

    public function test_LAB_007_piecework_earnings_use_the_approved_rate_frozen_at_approval(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->supervisor, $farm, 'supervisor');
        $w = $this->worker($farm, 'Anna');
        $rate = $this->approvedRate(rate: 1.20);

        $this->actingAs($this->supervisor)->post(route('attendance.piecework.store'), ['farm_id' => $farm->uuid, 'work_date' => today()->toDateString(),
            'activity' => 'harvesting', 'unit' => 'kg', 'worker_id' => $w->id, 'measured_quantity' => 250])->assertSessionHasNoErrors();
        $e = PieceworkEntry::withoutGlobalScopes()->firstOrFail();
        $this->actingAs($this->supervisor)->post(route('attendance.piecework.review', $e->uuid), ['action' => 'approve', 'approved_quantity' => 250])->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(300.00, PieceworkEntry::withoutGlobalScopes()->find($e->id)->amount, 0.001);
        $rate->update(['rate' => 2.00]);
        $this->assertEqualsWithDelta(300.00, PieceworkEntry::withoutGlobalScopes()->find($e->id)->amount, 0.001, 'A later rate never re-prices approved work.');
    }

    public function test_LAB_008_unverified_and_rejected_quantities_earn_nothing(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->supervisor, $farm, 'supervisor');
        $w = $this->worker($farm, 'Anna');
        $this->approvedRate(rate: 1.00);
        $submit = fn ($q) => $this->actingAs($this->supervisor)->post(route('attendance.piecework.store'), ['farm_id' => $farm->uuid, 'work_date' => today()->toDateString(),
            'activity' => 'harvesting', 'unit' => 'kg', 'worker_id' => $w->id, 'measured_quantity' => $q])->assertSessionHasNoErrors();
        $submit(300);
        $submit(80);
        [$a, $b] = PieceworkEntry::withoutGlobalScopes()->orderBy('id')->get()->all();

        $this->actingAs($this->supervisor)->post(route('attendance.piecework.review', $a->uuid), ['action' => 'approve', 'approved_quantity' => 260])->assertSessionHasNoErrors();
        $this->actingAs($this->supervisor)->post(route('attendance.piecework.review', $b->uuid), ['action' => 'reject', 'note' => 'Not weighed'])->assertSessionHasNoErrors();

        $a->refresh();
        $this->assertEqualsWithDelta(40, $a->rejected_quantity, 0.001);
        $this->assertEqualsWithDelta(260.00, $a->amount, 0.001);
        $this->assertEqualsWithDelta(0, $b->fresh()->amount, 0.001);
        $this->assertEqualsWithDelta(260.00, (float) PieceworkEntry::withoutGlobalScopes()->where('status', 'approved')->sum('amount'), 0.001);
    }

    public function test_LAB_009_approved_hours_and_piecework_reach_payroll_once_and_reconcile(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->supervisor, $farm, 'supervisor');
        $this->worker->givePermissionTo(RoleCatalog::PAYROLL_HANDOFF);
        $this->admin->givePermissionTo(RoleCatalog::PAYROLL_HANDOFF);
        $login = $this->makeUser('picker@boplaas.test', null, $this->company);
        $w = $this->worker($farm, 'Anna', $login);
        $this->rules();
        $this->approvedRate(rate: 2.00);
        $d = today()->subDays(2)->toDateString();
        $this->clock($w, 'in', "$d 07:00");
        $this->clock($w, 'out', "$d 16:00");
        $t = FarmTimesheet::withoutGlobalScopes()->firstOrFail();
        $this->actingAs($this->admin)->post(route('attendance.timesheets.decide', $t->uuid), ['action' => 'approve'])->assertSessionHasNoErrors();
        $this->asCompany($this->company, function () use ($farm, $w, $d) {
            $e = app(\App\Modules\HR\Hr\Attendance\Services\Piecework::class)->submit($farm, ['work_date' => $d, 'activity' => 'harvesting', 'unit' => 'kg',
                'worker_id' => $w->id, 'measured_quantity' => 100], $this->supervisor);
            app(\App\Modules\HR\Hr\Attendance\Services\Piecework::class)->approve($e, 100, 0, null, $this->supervisor);
        });
        // An open in-house payroll run with a payslip for this person.
        $runId = DB::table('payroll_runs')->insertGetId(['company_id' => $this->company->id, 'name' => 'Run', 'period_start' => today()->startOfMonth()->subMonth(),
            'period_end' => today()->endOfMonth(), 'status' => 'draft', 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        $slip = DB::table('payslips')->insertGetId(['company_id' => $this->company->id, 'payroll_run_id' => $runId, 'employee_id' => $login->id,
            'period_start' => today()->startOfMonth()->subMonth(), 'period_end' => today()->endOfMonth(), 'created_at' => now(), 'updated_at' => now()]);

        $period = ['period_start' => today()->subWeek()->toDateString(), 'period_end' => today()->toDateString()];
        $this->actingAs($this->admin)->post(route('attendance.payroll-handoff.prepare'), $period)->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('attendance.payroll-handoff.prepare'), $period)->assertSessionHasErrors('period_start'); // nothing left: once

        $h = \App\Modules\HR\Hr\Attendance\Models\PayrollHandoff::withoutGlobalScopes()->sole();
        $this->actingAs($this->admin)->post(route('attendance.payroll-handoff.send', $h->uuid))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('attendance.payroll-handoff.send', $h->uuid))->assertSessionHasNoErrors(); // resend replaces, never adds

        $this->assertSame(1, DB::table('payroll_earnings_lines')->where('payslip_id', $slip)->where('line_type', 'piecework')->count());
        $this->assertEqualsWithDelta(200.00, (float) DB::table('payroll_earnings_lines')->where('payslip_id', $slip)->value('amount'), 0.001);
        $att = DB::table('attendance_records')->where('employee_id', $w->hr_person_id)->sole();
        $this->assertSame('timesheets', $att->source);
        $this->assertEqualsWithDelta(8.5, (float) $att->hours_worked, 0.01);   // 9 h less the 30-minute break
        $line = $h->lines()->sole();
        $this->assertSame('delivered', $line->status);
        $this->assertSame($line->regular_minutes + $line->overtime_minutes, $line->delivered_minutes);
        $this->actingAs($this->admin)->post(route('attendance.payroll-handoff.reconcile', $h->uuid))->assertSessionHasNoErrors();
        $this->assertSame('reconciled', $h->fresh()->status);
    }

    public function test_LAB_010_changing_a_published_roster_keeps_the_original_and_the_revision(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $w = $this->worker($farm, 'Anna');
        $this->rules();
        $d = today()->addDays(2)->toDateString();
        $s = $this->shiftFor($farm, $w, "$d 06:00", "$d 14:00");
        $this->actingAs($this->manager)->post(route('attendance.roster.publish'), ['farm_id' => $farm->uuid, 'from' => $d, 'days' => 1])->assertSessionHasNoErrors();

        $this->actingAs($this->manager)->patch(route('attendance.roster.shifts.update', $s->uuid), ['starts_at' => "$d 07:00", 'ends_at' => "$d 15:00"])
            ->assertSessionHasErrors('reason');
        $this->actingAs($this->manager)->patch(route('attendance.roster.shifts.update', $s->uuid), ['starts_at' => "$d 07:00", 'ends_at' => "$d 15:00", 'reason' => 'Rain delay'])
            ->assertSessionHasNoErrors();

        $change = FarmShiftChange::withoutGlobalScopes()->where('shift_id', $s->id)->sole();
        $this->assertSame(1, $change->revision);
        $this->assertSame('06:00', Carbon::parse($change->before['starts_at'])->format('H:i'));
        $this->assertSame([$w->id], $change->before['workers']);
        $this->assertSame('07:00', $s->fresh()->starts_at->format('H:i'));
    }

    public function test_LAB_011_an_uncertified_worker_is_blocked_from_restricted_work(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $this->rules(['certification_policy' => 'block']);
        $uncert = $this->worker($farm, 'Anna');
        $cert = $this->worker($farm, 'Ben');
        $this->competency($cert, 'PCO');
        $d = today()->addDays(2)->toDateString();
        $spray = $this->asCompany($this->company, fn () => app(Rostering::class)->createShift($farm, ['title' => 'Spraying', 'starts_at' => $this->at("$d 06:00"),
            'ends_at' => $this->at("$d 12:00"), 'required_certifications' => ['PCO']], $this->manager));

        $this->actingAs($this->manager)->post(route('attendance.roster.shifts.assign', $spray->uuid), ['worker_ids' => [$uncert->id], 'accept_warnings' => true])
            ->assertSessionHasErrors('worker_id');
        $this->actingAs($this->manager)->post(route('attendance.roster.shifts.assign', $spray->uuid), ['worker_ids' => [$cert->id]])->assertSessionHasNoErrors();

        // An expired certificate counts as none.
        $late = $this->worker($farm, 'Cara');
        $this->competency($late, 'PCO', today()->subDay()->toDateString());
        $this->actingAs($this->manager)->post(route('attendance.roster.shifts.assign', $spray->uuid), ['worker_ids' => [$late->id], 'accept_warnings' => true])
            ->assertSessionHasErrors('worker_id');
    }

    public function test_LAB_012_crop_labour_cost_reconciles_to_approved_records_and_rates(): void
    {
        $this->openBooks();
        $this->catalogue();
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $field = $this->field($farm);
        $plan = $this->asCompany($this->company, fn () => \App\Modules\Agri\Models\CropPlan::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'farm_id' => $farm->id, 'field_id' => $field->id, 'season_id' => $this->seasonId(), 'crop_type_id' => $this->cropTypeId(),
            'code' => 'MZ', 'planned_area' => 40, 'status' => 'planted', 'planned_planting_on' => today()->subMonth(), 'planned_harvest_on' => today()->addMonths(3)]));
        $wo = $this->asCompany($this->company, fn () => app(\App\Modules\Agri\Services\WorkOrders::class)->create($farm, ['category' => 'weeding', 'title' => 'Weed MZ',
            'priority' => 'normal', 'crop_plan_id' => $plan->id, 'field_id' => $field->id, 'planned_start' => now()->subDays(3), 'planned_end' => now()->subDays(2)], $this->manager));
        $this->rules();
        $a = $this->worker($farm, 'Anna', null, ['hourly_cost_rate' => 30]);
        $b = $this->worker($farm, 'Ben', null, ['hourly_cost_rate' => 40]);
        // An ordinary weekday: Sunday and public-holiday time is costed at a higher rate.
        $d = today()->subDays(2)->isWeekday() ? today()->subDays(2)->toDateString() : today()->previous(\Carbon\Carbon::WEDNESDAY)->toDateString();
        foreach ([$a, $b] as $w) {
            $s = $this->asCompany($this->company, fn () => app(Rostering::class)->createShift($farm, ['title' => 'Weeding', 'starts_at' => $this->at("$d 07:00"),
                'ends_at' => $this->at("$d 15:30"), 'work_order_id' => $wo->id], $this->manager));
            $this->asCompany($this->company, fn () => app(Rostering::class)->assign($s, $w, $this->manager, true));
            $this->clock($w, 'in', "$d 07:00");
            $this->clock($w, 'out', "$d 15:30");
        }
        foreach (FarmTimesheet::withoutGlobalScopes()->get() as $t) {
            $this->actingAs($this->admin)->post(route('attendance.timesheets.decide', $t->uuid), ['action' => 'approve'])->assertSessionHasNoErrors();
        }
        $this->approvedRate('weeding', 'row', 5.0);
        $this->asCompany($this->company, function () use ($farm, $a, $d, $plan, $wo) {
            $e = app(\App\Modules\HR\Hr\Attendance\Services\Piecework::class)->submit($farm, ['work_date' => $d, 'activity' => 'weeding', 'unit' => 'row', 'worker_id' => $a->id,
                'measured_quantity' => 20, 'crop_plan_id' => $plan->id, 'work_order_id' => $wo->id], $this->manager);
            app(\App\Modules\HR\Hr\Attendance\Services\Piecework::class)->approve($e, 20, 0, null, $this->admin);
        });

        $sheets = FarmTimesheet::withoutGlobalScopes()->get();
        $this->assertTrue($sheets->every(fn ($t) => (int) $t->crop_plan_id === $plan->id), 'Time on the job lands on its crop.');
        $expected = round((float) $sheets->sum('cost_amount') + 100.0, 2);   // 8 h × 30 + 8 h × 40 = 560, plus 20 rows × 5
        $this->assertEqualsWithDelta(660.0, $expected, 0.01);
        $report = $this->asCompany($this->company, fn () => app(LabourCosting::class)->forFarm($farm, today()->subWeek(), today()));
        $this->assertEqualsWithDelta($expected, $report['by_enterprise']['crop_plan:'.$plan->id], 0.01);
        $this->assertEqualsWithDelta($expected, $report['totals']['total'], 0.01);
        $row = collect($report['rows'])->firstWhere('enterprise', 'crop_plan:'.$plan->id);
        $this->assertEqualsWithDelta(16.0, $row['hours'], 0.01);
    }
}
