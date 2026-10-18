<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Services\SafetyAlertService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Carrying a finding to the crew doing the same job somewhere else.
 *
 * The module could establish to ICAM depth, with a second person's approval, that a walkway was
 * never closed beneath a lifting zone — and that conclusion then stayed on the record of the one
 * incident. Nothing carried it anywhere. That is the step that stops the second one, and it was
 * the only part of the chain with nothing behind it at all.
 */
class FindingsReachThePeopleAtRiskTest extends TestCase
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
            'uuid' => (string) Str::uuid(), 'email' => 'alert-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->incidentUuid = (string) Str::uuid();
        $this->incidentId = DB::table('sheq_incidents')->insertGetId([
            'uuid' => $this->incidentUuid, 'company_id' => $this->companyId,
            'reference' => 'INC-'.Str::random(8),
            'occurred_at' => now()->subDay(), 'reported_at' => now()->subDay(),
            'incident_type' => 'NEAR_MISS',
            'description' => 'A suspended load swung across an open walkway.',
            'actual_severity' => 1, 'potential_severity' => 5,
            'status' => 'ACTIONS_PENDING', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function service(): SafetyAlertService
    {
        return app(SafetyAlertService::class);
    }

    private function approveAnInvestigation(): int
    {
        $id = DB::table('sheq_investigations')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'incident_id' => $this->incidentId, 'method' => 'ICAM', 'status' => 'APPROVED',
            'summary' => 'The lift was planned informally and the walkway was never closed.',
            'opened_at' => now(), 'approved_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sheq_investigation_causes')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'investigation_id' => $id, 'category' => 'ORGANISATIONAL', 'cause_type' => 'ROOT',
            'description' => 'No lift plan was required by the procedure.', 'depth' => 4,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function action(string $title, string $hierarchy): void
    {
        DB::table('sheq_actions')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'ACT-'.Str::random(8), 'action_type' => 'CORRECTIVE',
            'title' => $title, 'hierarchy_level' => $hierarchy,
            'source_type' => 'INCIDENT', 'source_id' => $this->incidentId,
            'status' => 'OPEN', 'due_date' => now()->addWeek(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── Drafted from the findings, not from memory ──────────────────────────────────────────────

    public function test_a_draft_carries_the_root_cause_rather_than_the_description_twice(): void
    {
        $this->approveAnInvestigation();

        $alert = $this->service()->draftFromIncident($this->companyId, $this->incidentUuid, $this->actorId);

        $this->assertStringContainsString('walkway', $alert['what_happened']);
        $this->assertSame('No lift plan was required by the procedure.', $alert['why_it_happened'],
            'The alert repeated what happened instead of carrying why.');
    }

    public function test_the_strongest_control_is_listed_first(): void
    {
        $this->approveAnInvestigation();
        $this->action('Retrain the riggers', 'ADMINISTRATIVE');
        $this->action('Barrier the walkway beneath the lifting zone', 'ENGINEERING');

        $alert = $this->service()->draftFromIncident($this->companyId, $this->incidentUuid, $this->actorId);

        // An alert that leads with "we retrained him" teaches the wrong lesson about the fix.
        $this->assertStringStartsWith('• Barrier the walkway', $alert['what_we_changed']);
    }

    public function test_a_could_have_killed_incident_produces_a_critical_alert(): void
    {
        $this->approveAnInvestigation();

        $this->assertSame('CRITICAL',
            $this->service()->draftFromIncident($this->companyId, $this->incidentUuid, $this->actorId)['severity']);
    }

    public function test_findings_still_being_argued_about_cannot_be_broadcast(): void
    {
        DB::table('sheq_investigations')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'incident_id' => $this->incidentId, 'method' => 'ICAM', 'status' => 'PENDING_APPROVAL',
            'opened_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        // A retracted safety alert costs more credibility than a late one.
        $this->expectExceptionMessageMatches('/approved findings/i');

        $this->service()->draftFromIncident($this->companyId, $this->incidentUuid, $this->actorId);
    }

    // ── Issuing ─────────────────────────────────────────────────────────────────────────────────

    public function test_an_alert_that_only_says_what_happened_is_refused(): void
    {
        $alert = $this->service()->create($this->companyId, [
            'title' => 'Something went wrong',
            'what_happened' => 'A load swung over a walkway.',
        ], $this->actorId);

        $this->expectExceptionMessageMatches('/why it happened or what was changed/i');

        $this->service()->issue($this->companyId, $alert['uuid'], $this->actorId);
    }

    public function test_issuing_publishes_it_for_the_people_at_risk(): void
    {
        $this->approveAnInvestigation();
        $this->action('Barrier the walkway', 'ENGINEERING');

        $alert = $this->service()->draftFromIncident($this->companyId, $this->incidentUuid, $this->actorId);
        $issued = $this->service()->issue($this->companyId, $alert['uuid'], $this->actorId);

        $this->assertSame('ISSUED', $issued['status']);
        $this->assertNotNull($issued['issued_at']);

        $this->assertSame(1, DB::table('sheq_outbox_messages')
            ->where('company_id', $this->companyId)
            ->where('event_type', 'sheq.safety_alert.issued')->count());
    }

    public function test_an_issued_alert_cannot_be_rewritten(): void
    {
        $this->approveAnInvestigation();
        $this->action('Barrier the walkway', 'ENGINEERING');

        $alert = $this->service()->draftFromIncident($this->companyId, $this->incidentUuid, $this->actorId);
        $this->service()->issue($this->companyId, $alert['uuid'], $this->actorId);

        // People have read it. Editing it afterwards means two versions in circulation.
        $this->expectExceptionMessageMatches('/cannot be rewritten/i');

        $this->service()->update($this->companyId, $alert['uuid'], ['title' => 'Different'], $this->actorId);
    }

    public function test_reads_are_counted_once_per_person(): void
    {
        $this->approveAnInvestigation();
        $this->action('Barrier the walkway', 'ENGINEERING');

        $alert = $this->service()->draftFromIncident($this->companyId, $this->incidentUuid, $this->actorId);
        $this->service()->issue($this->companyId, $alert['uuid'], $this->actorId);

        $this->service()->markRead($this->companyId, $alert['uuid'], $this->actorId);
        $this->service()->markRead($this->companyId, $alert['uuid'], $this->actorId);

        // A coverage figure that double-counts one person is a figure nobody can act on.
        $this->assertSame(1, $this->service()->view($this->companyId, $alert['uuid'])['reads']);
    }

    public function test_a_draft_has_not_been_sent_to_anybody_to_read(): void
    {
        $alert = $this->service()->create($this->companyId, [
            'title' => 'Draft', 'what_happened' => 'Something.',
        ], $this->actorId);

        $this->expectExceptionMessageMatches('/has not been sent/i');

        $this->service()->markRead($this->companyId, $alert['uuid'], $this->actorId);
    }
}
