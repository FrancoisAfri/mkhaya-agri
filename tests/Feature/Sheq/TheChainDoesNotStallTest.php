<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Regulatory\PackLoader;
use App\Modules\Operations\Sheq\Services\IncidentReporter;
use App\Modules\Operations\Sheq\Services\IncidentTriage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Something has to happen next, and something has to notice when it does not.
 *
 * Reporting was thorough and everything after it was voluntary: the incident was classified, its
 * statutory obligations raised with real deadlines, the escalation matrix notified — and then the
 * record waited for a person to remember it. A could-have-killed near miss could sit at REPORTED
 * for a year while every screen and every KPI showed the module working.
 *
 * These are the links that were missing: a clock on the incident from the moment it is reported, a
 * named person who owns it, and a sweep that says so when either is ignored.
 */
class TheChainDoesNotStallTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $reporterId;

    private int $officerId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;

        $manifest = (new PackLoader)->load(base_path('resources/regulatory-packs/za-ohsa'));

        $packId = DB::table('sheq_regulatory_packs')->insertGetId([
            'pack_id' => 'za-ohsa-chain-'.Str::random(6), 'jurisdiction' => 'ZA',
            'name' => 'Chain pack', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $packVersionId = DB::table('sheq_regulatory_pack_versions')->insertGetId([
            'pack_id' => $packId, 'version' => $manifest->version,
            'effective_from' => '2020-01-01', 'manifest' => json_encode($manifest->manifest),
            'checksum' => $manifest->checksum(), 'signature_verified' => false,
            'installed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sheq_regulatory_pack_activations')->insert([
            'company_id' => $this->companyId, 'uuid' => (string) Str::uuid(), 'site_id' => null,
            'pack_version_id' => $packVersionId, 'status' => 'ACCEPTED',
            'activated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->reporterId = $this->user();
        $this->officerId = $this->user();
    }

    private function user(): int
    {
        return DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'chain-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{uuid:string, row:object} */
    private function report(int $potential = 5): array
    {
        $result = app(IncidentReporter::class)->report($this->companyId, [
            'occurred_at'        => Carbon::now()->subHours(2)->toIso8601String(),
            'incident_type'      => 'NEAR_MISS',
            'description'        => 'A suspended load swung across an open walkway.',
            'actual_severity'    => 1,
            'potential_severity' => $potential,
        ], $this->reporterId);

        $uuid = $result['uuid'] ?? $result['incident_uuid'];

        return ['uuid' => $uuid, 'row' => DB::table('sheq_incidents')->where('uuid', $uuid)->first()];
    }

    private function sweep(): void
    {
        $this->artisan('sheq:events:sweep', ['--company' => $this->companyId])->assertSuccessful();
    }

    private function published(string $eventType, int $subjectId): int
    {
        return DB::table('sheq_outbox_messages')
            ->where('company_id', $this->companyId)
            ->where('event_type', $eventType)->where('subject_id', $subjectId)->count();
    }

    // ── The clock starts at report ──────────────────────────────────────────────────────────────

    public function test_a_could_have_killed_incident_gets_a_week_not_a_month(): void
    {
        $incident = $this->report(5)['row'];

        $this->assertNotNull($incident->investigation_due_at, 'The incident carries no deadline.');

        $this->assertEqualsWithDelta(
            7,
            Carbon::now()->diffInDays(Carbon::parse($incident->investigation_due_at), false),
            1,
            'A could-have-killed near miss must be investigated in days, not a month.'
        );
    }

    public function test_a_minor_incident_gets_longer(): void
    {
        $incident = $this->report(1)['row'];

        $this->assertGreaterThan(
            20,
            Carbon::now()->diffInDays(Carbon::parse($incident->investigation_due_at), false),
            'Every incident got the same deadline, so the rating decides nothing.'
        );
    }

    public function test_nobody_owns_it_until_somebody_says_so(): void
    {
        // Deliberately not guessed from the notification matrix: routing tells many people, and an
        // owner invented from that list is accountability nobody agreed to.
        $this->assertNull($this->report()['row']->assigned_to);
        $this->assertNull($this->report()['row']->acknowledged_at);
    }

    // ── Triage ──────────────────────────────────────────────────────────────────────────────────

    public function test_triage_records_who_read_it_and_who_owns_it(): void
    {
        $uuid = $this->report()['uuid'];

        app(IncidentTriage::class)->triage($this->companyId, $uuid, [
            'assigned_to' => $this->officerId,
        ], $this->reporterId);

        $incident = DB::table('sheq_incidents')->where('uuid', $uuid)->first();

        $this->assertNotNull($incident->acknowledged_at);
        $this->assertSame($this->reporterId, (int) $incident->acknowledged_by);
        $this->assertSame($this->officerId, (int) $incident->assigned_to);
    }

    public function test_re_rating_at_triage_moves_the_deadline_in(): void
    {
        // Reported as minor by somebody who had not seen the scene.
        $reported = $this->report(1);
        $wasDue = Carbon::parse($reported['row']->investigation_due_at);

        app(IncidentTriage::class)->triage($this->companyId, $reported['uuid'], [
            'assigned_to' => $this->officerId,
            'potential_severity' => 5,
        ], $this->officerId);

        $nowDue = Carbon::parse(
            DB::table('sheq_incidents')->where('uuid', $reported['uuid'])->value('investigation_due_at')
        );

        $this->assertTrue($nowDue->lessThan($wasDue),
            'A first-aid case re-rated as a near-miss fatality kept the month it was wrongly given.');
    }

    public function test_an_owner_from_another_company_is_refused(): void
    {
        $uuid = $this->report()['uuid'];

        $outsider = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'out-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => null, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectExceptionMessageMatches('/not in this company/i');

        app(IncidentTriage::class)->triage($this->companyId, $uuid, ['assigned_to' => $outsider], $this->officerId);
    }

    // ── The sweep notices ───────────────────────────────────────────────────────────────────────

    public function test_an_unread_incident_is_chased(): void
    {
        $incident = $this->report()['row'];

        DB::table('sheq_incidents')->where('id', $incident->id)
            ->update(['reported_at' => Carbon::now()->subDays(3)]);

        $this->sweep();

        $this->assertSame(1, $this->published('sheq.incident.triage_overdue', (int) $incident->id),
            'An incident nobody acknowledged for three days went unchased.');
    }

    public function test_an_acknowledged_incident_is_not_chased_for_triage(): void
    {
        $reported = $this->report();

        DB::table('sheq_incidents')->where('id', $reported['row']->id)
            ->update(['reported_at' => Carbon::now()->subDays(3)]);

        app(IncidentTriage::class)->triage($this->companyId, $reported['uuid'],
            ['assigned_to' => $this->officerId], $this->officerId);

        $this->sweep();

        $this->assertSame(0, $this->published('sheq.incident.triage_overdue', (int) $reported['row']->id));
    }

    public function test_an_incident_past_its_deadline_with_no_approved_findings_is_chased(): void
    {
        $incident = $this->report()['row'];

        DB::table('sheq_incidents')->where('id', $incident->id)
            ->update(['investigation_due_at' => Carbon::now()->subDays(10)]);

        $this->sweep();

        $this->assertSame(1, $this->published('sheq.incident.investigation_overdue', (int) $incident->id),
            'A could-have-killed incident sat past its deadline and nothing said so.');
    }

    public function test_a_draft_investigation_does_not_stop_the_chase(): void
    {
        $incident = $this->report()['row'];

        DB::table('sheq_incidents')->where('id', $incident->id)
            ->update(['investigation_due_at' => Carbon::now()->subDays(10)]);

        // An investigation left in DRAFT for a month is the same silence as one never opened.
        DB::table('sheq_investigations')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'incident_id' => $incident->id, 'method' => 'ICAM', 'status' => 'DRAFT',
            'opened_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->sweep();

        $this->assertSame(1, $this->published('sheq.incident.investigation_overdue', (int) $incident->id));
    }

    public function test_approved_findings_end_the_chase(): void
    {
        $incident = $this->report()['row'];

        DB::table('sheq_incidents')->where('id', $incident->id)
            ->update(['investigation_due_at' => Carbon::now()->subDays(10)]);

        DB::table('sheq_investigations')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'incident_id' => $incident->id, 'method' => 'ICAM', 'status' => 'APPROVED',
            'opened_at' => now(), 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->sweep();

        $this->assertSame(0, $this->published('sheq.incident.investigation_overdue', (int) $incident->id));
    }

    public function test_a_closed_incident_is_left_alone(): void
    {
        $incident = $this->report()['row'];

        DB::table('sheq_incidents')->where('id', $incident->id)->update([
            'investigation_due_at' => Carbon::now()->subDays(10),
            'reported_at' => Carbon::now()->subDays(30),
            'status' => 'CLOSED',
        ]);

        $this->sweep();

        $this->assertSame(0, $this->published('sheq.incident.investigation_overdue', (int) $incident->id));
        $this->assertSame(0, $this->published('sheq.incident.triage_overdue', (int) $incident->id));
    }

    public function test_the_chase_happens_once_not_every_night(): void
    {
        $incident = $this->report()['row'];

        DB::table('sheq_incidents')->where('id', $incident->id)
            ->update(['investigation_due_at' => Carbon::now()->subDays(10)]);

        $this->sweep();
        $this->sweep();
        $this->sweep();

        $this->assertSame(1, $this->published('sheq.incident.investigation_overdue', (int) $incident->id),
            'Three nights produced three alerts, which is how people learn to ignore them.');
    }

    // ── The last step of CAPA ───────────────────────────────────────────────────────────────────

    public function test_an_unreviewed_control_is_chased_when_its_review_falls_due(): void
    {
        $actionId = DB::table('sheq_actions')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'ACT-'.Str::random(8), 'action_type' => 'CORRECTIVE',
            'due_date' => Carbon::today()->subMonths(3),
            'title' => 'Guard the lifting zone', 'hierarchy_level' => 'ENGINEERING',
            'status' => 'VERIFIED', 'effectiveness_review_due' => Carbon::today()->subDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->sweep();

        $this->assertSame(1, $this->published('sheq.action.effectiveness_review_due', $actionId),
            'The last step of CAPA fell due and nothing asked whether the control worked.');
    }

    public function test_a_control_already_reviewed_is_not_chased(): void
    {
        $actionId = DB::table('sheq_actions')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'ACT-'.Str::random(8), 'action_type' => 'CORRECTIVE',
            'due_date' => Carbon::today()->subMonths(3),
            'title' => 'Guard the lifting zone', 'status' => 'VERIFIED',
            'effectiveness_review_due' => Carbon::today()->subDay(),
            'effectiveness_result' => 'EFFECTIVE',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->sweep();

        $this->assertSame(0, $this->published('sheq.action.effectiveness_review_due', $actionId));
    }

    public function test_an_unfinished_control_is_not_asked_whether_it_worked(): void
    {
        $actionId = DB::table('sheq_actions')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'ACT-'.Str::random(8), 'action_type' => 'CORRECTIVE',
            'due_date' => Carbon::today()->subMonths(3),
            'title' => 'Guard the lifting zone', 'status' => 'OPEN',
            'effectiveness_review_due' => Carbon::today()->subDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->sweep();

        // Reviewing whether an unfinished control worked is a question with no answer; it is the
        // overdue rule's job to chase that one, not this one's.
        $this->assertSame(0, $this->published('sheq.action.effectiveness_review_due', $actionId));
    }
}
