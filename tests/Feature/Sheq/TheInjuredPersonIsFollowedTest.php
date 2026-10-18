<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Services\InjuryCaseService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The person who got hurt, from the injury to the day they are back on full duty.
 *
 * `days_lost` and `days_restricted` were two numbers somebody typed on the incident, and they do
 * more work than almost anything else here: they decide whether an injury is reportable, they
 * drive the DIFR the board is shown, and they are what a COIDA claim is assessed on. Nothing
 * tracked the person they described, and nothing could answer "who is still off work".
 */
class TheInjuredPersonIsFollowedTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $actorId;

    private int $incidentId;

    private string $incidentUuid;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;

        $this->actorId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'inj-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->incidentUuid = (string) Str::uuid();
        $this->incidentId = DB::table('sheq_incidents')->insertGetId([
            'uuid' => $this->incidentUuid, 'company_id' => $this->companyId,
            'reference' => 'INC-'.Str::random(8),
            'occurred_at' => Carbon::parse('2026-09-01'), 'reported_at' => Carbon::parse('2026-09-01'),
            'incident_type' => 'INJURY', 'description' => 'Crushed hand in a press.',
            'outcome' => 'LOST_TIME', 'actual_severity' => 3, 'potential_severity' => 4,
            'status' => 'REPORTED', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function service(): InjuryCaseService
    {
        return app(InjuryCaseService::class);
    }

    private function openCase(): string
    {
        return $this->service()->open($this->companyId, $this->incidentUuid, [
            'injured_on' => '2026-09-01',
            'diagnosis' => 'Fracture to the left hand.',
        ], $this->actorId)['uuid'];
    }

    // ── The days are counted, not typed ─────────────────────────────────────────────────────────

    public function test_a_day_off_counts_as_a_day(): void
    {
        $case = $this->openCase();

        // Booked off Monday, back Tuesday: that is one lost day, and a plain date difference
        // would call it zero.
        $this->service()->record($this->companyId, $case, [
            'event_type' => 'BOOKED_OFF', 'from_date' => '2026-09-01', 'to_date' => '2026-09-01',
        ], $this->actorId);

        $this->assertSame(1, $this->service()->view($this->companyId, $case)['days_lost']);
    }

    public function test_lost_and_restricted_days_are_counted_apart(): void
    {
        $case = $this->openCase();

        $this->service()->record($this->companyId, $case, [
            'event_type' => 'BOOKED_OFF', 'from_date' => '2026-09-01', 'to_date' => '2026-09-05',
        ], $this->actorId);

        $this->service()->record($this->companyId, $case, [
            'event_type' => 'RESTRICTED_DUTY', 'from_date' => '2026-09-06', 'to_date' => '2026-09-10',
        ], $this->actorId);

        $view = $this->service()->view($this->companyId, $case);

        // Five days off and five on light duty are not ten lost days, and reporting them as ten
        // would misstate the DIFR.
        $this->assertSame(5, $view['days_lost']);
        $this->assertSame(5, $view['days_restricted']);
    }

    public function test_the_incident_is_told_what_the_timeline_says(): void
    {
        $case = $this->openCase();

        $this->service()->record($this->companyId, $case, [
            'event_type' => 'BOOKED_OFF', 'from_date' => '2026-09-01', 'to_date' => '2026-09-14',
        ], $this->actorId);

        $incident = DB::table('sheq_incidents')->where('id', $this->incidentId)->first();

        // The statutory forms and the DIFR read the incident, not the case.
        $this->assertSame(14, (int) $incident->days_lost);
    }

    public function test_an_incident_that_hurt_two_people_sums_both(): void
    {
        $first = $this->service()->open($this->companyId, $this->incidentUuid,
            ['injured_on' => '2026-09-01', 'incident_person_id' => 1], $this->actorId)['uuid'];

        $second = $this->service()->open($this->companyId, $this->incidentUuid,
            ['injured_on' => '2026-09-01', 'incident_person_id' => 2], $this->actorId)['uuid'];

        foreach ([$first, $second] as $case) {
            $this->service()->record($this->companyId, $case, [
                'event_type' => 'BOOKED_OFF', 'from_date' => '2026-09-01', 'to_date' => '2026-09-03',
            ], $this->actorId);
        }

        $this->assertSame(6,
            (int) DB::table('sheq_incidents')->where('id', $this->incidentId)->value('days_lost'),
            "An event that hurt two people reported only one person's lost days.");
    }

    public function test_a_change_in_lost_days_is_published_as_a_compliance_event(): void
    {
        $case = $this->openCase();

        $this->service()->record($this->companyId, $case, [
            'event_type' => 'BOOKED_OFF', 'from_date' => '2026-09-01', 'to_date' => '2026-09-20',
        ], $this->actorId);

        // Lost days are what turn an injury into a reportable one, so the total moving is a
        // compliance event rather than a bookkeeping one.
        $this->assertGreaterThan(0, DB::table('sheq_outbox_messages')
            ->where('company_id', $this->companyId)
            ->where('event_type', 'sheq.injury_case.days_changed')
            ->where('subject_id', $this->incidentId)->count());
    }

    // ── Guards ──────────────────────────────────────────────────────────────────────────────────

    public function test_time_off_before_the_injury_is_refused(): void
    {
        $case = $this->openCase();

        $this->expectExceptionMessageMatches('/cannot start before the injury/i');

        $this->service()->record($this->companyId, $case, [
            'event_type' => 'BOOKED_OFF', 'from_date' => '2026-08-01', 'to_date' => '2026-08-05',
        ], $this->actorId);
    }

    public function test_a_period_cannot_end_before_it_starts(): void
    {
        $case = $this->openCase();

        $this->expectExceptionMessageMatches('/cannot end before it starts/i');

        $this->service()->record($this->companyId, $case, [
            'event_type' => 'BOOKED_OFF', 'from_date' => '2026-09-10', 'to_date' => '2026-09-05',
        ], $this->actorId);
    }

    public function test_a_case_cannot_be_closed_over_somebody_still_off_work(): void
    {
        $case = $this->openCase();

        // No end date: the person has not been recorded as back, and closing would freeze the
        // count at whatever today happens to be.
        $this->service()->record($this->companyId, $case, [
            'event_type' => 'BOOKED_OFF', 'from_date' => '2026-09-01',
        ], $this->actorId);

        $this->expectExceptionMessageMatches('/still recorded as off work/i');

        $this->service()->close($this->companyId, $case, $this->actorId);
    }

    public function test_opening_a_second_case_for_the_same_person_returns_the_first(): void
    {
        $first = $this->openCase();
        $again = $this->service()->open($this->companyId, $this->incidentUuid,
            ['injured_on' => '2026-09-01'], $this->actorId)['uuid'];

        // Two cases for one person means two sets of day counts, and the incident would be told
        // whichever was written last.
        $this->assertSame($first, $again);
    }

    // ── Who is still away ───────────────────────────────────────────────────────────────────────

    public function test_the_module_can_say_who_is_still_off_work(): void
    {
        $case = $this->openCase();

        $this->service()->record($this->companyId, $case, [
            'event_type' => 'BOOKED_OFF', 'from_date' => '2026-09-01',
        ], $this->actorId);

        $away = collect($this->service()->stillAway($this->companyId))->pluck('uuid');

        $this->assertContains($case, $away->all(),
            'The one question an injury management system has to answer went unanswered.');
    }

    public function test_returning_to_full_duty_takes_them_off_that_list(): void
    {
        $case = $this->openCase();

        $this->service()->record($this->companyId, $case, [
            'event_type' => 'BOOKED_OFF', 'from_date' => '2026-09-01', 'to_date' => '2026-09-05',
        ], $this->actorId);

        $this->service()->record($this->companyId, $case, [
            'event_type' => 'RETURNED_FULL', 'from_date' => '2026-09-06',
        ], $this->actorId);

        $this->assertSame('RETURNED', $this->service()->view($this->companyId, $case)['status']);

        $this->assertNotContains($case,
            collect($this->service()->stillAway($this->companyId))->pluck('uuid')->all());
    }

    public function test_the_coida_claim_is_recorded_against_the_case(): void
    {
        $case = $this->openCase();

        $view = $this->service()->recordCoidaClaim($this->companyId, $case, 'CL-2026-88421');

        $this->assertSame('CL-2026-88421', $view['coida_claim_number']);
    }
}
