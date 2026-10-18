<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Services\ActionService;
use App\Modules\Operations\Sheq\Services\ContextSnapshotBuilder;
use App\Modules\Operations\Sheq\Services\HazardService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Hazard reporting, triage and the unified CAPA engine (§5.2, §5.5).
 *
 * The rules under test are the ones that separate a CAPA system from a task list: no silent
 * rejection, no self-verification of critical work, no silent due-date edits, and a failed
 * effectiveness review that produces a successor rather than a closed file.
 */
class HazardAndActionTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $siteId;

    /** The reporter/assignee and an independent verifier (SHEQ-CAP-2 needs two distinct people). */
    private int $assigneeId;

    private int $verifierId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }
        $this->companyId = (int) $companyId;

        // The test database carries schema but no data, and these columns are real foreign keys —
        // passing an arbitrary id would test nothing except the constraint.
        $this->assigneeId = $this->makeUser('assignee');
        $this->verifierId = $this->makeUser('verifier');

        $this->siteId = DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'T'.Str::random(5), 'name' => 'Test Plant', 'site_type' => 'PLANT',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeUser(string $label): int
    {
        return DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'email' => "sheq-{$label}-".Str::random(8).'@example.test',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function hazards(): HazardService
    {
        return app(HazardService::class);
    }

    private function actions(): ActionService
    {
        return app(ActionService::class);
    }

    // ── Hazards ─────────────────────────────────────────────────────────────────────────────────

    public function test_a_hazard_report_sets_its_triage_clock(): void
    {
        $result = $this->hazards()->report($this->companyId, [
            'description' => 'Handrail missing on gantry stairs.',
            'site_id' => $this->siteId, 'hazard_category' => 'MECHANICAL', 'severity_est' => 4,
        ], $this->assigneeId);

        $hazard = DB::table('sheq_hazards')->find($result['id']);

        $this->assertSame('OPEN', $hazard->status);
        $this->assertNotNull($hazard->triage_due_at, 'SHEQ-HAZ-2 requires a triage SLA on every hazard.');
        $this->assertEqualsWithDelta(
            24, Carbon::parse($hazard->reported_at)->diffInHours(Carbon::parse($hazard->triage_due_at)), 1
        );
    }

    public function test_rejecting_a_hazard_without_a_reason_is_refused(): void
    {
        // SHEQ-HAZ-3: silent rejection kills reporting culture. The system must be unable to do it.
        $hazard = $this->hazards()->report($this->companyId, [
            'description' => 'Oil on the walkway.', 'site_id' => $this->siteId,
        ], $this->assigneeId);

        $this->expectExceptionMessageMatches('/without a reason/i');

        $this->hazards()->triage($this->companyId, $hazard['uuid'], 'REJECTED', $this->assigneeId, []);
    }

    public function test_rejecting_with_a_reason_records_it_against_the_hazard(): void
    {
        $hazard = $this->hazards()->report($this->companyId, [
            'description' => 'Duplicate of HAZ-2026-000001.', 'site_id' => $this->siteId,
        ], $this->assigneeId);

        $this->hazards()->triage($this->companyId, $hazard['uuid'], 'REJECTED', $this->assigneeId,
            ['reason' => 'Duplicate of an already-controlled hazard.']);

        $row = DB::table('sheq_hazards')->find($hazard['id']);

        $this->assertSame('REJECTED', $row->status);
        $this->assertSame('Duplicate of an already-controlled hazard.', $row->rejection_reason);
        $this->assertNotNull(
            DB::table('sheq_audit_log')->where('subject_type', 'Hazard')
                ->where('subject_id', $hazard['id'])->where('action', 'hazard.triaged')->value('reason'),
            'The reason must reach the audit trail, not just the record.'
        );
    }

    public function test_triaging_to_an_action_creates_a_linked_capa_with_derived_priority(): void
    {
        $hazard = $this->hazards()->report($this->companyId, [
            'description' => 'Exposed live conductor in DB board.',
            'site_id' => $this->siteId, 'severity_est' => 5,
        ], $this->assigneeId);

        $result = $this->hazards()->triage($this->companyId, $hazard['uuid'], 'ACTION', $this->assigneeId, []);

        $this->assertNotNull($result['action']);
        $action = DB::table('sheq_actions')->find($result['action']['id']);

        $this->assertSame('HAZARD', $action->source_type);
        $this->assertSame($hazard['id'], (int) $action->source_id);
        $this->assertSame('CRITICAL', $action->priority, 'Severity 5 must not become a medium action.');
        $this->assertSame('TRIAGED', DB::table('sheq_hazards')->find($hazard['id'])->status);
    }

    // ── CAPA ────────────────────────────────────────────────────────────────────────────────────

    public function test_a_critical_action_cannot_be_verified_by_the_person_assigned_to_it(): void
    {
        // SHEQ-CAP-2. Self-verification of critical work turns a corrective action into a formality.
        $action = $this->actions()->create($this->companyId, [
            'title' => 'Install machine guard', 'priority' => 'CRITICAL',
            'assigned_to' => $this->assigneeId, 'due_date' => now()->addDays(7)->toDateString(),
            'site_id' => $this->siteId,
        ], $this->assigneeId);

        $this->actions()->complete($this->companyId, $action['uuid'], $this->assigneeId, 'Guard fitted.');

        $this->expectExceptionMessageMatches('/verified by someone other than/i');

        $this->actions()->verify($this->companyId, $action['uuid'], $this->assigneeId);
    }

    public function test_a_different_verifier_may_close_a_critical_action(): void
    {
        $action = $this->actions()->create($this->companyId, [
            'title' => 'Install machine guard', 'priority' => 'CRITICAL',
            'assigned_to' => $this->assigneeId, 'due_date' => now()->addDays(7)->toDateString(),
        ], $this->assigneeId);

        $this->actions()->complete($this->companyId, $action['uuid'], $this->assigneeId);
        $this->actions()->verify($this->companyId, $action['uuid'], $this->verifierId, 'Inspected and confirmed.');

        $row = DB::table('sheq_actions')->find($action['id']);

        $this->assertSame('VERIFIED_CLOSED', $row->status);
        $this->assertSame($this->verifierId, (int) $row->verified_by);
        $this->assertNotNull($row->effectiveness_review_due, 'Closure must schedule an effectiveness review.');
    }

    public function test_extending_a_due_date_is_counted_reasoned_and_audited(): void
    {
        // SHEQ-CAP-4: a silently editable due date makes "overdue" meaningless.
        $action = $this->actions()->create($this->companyId, [
            'title' => 'Replace ladder', 'priority' => 'MEDIUM',
            'due_date' => now()->addDays(3)->toDateString(),
        ], $this->assigneeId);

        $this->actions()->extend($this->companyId, $action['uuid'],
            now()->addDays(20)->toDateString(), 'Supplier lead time is 3 weeks.', $this->assigneeId);

        $row = DB::table('sheq_actions')->find($action['id']);

        $this->assertSame(1, (int) $row->extension_count);
        $this->assertNotSame($row->due_date, $row->original_due_date, 'The original date must survive.');
        $this->assertSame('Supplier lead time is 3 weeks.',
            DB::table('sheq_audit_log')->where('subject_id', $action['id'])
                ->where('action', 'action.extended')->value('reason'));
    }

    public function test_an_extension_without_a_reason_is_refused(): void
    {
        $action = $this->actions()->create($this->companyId, ['title' => 'x', 'due_date' => now()->toDateString()], $this->assigneeId);

        $this->expectExceptionMessageMatches('/needs a reason/i');

        $this->actions()->extend($this->companyId, $action['uuid'], now()->addDays(5)->toDateString(), '  ', $this->assigneeId);
    }

    public function test_a_not_effective_review_spawns_a_linked_successor(): void
    {
        // SHEQ-CAP-3: closing the file on an action that demonstrably did not work is the failure.
        $action = $this->actions()->create($this->companyId, [
            'title' => 'Toolbox talk on housekeeping', 'priority' => 'MEDIUM',
            'due_date' => now()->addDays(7)->toDateString(),
        ], $this->assigneeId);

        $successor = $this->actions()->reviewEffectiveness(
            $this->companyId, $action['uuid'], 'NOT_EFFECTIVE', $this->assigneeId, 'Same slip recurred twice since.'
        );

        $this->assertNotNull($successor);
        $row = DB::table('sheq_actions')->find($successor['id']);

        $this->assertSame($action['id'], (int) $row->supersedes_action_id);
        $this->assertStringContainsString('Follow-up', $row->title);
        $this->assertStringContainsString($action['reference'], $row->description);
    }

    public function test_an_effective_review_closes_the_loop_without_a_successor(): void
    {
        $action = $this->actions()->create($this->companyId, ['title' => 'y', 'due_date' => now()->toDateString()], $this->assigneeId);

        $this->assertNull($this->actions()->reviewEffectiveness(
            $this->companyId, $action['uuid'], 'EFFECTIVE', $this->assigneeId
        ));
    }

    // ── The context snapshot now sees both (SHEQ-INC-6) ─────────────────────────────────────────

    public function test_an_overdue_action_at_the_site_becomes_a_gap_on_a_later_incident(): void
    {
        // "We already knew, and had not fixed it" is the most useful fact an investigation can have.
        $this->actions()->create($this->companyId, [
            'title' => 'Repair gantry handrail', 'priority' => 'HIGH', 'site_id' => $this->siteId,
            'due_date' => now()->subDays(45)->toDateString(),
        ], $this->assigneeId);

        $context = app(ContextSnapshotBuilder::class)
            ->build(Carbon::now(), [], $this->siteId);

        $gap = collect($context['gaps'])->firstWhere('type', 'ACTION_OVERDUE');

        $this->assertNotNull($gap, 'An action overdue at the site must be flagged on the incident.');
        $this->assertSame('CRITICAL', $gap['severity'], 'A high-priority overdue action is a critical gap.');
        $this->assertStringContainsString('Repair gantry handrail', $gap['detail']);
        $this->assertCount(1, $context['snapshot']['location']['open_actions']);
    }

    public function test_an_untriaged_hazard_past_its_sla_becomes_a_gap(): void
    {
        $hazard = $this->hazards()->report($this->companyId, [
            'description' => 'Handrail missing.', 'site_id' => $this->siteId,
        ], $this->assigneeId);

        // Age it past the SLA without touching status.
        DB::table('sheq_hazards')->where('id', $hazard['id'])->update([
            'reported_at' => now()->subDays(5),
            'triage_due_at' => now()->subDays(4),
        ]);

        $context = app(ContextSnapshotBuilder::class)
            ->build(Carbon::now(), [], $this->siteId);

        $gap = collect($context['gaps'])->firstWhere('type', 'HAZARD_UNTRIAGED');

        $this->assertNotNull($gap);
        $this->assertStringContainsString($hazard['reference'], $gap['detail']);
    }
}
