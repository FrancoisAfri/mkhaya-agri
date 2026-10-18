<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Services\InvestigationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Incident investigation (SHEQ-INC-7 … SHEQ-INC-10).
 *
 * These tests are about the guards, not the happy path. An investigation module that lets somebody
 * pick "ICAM", write two lines blaming an operator and close the incident with actions outstanding
 * is worse than no module at all — it produces a paper trail saying the organisation learned
 * something it did not.
 */
class InvestigationTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $investigatorId;

    private int $approverId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }
        $this->companyId = (int) $companyId;

        $this->investigatorId = $this->user();
        $this->approverId = $this->user();
    }

    private function user(): int
    {
        return DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'inv-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function service(): InvestigationService
    {
        return app(InvestigationService::class);
    }

    private function incident(array $overrides = []): string
    {
        $uuid = (string) Str::uuid();

        DB::table('sheq_incidents')->insert(array_merge([
            'uuid' => $uuid, 'company_id' => $this->companyId,
            'reference' => 'INC-'.Str::random(8),
            'occurred_at' => Carbon::now()->subDay(), 'reported_at' => Carbon::now()->subDay(),
            'incident_type' => 'NEAR_MISS',
            'description' => 'A load swung over the walkway.',
            'actual_severity' => 1, 'potential_severity' => 5,
            'status' => 'REPORTED', 'created_at' => now(), 'updated_at' => now(),
        ], $overrides));

        return $uuid;
    }

    /** Enough causes, at the right layers, to pass completeness. */
    private function completeCauses(string $investigationUuid): void
    {
        foreach ([
            ['ORGANISATIONAL', 'ROOT', 'No lift plan was required by the procedure.'],
            ['TASK_ENVIRONMENT', 'CONTRIBUTING', 'The walkway runs beneath the lifting zone.'],
            ['ABSENT_FAILED_DEFENCE', 'CONTRIBUTING', 'The exclusion barrier was not deployed.'],
            ['INDIVIDUAL_TEAM_ACTION', 'CONTRIBUTING', 'The rigger did not stop the lift.'],
        ] as [$category, $type, $description]) {
            $this->service()->addCause($this->companyId, $investigationUuid, [
                'category' => $category, 'cause_type' => $type, 'description' => $description,
            ], $this->investigatorId);
        }

        $this->service()->update($this->companyId, $investigationUuid,
            ['summary' => 'The lift was planned informally and the walkway was never closed.'],
            $this->investigatorId);
    }

    // ── Depth follows potential, not outcome (SHEQ-INC-7) ───────────────────────────────────────

    public function test_a_near_miss_with_fatal_potential_demands_the_rigour_of_a_fatality(): void
    {
        /**
         * The requirement that stops an organisation learning nothing until it gets unlucky. Actual
         * severity 1, potential severity 5 — the pack demands ICAM.
         */
        $investigation = $this->service()->open($this->companyId, $this->incident(),
            null, $this->investigatorId);

        $this->assertSame('ICAM', $investigation['required_method']);
        $this->assertSame('ICAM', $investigation['method']);
        $this->assertSame(4, $investigation['minimum_causes']);
    }

    public function test_a_low_potential_incident_gets_a_lighter_method(): void
    {
        $uuid = $this->incident(['potential_severity' => 2, 'actual_severity' => 2]);

        $this->assertSame('FIVE_WHY',
            $this->service()->open($this->companyId, $uuid, null, $this->investigatorId)['required_method']);
    }

    public function test_an_unknown_method_is_refused(): void
    {
        $this->expectExceptionMessageMatches('/not a supported investigation method/');
        $this->service()->open($this->companyId, $this->incident(), 'VIBES', $this->investigatorId);
    }

    public function test_opening_twice_is_refused_rather_than_creating_a_second_live_one(): void
    {
        $uuid = $this->incident();
        $this->service()->open($this->companyId, $uuid, null, $this->investigatorId);

        $this->expectExceptionMessageMatches('/already has a live investigation/');
        $this->service()->open($this->companyId, $uuid, null, $this->investigatorId);
    }

    // ── Completeness (SHEQ-INC-8) ───────────────────────────────────────────────────────────────

    public function test_an_investigation_below_its_minimum_cannot_be_submitted(): void
    {
        // Choosing "ICAM" and writing two lines is not an ICAM investigation.
        $investigation = $this->service()->open($this->companyId, $this->incident(),
            null, $this->investigatorId);

        $this->service()->addCause($this->companyId, $investigation['uuid'], [
            'category' => 'ORGANISATIONAL', 'cause_type' => 'ROOT', 'description' => 'Thin.',
        ], $this->investigatorId);

        $this->expectExceptionMessageMatches('/needs at least 4 causes/');
        $this->service()->submit($this->companyId, $investigation['uuid'], $this->investigatorId);
    }

    public function test_an_investigation_with_no_root_cause_cannot_be_submitted(): void
    {
        $investigation = $this->service()->open($this->companyId, $this->incident(),
            null, $this->investigatorId);

        foreach (self::CONTRIBUTING_ONLY as $category) {
            $this->service()->addCause($this->companyId, $investigation['uuid'], [
                'category' => $category, 'cause_type' => 'CONTRIBUTING',
                'description' => 'A contributing factor.',
            ], $this->investigatorId);
        }

        $this->service()->update($this->companyId, $investigation['uuid'],
            ['summary' => 'Something happened.'], $this->investigatorId);

        $this->expectExceptionMessageMatches('/No root cause/');
        $this->service()->submit($this->companyId, $investigation['uuid'], $this->investigatorId);
    }

    private const CONTRIBUTING_ONLY = [
        'ORGANISATIONAL', 'TASK_ENVIRONMENT', 'ABSENT_FAILED_DEFENCE', 'INDIVIDUAL_TEAM_ACTION',
    ];

    public function test_an_investigation_that_only_blames_a_person_is_refused(): void
    {
        /**
         * The difference between blame and prevention. "The operator made a mistake" is where a bad
         * investigation stops; the system that let them is where a useful one starts.
         */
        $investigation = $this->service()->open($this->companyId, $this->incident(),
            null, $this->investigatorId);

        for ($i = 0; $i < 4; $i++) {
            $this->service()->addCause($this->companyId, $investigation['uuid'], [
                'category' => 'INDIVIDUAL_TEAM_ACTION',
                'cause_type' => $i === 0 ? 'ROOT' : 'CONTRIBUTING',
                'description' => 'The operator was careless.',
            ], $this->investigatorId);
        }

        $this->service()->update($this->companyId, $investigation['uuid'],
            ['summary' => 'Operator error.'], $this->investigatorId);

        $this->expectExceptionMessageMatches('/blames an individual or team/');
        $this->service()->submit($this->companyId, $investigation['uuid'], $this->investigatorId);
    }

    public function test_a_complete_investigation_submits_and_shows_no_shortfalls(): void
    {
        $investigation = $this->service()->open($this->companyId, $this->incident(),
            null, $this->investigatorId);
        $this->completeCauses($investigation['uuid']);

        $this->assertSame([], $this->service()->view($this->companyId, $investigation['uuid'])['shortfalls']);

        $submitted = $this->service()->submit($this->companyId, $investigation['uuid'], $this->investigatorId);
        $this->assertSame('PENDING_APPROVAL', $submitted['status']);
    }

    public function test_the_lead_investigator_cannot_approve_their_own_work(): void
    {
        $investigation = $this->service()->open($this->companyId, $this->incident(),
            null, $this->investigatorId);
        $this->completeCauses($investigation['uuid']);
        $this->service()->submit($this->companyId, $investigation['uuid'], $this->investigatorId);

        $this->expectExceptionMessageMatches('/cannot approve their own/');
        $this->service()->approve($this->companyId, $investigation['uuid'], $this->investigatorId);
    }

    public function test_a_submitted_investigation_can_no_longer_be_edited(): void
    {
        $investigation = $this->service()->open($this->companyId, $this->incident(),
            null, $this->investigatorId);
        $this->completeCauses($investigation['uuid']);
        $this->service()->submit($this->companyId, $investigation['uuid'], $this->investigatorId);

        $this->expectExceptionMessageMatches('/no longer a draft/');
        $this->service()->addCause($this->companyId, $investigation['uuid'], [
            'category' => 'ORGANISATIONAL', 'description' => 'An afterthought.',
        ], $this->investigatorId);
    }

    // ── Closure (SHEQ-INC-9) ────────────────────────────────────────────────────────────────────

    private function approvedInvestigation(string $incidentUuid): array
    {
        $investigation = $this->service()->open($this->companyId, $incidentUuid, null, $this->investigatorId);
        $this->completeCauses($investigation['uuid']);
        $this->service()->submit($this->companyId, $investigation['uuid'], $this->investigatorId);

        return $this->service()->approve($this->companyId, $investigation['uuid'], $this->approverId);
    }

    public function test_an_incident_cannot_close_without_an_approved_investigation(): void
    {
        $uuid = $this->incident();

        $blockers = $this->service()->closureBlockers($this->companyId, $uuid);
        $this->assertContains('The investigation has no approved findings.', $blockers);

        $this->expectExceptionMessageMatches('/cannot be closed yet/');
        $this->service()->closeIncident($this->companyId, $uuid, $this->approverId);
    }

    public function test_an_incident_cannot_close_while_an_action_is_open(): void
    {
        $uuid = $this->incident();
        $incidentId = DB::table('sheq_incidents')->where('uuid', $uuid)->value('id');
        $this->approvedInvestigation($uuid);

        DB::table('sheq_actions')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'ACT-'.Str::random(6), 'title' => 'Fit an exclusion barrier',
            'source_type' => 'INCIDENT', 'source_id' => $incidentId,
            'due_date' => Carbon::today()->addWeek(), 'status' => 'OPEN',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertStringContainsString('corrective action',
            implode(' ', $this->service()->closureBlockers($this->companyId, $uuid)));

        $this->expectExceptionMessageMatches('/corrective action/');
        $this->service()->closeIncident($this->companyId, $uuid, $this->approverId);
    }

    public function test_an_incident_cannot_close_while_a_statutory_obligation_is_undischarged(): void
    {
        // Closing here would record that the organisation dealt with something it has not.
        $uuid = $this->incident();
        $incidentId = DB::table('sheq_incidents')->where('uuid', $uuid)->value('id');
        $this->approvedInvestigation($uuid);

        DB::table('sheq_statutory_obligations')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'obligation_def_id' => 'za.ohsa.notify', 'label' => 'Notify the Provincial Director',
            'subject_type' => 'INCIDENT', 'subject_id' => $incidentId,
            'regulatory_pack_id' => 'za-ohsa', 'regulatory_pack_version' => '2026.1.0',
            'anchor_at' => Carbon::now(), 'due_at' => Carbon::now()->addDay(),
            'status' => 'PENDING', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectExceptionMessageMatches('/statutory obligation/');
        $this->service()->closeIncident($this->companyId, $uuid, $this->approverId);
    }

    public function test_a_clean_incident_closes_and_publishes_the_event(): void
    {
        $uuid = $this->incident();
        $this->approvedInvestigation($uuid);

        $result = $this->service()->closeIncident($this->companyId, $uuid, $this->approverId);
        $this->assertSame('CLOSED', $result['status']);

        $event = DB::table('sheq_outbox_messages')
            ->where('company_id', $this->companyId)
            ->where('event_type', 'sheq.incident.closed')->orderByDesc('id')->first();

        $this->assertNotNull($event, 'Closing an incident published nothing.');
        $this->assertNotEmpty(json_decode($event->payload, true)['root_causes'],
            'The closure event must carry what was learned.');
    }

    // ── Reopening (SHEQ-INC-10) ─────────────────────────────────────────────────────────────────

    public function test_reopening_needs_a_reason(): void
    {
        $uuid = $this->incident();
        $this->approvedInvestigation($uuid);

        $this->expectExceptionMessageMatches('/needs a reason/');
        $this->service()->reopen($this->companyId, $uuid, '   ', $this->approverId);
    }

    public function test_reopening_creates_a_revision_and_preserves_the_prior_one_verbatim(): void
    {
        /**
         * What was concluded in March stays readable after April contradicts it — including when
         * the contradiction is that the first investigation was wrong.
         */
        $uuid = $this->incident();
        $first = $this->approvedInvestigation($uuid);

        $second = $this->service()->reopen($this->companyId, $uuid,
            'New evidence: the lift plan was never issued.', $this->approverId);

        $this->assertSame(2, $second['revision']);
        $this->assertSame('DRAFT', $second['status']);
        $this->assertNotSame($first['uuid'], $second['uuid']);

        // The original survives, untouched, with its findings.
        $prior = $this->service()->view($this->companyId, $first['uuid']);
        $this->assertSame('SUPERSEDED', $prior['status']);
        $this->assertNotEmpty($prior['causes']);
        $this->assertSame('The lift was planned informally and the walkway was never closed.',
            $prior['summary']);

        // And both revisions are visible as history.
        $this->assertCount(2, $this->service()->history($this->companyId, $uuid));
    }

    public function test_reopening_does_not_downgrade_the_required_rigour(): void
    {
        $uuid = $this->incident();
        $this->approvedInvestigation($uuid);

        $second = $this->service()->reopen($this->companyId, $uuid, 'Revisiting.', $this->approverId);

        $this->assertSame('ICAM', $second['required_method']);
        $this->assertSame(4, $second['minimum_causes']);
    }

    public function test_another_tenants_investigation_is_not_found(): void
    {
        $other = DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Other Tenant',
            'slug' => 'other-inv-'.Str::lower(Str::random(8)),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $investigation = $this->service()->open($this->companyId, $this->incident(),
            null, $this->investigatorId);

        $this->expectExceptionMessageMatches('/Investigation not found/');
        $this->service()->view($other, $investigation['uuid']);
    }
}
