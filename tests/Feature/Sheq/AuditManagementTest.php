<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Approvals\ApprovalService;
use App\Modules\Operations\Sheq\Services\AuditService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Audit management — the "Q" in SHEQ.
 *
 * These pin the three rules that separate an audit system from a checklist app: findings are graded
 * and cited, nobody verifies their own corrective action, and an audit cannot be declared closed
 * while a major non-conformance stands.
 */
class AuditManagementTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $auditorId;

    private int $auditeeId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        $this->auditorId = $this->user();
        $this->auditeeId = $this->user();
    }

    private function user(): int
    {
        return DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'aud-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function audit(array $overrides = []): array
    {
        return app(AuditService::class)->plan($this->companyId, array_merge([
            'title' => 'ISO 45001 internal audit — maintenance',
            'audit_type' => 'INTERNAL', 'standard' => 'ISO 45001',
        ], $overrides), $this->auditorId);
    }

    // ── Findings are graded and cited ─────────────────────────────────────────────────────────

    public function test_a_non_conformance_must_cite_a_clause(): void
    {
        $audit = $this->audit();

        // Without a clause it is an opinion. A certification body rejects it, and so does this.
        $this->expectExceptionMessageMatches('/must cite the clause/');

        app(AuditService::class)->raiseFinding($this->companyId, $audit['uuid'], [
            'grade' => 'MAJOR_NC', 'statement' => 'Records were untidy.',
        ], $this->auditorId);
    }

    public function test_an_observation_needs_no_clause(): void
    {
        $audit = $this->audit();

        // An observation carries no obligation, so demanding a clause would be bureaucratic
        // rather than useful.
        $finding = app(AuditService::class)->raiseFinding($this->companyId, $audit['uuid'], [
            'grade' => 'OBSERVATION', 'statement' => 'Toolbox talks are well attended.',
        ], $this->auditorId);

        $this->assertSame('OBSERVATION', $finding['grade']);
    }

    public function test_an_unknown_grade_is_refused(): void
    {
        $audit = $this->audit();

        $this->expectExceptionMessageMatches('/is not a finding grade/');

        app(AuditService::class)->raiseFinding($this->companyId, $audit['uuid'], [
            'grade' => 'BAD', 'statement' => 'Something.',
        ], $this->auditorId);
    }

    public function test_a_non_conformance_automatically_raises_a_corrective_action(): void
    {
        $audit = $this->audit();

        $finding = app(AuditService::class)->raiseFinding($this->companyId, $audit['uuid'], [
            'grade' => 'MINOR_NC', 'clause' => '7.5.3',
            'statement' => 'Two calibration records could not be retrieved.',
        ], $this->auditorId);

        // The failure this prevents: agreed in the closing meeting, written in the report, and
        // then nothing happens because nobody owned it.
        $this->assertNotNull($finding['action_id']);

        $action = DB::table('sheq_actions')->where('id', $finding['action_id'])
            ->first(['source_type', 'source_id', 'priority']);

        $this->assertSame('AUDIT_FINDING', $action->source_type);
        $this->assertSame($finding['id'], (int) $action->source_id,
            'The action must point back at the finding, not at zero.');
    }

    public function test_an_observation_does_not_raise_an_action(): void
    {
        $audit = $this->audit();

        $finding = app(AuditService::class)->raiseFinding($this->companyId, $audit['uuid'], [
            'grade' => 'OFI', 'statement' => 'Consider consolidating the two registers.',
        ], $this->auditorId);

        $this->assertNull($finding['action_id']);
    }

    public function test_a_major_finding_is_raised_at_higher_priority(): void
    {
        $audit = $this->audit();

        $major = app(AuditService::class)->raiseFinding($this->companyId, $audit['uuid'], [
            'grade' => 'MAJOR_NC', 'clause' => '8.1',
            'statement' => 'No operational planning for a high-risk activity.',
        ], $this->auditorId);

        $this->assertSame('HIGH', DB::table('sheq_actions')->where('id', $major['action_id'])
            ->value('priority'));
    }

    // ── Responses ─────────────────────────────────────────────────────────────────────────────

    public function test_a_response_without_a_root_cause_is_refused(): void
    {
        $audit = $this->audit();

        $finding = app(AuditService::class)->raiseFinding($this->companyId, $audit['uuid'], [
            'grade' => 'MINOR_NC', 'clause' => '7.2', 'statement' => 'Training records incomplete.',
        ], $this->auditorId);

        // "We reminded the team" is a correction, not a cause, and a corrective action built on
        // it will not prevent recurrence.
        $this->expectExceptionMessageMatches('/needs a root cause/');

        app(AuditService::class)->respondToFinding($this->companyId, $finding['uuid'], [
            'correction' => 'We reminded the team.',
        ], $this->auditeeId);
    }

    public function test_closure_cannot_be_requested_before_a_response(): void
    {
        $audit = $this->audit();

        $finding = app(AuditService::class)->raiseFinding($this->companyId, $audit['uuid'], [
            'grade' => 'MINOR_NC', 'clause' => '9.2', 'statement' => 'Audit programme not followed.',
        ], $this->auditorId);

        $this->expectExceptionMessageMatches('/no root cause recorded/');

        app(AuditService::class)->requestClosure($this->companyId, $finding['uuid'], $this->auditeeId);
    }

    // ── Independent verification ──────────────────────────────────────────────────────────────

    public function test_the_person_who_fixed_a_finding_cannot_verify_it(): void
    {
        $role = 'auditor-'.Str::random(6);
        Role::create(['name' => $role, 'guard_name' => 'web']);

        $workflowId = DB::table('sheq_approval_workflows')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'subject_type' => 'AUDIT_FINDING', 'name' => 'Finding verification',
            'version' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sheq_approval_steps')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'workflow_id' => $workflowId, 'position' => 1, 'name' => 'Auditor verifies',
            'approver_type' => 'ROLE', 'approver_ref' => $role, 'quorum' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        User::withoutGlobalScopes()->findOrFail($this->auditeeId)
            ->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $audit = $this->audit();
        $service = app(AuditService::class);

        $finding = $service->raiseFinding($this->companyId, $audit['uuid'], [
            'grade' => 'MINOR_NC', 'clause' => '7.5', 'statement' => 'Procedure not followed.',
        ], $this->auditorId);

        $service->respondToFinding($this->companyId, $finding['uuid'],
            ['root_cause' => 'The procedure was never communicated after the revision.'],
            $this->auditeeId);

        $approval = $service->requestClosure($this->companyId, $finding['uuid'], $this->auditeeId);

        // The auditee holds the verifying role and still cannot sign off their own fix. This is
        // the single most common adverse observation in a surveillance audit.
        $this->expectExceptionMessageMatches('/you cannot approve it/');

        app(ApprovalService::class)
            ->decide($this->companyId, $approval['uuid'], $this->auditeeId,
                ['decision' => 'APPROVED']);
    }

    // ── Closing the audit ─────────────────────────────────────────────────────────────────────

    public function test_an_audit_cannot_close_with_an_open_major_non_conformance(): void
    {
        $audit = $this->audit();
        $service = app(AuditService::class);

        $service->raiseFinding($this->companyId, $audit['uuid'], [
            'grade' => 'MAJOR_NC', 'clause' => '6.1',
            'statement' => 'Risks were not determined for a change to the process.',
        ], $this->auditorId);

        // Closing around it would produce a report asserting conformity that the findings in the
        // same database contradict.
        $this->expectExceptionMessageMatches('/open major non-conformance/');

        $service->close($this->companyId, $audit['uuid'], $this->auditorId);
    }

    public function test_an_audit_closes_once_the_majors_are_closed(): void
    {
        $audit = $this->audit();
        $service = app(AuditService::class);

        $finding = $service->raiseFinding($this->companyId, $audit['uuid'], [
            'grade' => 'MAJOR_NC', 'clause' => '6.1', 'statement' => 'Risks not determined.',
        ], $this->auditorId);

        $service->applyClosureDecision($this->companyId, $finding['id'], true, $this->auditorId);
        $service->close($this->companyId, $audit['uuid'], $this->auditorId, 'All findings addressed.');

        $row = DB::table('sheq_audits')->where('uuid', $audit['uuid'])
            ->first(['status', 'passed', 'completed_at']);

        $this->assertSame('CLOSED', $row->status);
        $this->assertTrue((bool) $row->passed);
    }

    public function test_an_audit_with_open_minors_closes_but_does_not_pass(): void
    {
        $audit = $this->audit();
        $service = app(AuditService::class);

        $service->raiseFinding($this->companyId, $audit['uuid'], [
            'grade' => 'MINOR_NC', 'clause' => '7.2', 'statement' => 'Records incomplete.',
        ], $this->auditorId);

        $service->close($this->companyId, $audit['uuid'], $this->auditorId);

        // A minor does not block closure — but the audit did not pass, and the report should not
        // pretend otherwise.
        $this->assertFalse((bool) DB::table('sheq_audits')->where('uuid', $audit['uuid'])
            ->value('passed'));
    }

    // ── Scoring ───────────────────────────────────────────────────────────────────────────────

    public function test_not_applicable_questions_do_not_drag_the_score_down(): void
    {
        $audit = $this->audit();

        $result = app(AuditService::class)->recordFieldwork($this->companyId, $audit['uuid'], [
            'q1' => ['answer' => 'CONFORMS'],
            'q2' => ['answer' => 'CONFORMS'],
            // A site with no confined spaces should not score badly for having no confined-space
            // procedure.
            'q3' => ['answer' => 'NA'],
        ], $this->auditorId);

        $this->assertSame(2, $result['questions_scored']);
        $this->assertEqualsWithDelta(100.0, $result['score'], 0.01);
    }

    public function test_partial_conformity_scores_half(): void
    {
        $audit = $this->audit();

        $result = app(AuditService::class)->recordFieldwork($this->companyId, $audit['uuid'], [
            'q1' => ['answer' => 'CONFORMS'],
            'q2' => ['answer' => 'PARTIAL'],
            'q3' => ['answer' => 'NOT_CONFORMS'],
        ], $this->auditorId);

        $this->assertEqualsWithDelta(50.0, $result['score'], 0.01);
    }

    public function test_the_summary_counts_findings_by_grade(): void
    {
        $audit = $this->audit();
        $service = app(AuditService::class);

        $service->raiseFinding($this->companyId, $audit['uuid'],
            ['grade' => 'MAJOR_NC', 'clause' => '6.1', 'statement' => 'A'], $this->auditorId);
        $service->raiseFinding($this->companyId, $audit['uuid'],
            ['grade' => 'MINOR_NC', 'clause' => '7.2', 'statement' => 'B'], $this->auditorId);
        $service->raiseFinding($this->companyId, $audit['uuid'],
            ['grade' => 'OFI', 'statement' => 'C'], $this->auditorId);

        $summary = $service->summary($this->companyId, $audit['uuid']);

        $this->assertSame(1, $summary['counts']['major']);
        $this->assertSame(1, $summary['counts']['minor']);
        $this->assertSame(1, $summary['counts']['ofi']);
    }

    public function test_references_are_sequential_and_scoped_to_the_audit(): void
    {
        $audit = $this->audit();
        $service = app(AuditService::class);

        $first = $service->raiseFinding($this->companyId, $audit['uuid'],
            ['grade' => 'OFI', 'statement' => 'A'], $this->auditorId);
        $second = $service->raiseFinding($this->companyId, $audit['uuid'],
            ['grade' => 'OFI', 'statement' => 'B'], $this->auditorId);

        $this->assertStringEndsWith('-F01', $first['reference']);
        $this->assertStringEndsWith('-F02', $second['reference']);
        $this->assertStringStartsWith($audit['reference'], $second['reference']);
    }
}
