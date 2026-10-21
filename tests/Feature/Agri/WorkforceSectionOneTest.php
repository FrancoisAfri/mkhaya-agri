<?php

namespace Tests\Feature\Agri;

use App\Modules\HR\Hr\Attendance\Models\ClockEvent as FarmClockEvent;
use App\Modules\HR\Hr\Attendance\Models\Crew as FarmCrew;
use App\Modules\HR\Hr\Attendance\Models\RosteredShift as FarmShift;
use App\Modules\HR\Hr\Attendance\Models\ShiftAssignment as FarmShiftAssignment;
use App\Modules\HR\Hr\Attendance\Models\Timesheet as FarmTimesheet;
use App\Modules\HR\Hr\Attendance\Models\PieceRate;
use App\Modules\HR\Hr\Attendance\Models\RosterPattern;
use App\Modules\Agri\Notifications\FarmWorkNotice;
use App\Modules\HR\Hr\Attendance\Services\Attendance;
use App\Modules\HR\Hr\Attendance\Services\Piecework;
use App\Modules\HR\Hr\Attendance\Services\Rostering;
use App\Modules\Agri\Services\WorkforceAnalytics;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Phase 2 §1, the parts beyond the LAB acceptance tests: workforce analytics (§1.7), whole-crew
 * assignment and season patterns (§1.2), clock devices (§1.3) and the cover and overtime notices.
 */
class WorkforceSectionOneTest extends AgriTestCase
{
    private function crew($farm, string $code, array $workers): FarmCrew
    {
        $crew = $this->asCompany($this->company, fn () => FarmCrew::withoutGlobalScopes()->create(['company_id' => $farm->company_id, 'farm_id' => $farm->id, 'code' => $code, 'name' => "Crew {$code}"]));
        foreach ($workers as $w) {
            $w->update(['crew_id' => $crew->id]);
        }

        return $crew;
    }

    private function shift($farm, string $start, string $end, int $min = 1, array $o = []): FarmShift
    {
        return $this->asCompany($this->company, fn () => app(Rostering::class)->createShift($farm, $o + ['title' => 'Picking', 'starts_at' => Carbon::parse($start),
            'ends_at' => Carbon::parse($end), 'break_minutes' => 30, 'min_staff' => $min], $this->manager));
    }

    public function test_analytics_reconcile_to_approved_records_and_show_missed_shifts(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $this->rules();
        [$a, $b] = [$this->worker($farm, 'Anna'), $this->worker($farm, 'Ben')];
        $day = today()->subDays(2);
        $shift = $this->shift($farm, $day->copy()->setTime(6, 0), $day->copy()->setTime(14, 30));
        $this->asCompany($this->company, function () use ($shift, $a, $b, $day) {
            $r = app(Rostering::class);
            $r->assign($shift, $a, $this->manager, true);
            $r->assign($shift, $b, $this->manager, true);
            $r->publish([$shift], $this->manager);
            // Anna works it; Ben never comes.
            $att = app(Attendance::class);
            $att->clock($a, 'in', $day->copy()->setTime(6, 0), 'kiosk', [], $this->supervisor);
            $att->clock($a, 'out', $day->copy()->setTime(14, 30), 'kiosk', [], $this->supervisor);
            $att->approve(FarmTimesheet::withoutGlobalScopes()->where('worker_id', $a->id)->firstOrFail(), $this->manager);
        });

        $r = $this->asCompany($this->company, fn () => app(WorkforceAnalytics::class)->report($farm, today()->subDays(6), today()));
        $sheet = FarmTimesheet::withoutGlobalScopes()->where('worker_id', $a->id)->firstOrFail();
        $this->assertSame(8.0, (float) $r['totals']['actual_hours']);
        $this->assertSame(16.0, (float) $r['totals']['planned_hours']);
        $this->assertSame(round((float) $sheet->cost_amount, 2), (float) $r['totals']['labour_cost']);
        $this->assertSame(1, $r['totals']['shifts_missed']);
        $this->assertSame('Ben Test', $r['absence'][0]['worker']);

        $this->actingAs($this->manager)->get(route('agri.workforce.analytics', ['farm' => $farm->uuid]))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Modules/Agri/Workforce/Analytics')->where('report.totals.shifts_missed', 1));
    }

    public function test_productivity_compares_only_like_with_like_and_needs_three_people(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $people = collect(['Anna', 'Ben', 'Cara'])->map(fn ($n) => $this->worker($farm, $n));
        $this->asCompany($this->company, function () use ($farm, $people) {
            $pw = app(Piecework::class);
            $rate = PieceRate::withoutGlobalScopes()->create(['company_id' => $farm->company_id, 'farm_id' => $farm->id, 'activity' => 'harvesting', 'unit' => 'crate',
                'rate' => 5, 'valid_from' => today()->subMonth(), 'status' => 'draft']);
            $pw->approveRate($rate, $this->admin);
            foreach ([[60, 80, 100], [50, 50, 50]] as $i => $qty) {
                foreach ($people as $k => $w) {
                    $e = $pw->submit($farm, ['work_date' => today()->subDays($i + 1)->toDateString(), 'activity' => 'harvesting', 'unit' => 'crate',
                        'measured_quantity' => $qty[$k], 'worker_id' => $w->id], $this->supervisor);
                    $pw->approve($e, $qty[$k], 0, null, $this->manager);
                }
            }
            // Two people weeding on another day: too few to compare, so it says nothing.
            $pw->approveRate(PieceRate::withoutGlobalScopes()->create(['company_id' => $farm->company_id, 'farm_id' => $farm->id, 'activity' => 'weeding',
                'unit' => 'row', 'rate' => 3, 'valid_from' => today()->subMonth(), 'status' => 'draft']), $this->admin);
            foreach ($people->take(2) as $w) {
                $e = $pw->submit($farm, ['work_date' => today()->subDays(3)->toDateString(), 'activity' => 'weeding', 'unit' => 'row',
                    'measured_quantity' => 10, 'worker_id' => $w->id], $this->supervisor);
                $pw->approve($e, 10, 0, null, $this->manager);
            }
        });

        $rows = collect($this->asCompany($this->company, fn () => app(WorkforceAnalytics::class)->productivity($farm, today()->subDays(7), today())))->keyBy('worker');
        // Day 1: median 80 → 75, 100, 125. Day 2: everyone 100. Weeding left out.
        $this->assertSame(2, $rows['Anna Test']['comparisons']);
        $this->assertEquals(88, $rows['Anna Test']['index']);   // (75 + 100) / 2, rounded
        $this->assertEquals(113, $rows['Cara Test']['index']);  // (125 + 100) / 2, rounded
    }

    public function test_demand_sets_planned_job_hours_against_rostered_hours(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $w = $this->worker($farm, 'Anna');
        $day = today()->addDays(2);
        $shift = $this->shift($farm, $day->copy()->setTime(6, 0), $day->copy()->setTime(14, 30), 3);
        $this->asCompany($this->company, fn () => app(Rostering::class)->assign($shift, $w, $this->manager, true));
        $this->asCompany($this->company, fn () => app(\App\Modules\Agri\Services\WorkOrders::class)->create($farm, ['category' => 'weeding', 'title' => 'Weed L1',
            'priority' => 'normal', 'planned_start' => $day->copy()->setTime(6, 0), 'planned_end' => $day->copy()->setTime(16, 0), 'estimated_hours' => 24], $this->manager));

        $d = collect($this->asCompany($this->company, fn () => app(WorkforceAnalytics::class)->demand($farm, today(), today()->addDays(6))))->firstWhere('date', $day->toDateString());
        $this->assertSame(8.0, (float) $d['rostered_hours']);
        $this->assertSame(24.0, (float) $d['job_hours']);
        $this->assertSame(16.0, (float) $d['gap_hours']);
        $this->assertSame(2, $d['short']);
    }

    public function test_a_whole_crew_is_assigned_at_once_and_a_season_pattern_drafts_its_roster(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $this->rules();
        $this->template('DAY', '06:00', '14:00');
        $people = collect(['Anna', 'Ben', 'Cara'])->map(fn ($n) => $this->worker($farm, $n));
        $crew = $this->crew($farm, 'PICK', $people->all());
        $shift = $this->shift($farm, today()->addDays(3)->setTime(6, 0), today()->addDays(3)->setTime(14, 0), 3);

        $this->actingAs($this->manager)->post(route('attendance.roster.shifts.assign', $shift->uuid), ['crew_id' => $crew->uuid, 'accept_warnings' => true])
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame(3, FarmShiftAssignment::withoutGlobalScopes()->where('shift_id', $shift->id)->where('status', 'assigned')->count());

        $this->actingAs($this->manager)->post(route('attendance.roster.patterns.store'), ['farm_id' => $farm->uuid, 'name' => 'Harvest crew PICK', 'season' => 'Harvest',
            'cycle' => ['DAY', 'DAY', 'OFF'], 'crew_id' => $crew->uuid])->assertSessionHasNoErrors();
        $pattern = RosterPattern::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(['DAY', 'DAY', 'OFF'], $pattern->cycle);

        $from = today()->addWeeks(2)->startOfWeek();
        $this->actingAs($this->manager)->post(route('attendance.roster.patterns.apply', $pattern->uuid), ['from' => $from->toDateString(), 'days' => 6])->assertSessionHasNoErrors();
        // Six days of DAY, DAY, OFF = four working days, the whole crew on each.
        $made = FarmShift::withoutGlobalScopes()->where('starts_at', '>=', $from)->get();
        $this->assertCount(4, $made);
        $this->assertSame(12, FarmShiftAssignment::withoutGlobalScopes()->whereIn('shift_id', $made->pluck('id'))->count());
        $this->assertTrue($made->every(fn ($s) => $s->status === FarmShift::DRAFT));
    }

    public function test_a_clock_device_sends_events_once_with_its_own_secret_and_biometrics_need_consent(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->admin, $farm);
        $anna = $this->worker($farm, 'Anna', null, ['biometric_allowed' => true]);
        $ben = $this->worker($farm, 'Ben');

        $issued = $this->actingAs($this->admin)->postJson(route('attendance.time-settings.devices.store'), ['farm_id' => $farm->uuid, 'name' => 'Main gate', 'kind' => 'biometric'])
            ->assertOk()->json();
        $this->assertStringStartsWith('agd_', $issued['secret']);
        $url = route('api.attendance.devices.clock', $issued['id']);
        $in = (string) Str::uuid();
        $events = ['events' => [
            ['event_id' => $in, 'badge' => $anna->badge_token, 'kind' => 'auto', 'occurred_at' => now()->subHours(2)->toIso8601String()],
            ['event_id' => (string) Str::uuid(), 'badge' => $ben->badge_token, 'kind' => 'auto', 'occurred_at' => now()->subHours(2)->toIso8601String()],
        ]];

        $this->postJson($url, $events, ['Authorization' => 'Bearer wrong'])->assertStatus(401);
        $r = $this->postJson($url, $events, ['Authorization' => 'Bearer '.$issued['secret']])->assertOk()->json('results');
        $this->assertSame('applied', $r[0]['status']);
        $this->assertSame('rejected', $r[1]['status']);   // Ben gave no biometric consent
        $this->assertStringContainsString('consent', $r[1]['message']);

        // The same batch again changes nothing; the next tap is a clock-out.
        $this->postJson($url, $events, ['Authorization' => 'Bearer '.$issued['secret']])->assertOk();
        $this->assertSame(1, FarmClockEvent::withoutGlobalScopes()->where('worker_id', $anna->id)->count());
        $this->postJson($url, ['events' => [['event_id' => (string) Str::uuid(), 'badge' => $anna->badge_token, 'occurred_at' => now()->subMinutes(5)->toIso8601String()]]],
            ['Authorization' => 'Bearer '.$issued['secret']])->assertOk();
        $this->assertSame(['clock_in', 'clock_out'], FarmClockEvent::withoutGlobalScopes()->where('worker_id', $anna->id)->orderBy('occurred_at')->pluck('type')->all(), 'In T&A\'s clock log.');
        $this->assertSame('biometric', FarmClockEvent::withoutGlobalScopes()->where('worker_id', $anna->id)->value('method'));
    }

    public function test_managers_are_told_when_rostered_shifts_need_cover_and_workers_about_their_overtime(): void
    {
        Notification::fake();
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $this->member($this->supervisor, $farm, 'supervisor');
        $this->rules();
        $w = $this->worker($farm, 'Anna', $this->worker);
        $shift = $this->shift($farm, today()->addDays(2)->setTime(6, 0), today()->addDays(2)->setTime(14, 0));
        $this->asCompany($this->company, function () use ($shift, $w) {
            app(Rostering::class)->assign($shift, $w, $this->manager, true);
            app(Rostering::class)->publish([$shift], $this->manager);
        });
        Notification::fake();

        $this->actingAs($this->manager)->post(route('attendance.people.unavailable', $w->uuid), ['starts_at' => today()->addDays(2)->toDateString().' 00:00',
            'ends_at' => today()->addDays(3)->toDateString().' 00:00', 'reason' => 'Clinic'])->assertSessionHasNoErrors();
        Notification::assertSentTo([$this->manager, $this->supervisor], FarmWorkNotice::class);

        $this->actingAs($this->supervisor)->post(route('attendance.timesheets.overtime.store'), ['worker_id' => $w->id, 'work_date' => today()->toDateString(),
            'hours' => 2, 'reason' => 'Finish loading'])->assertSessionHasNoErrors();
        Notification::assertSentTo($this->worker, FarmWorkNotice::class, fn ($n) => str_contains($n->text, 'Overtime'));
    }
}
