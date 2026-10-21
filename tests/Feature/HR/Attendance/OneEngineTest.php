<?php

namespace Tests\Feature\HR\Attendance;

use App\Core\Role\Models\Role;
use App\Modules\HR\Hr\Attendance\Models\ClockEvent;
use App\Modules\HR\Hr\Attendance\Models\RosteredShift;
use App\Modules\HR\Hr\Attendance\Models\Timesheet;
use App\Modules\HR\Hr\Attendance\Models\Worker;
use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Hr\Services\AttendanceClockingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Agri\AgriTestCase;

/**
 * One time-and-attendance engine for everybody: an office employee clocking in through T&A gets a
 * profile and timesheet sessions like a farm worker; approving a session writes the day's record
 * payroll reads; the organisation's own roster runs on the same shifts; and who sees whose time is
 * the engine's — T&A staff see everyone, a farm manager only their farms' people.
 */
class OneEngineTest extends AgriTestCase
{
    private $hr;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['attendance.records.view', 'attendance.records.edit', 'attendance.records.approve', 'attendance.roster.view', 'attendance.roster.manage', 'attendance.settings.edit'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $role = Role::firstOrCreate(['name' => 'ta test manager', 'guard_name' => 'web']);
        $role->syncPermissions(['attendance.records.view', 'attendance.records.edit', 'attendance.records.approve', 'attendance.roster.view', 'attendance.roster.manage', 'attendance.settings.edit']);
        $this->hr = $this->makeUser('hr@boplaas.test', $role, $this->company);
    }

    private function officePerson(string $first = 'Thandi'): HRPerson
    {
        return $this->asCompany($this->company, function () use ($first) {
            $p = new HRPerson(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'first_name' => $first, 'surname' => 'Office', 'status' => 1, 'employee_number' => 'O'.random_int(1000, 9999)]);
            $p->company_id = $this->company->id;
            $p->save();

            return $p;
        });
    }

    public function test_an_office_clocking_runs_on_the_engine_and_its_approved_session_is_the_day_payroll_reads(): void
    {
        $person = $this->officePerson();
        $clock = app(AttendanceClockingService::class);
        $day = Carbon::parse('2026-10-20 08:00', 'Africa/Johannesburg');

        Carbon::setTestNow($day);
        $in = $this->asCompany($this->company, fn () => $clock->clockIn($person->id, 'manual', 'office'));
        Carbon::setTestNow($day->copy()->setTime(16, 30));
        $out = $this->asCompany($this->company, fn () => $clock->clockOut($person->id, 'manual'));
        Carbon::setTestNow();

        // T&A's clock log holds both, now the engine's: a profile with no site, one session.
        $worker = Worker::withoutGlobalScopes()->where('hr_person_id', $person->id)->sole();
        $this->assertNull($worker->farm_id);
        $this->assertFalse($worker->biometric_allowed, 'Consent is never assumed.');
        $this->assertSame([$worker->id, $worker->id], ClockEvent::withoutGlobalScopes()->whereIn('id', [$in->id, $out->id])->pluck('worker_id')->map(fn ($x) => (int) $x)->all());
        $sheet = Timesheet::withoutGlobalScopes()->where('worker_id', $worker->id)->sole();
        $this->assertSame([Timesheet::COMPLETE, (int) $in->id, (int) $out->id], [$sheet->status, (int) $sheet->in_event_id, (int) $sheet->out_event_id]);
        $this->assertSame(480, (int) $sheet->worked_minutes, 'Eight and a half hours less the default rules\' 30-minute unpaid break.');

        // T&A staff see the office worker; a farm manager does not.
        $hrSees = $this->actingAs($this->hr)->get(route('attendance.timesheets.index', ['from' => '2026-10-19', 'to' => '2026-10-21']))->assertOk()->viewData('page')['props'];
        $this->assertContains($sheet->uuid, collect($hrSees['timesheets'] ?? $hrSees['sheets'] ?? [])->pluck('uuid')->all());
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $farmSees = $this->actingAs($this->manager)->get(route('attendance.people.index', ['status' => 'all']))->assertOk()->viewData('page')['props'];
        $this->assertNotContains($worker->id, collect($farmSees['workers'])->pluck('id')->all());
        $this->assertContains($worker->id, collect($this->actingAs($this->hr)->get(route('attendance.people.index', ['status' => 'all']))->viewData('page')['props']['workers'])->pluck('id')->all());

        // Approved: the day's record — the one payroll reads — carries the approved hours.
        $this->actingAs($this->hr)->post(route('attendance.timesheets.decide', $sheet->uuid), ['action' => 'approve'])->assertSessionHasNoErrors();
        $record = DB::table('attendance_records')->where('employee_id', $person->id)->whereDate('date', '2026-10-20')->sole();
        $this->assertEqualsWithDelta(8.0, (float) $record->hours_worked, 0.01);
        $this->assertTrue((bool) $record->manager_approved);
        $this->assertSame($this->hr->id, (int) $record->approved_by);
    }

    public function test_the_organisation_roster_runs_on_the_engine_without_a_farm(): void
    {
        $person = $this->officePerson('Sipho');
        $worker = $this->asCompany($this->company, fn () => app(\App\Modules\HR\Hr\Attendance\Services\Workforce::class)->profileFor($person->id));
        $tpl = $this->template('OFF-DAY', '08:00', '16:30');
        $d = today()->addDays(2)->toDateString();

        $this->actingAs($this->hr)->get(route('attendance.roster.index'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('HR/Attendance/Time/Roster')->where('farm', null));
        $this->actingAs($this->hr)->post(route('attendance.roster.shifts.store'), ['template_id' => $tpl->id, 'date' => $d])->assertSessionHasNoErrors()->assertRedirect();
        $shift = RosteredShift::withoutGlobalScopes()->sole();
        $this->assertNull($shift->farm_id);
        $this->assertSame('08:00', $shift->starts_at->format('H:i'));
        $this->actingAs($this->hr)->post(route('attendance.roster.shifts.assign', $shift->uuid), ['worker_ids' => [$worker->id], 'accept_warnings' => true])->assertSessionHasNoErrors();
        $this->actingAs($this->hr)->post(route('attendance.roster.publish'), ['from' => $d, 'days' => 1])->assertSessionHasNoErrors();
        $this->assertSame(RosteredShift::PUBLISHED, $shift->fresh()->status);
        // A farm manager cannot touch the organisation's roster.
        $this->actingAs($this->manager)->post(route('attendance.roster.shifts.assign', $shift->uuid), ['worker_ids' => [$worker->id]])->assertNotFound();
    }
}
