<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Approvals\ApprovalService;
use App\Modules\Operations\Sheq\Services\ContractorService;
use App\Modules\Operations\Sheq\Services\DocumentService;
use App\Modules\Operations\Sheq\Services\RiskAssessmentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Every subject the Approvals screen offers now runs the workflow configured for it.
 *
 * The screen has always listed ten. Three opened an approval; the other seven could be configured,
 * saved, listed — and ignored. A screen that describes a control which does not exist is worse than
 * one that does not offer it, because somebody believes the chain is in force.
 *
 * The rule throughout: **configure a workflow and it is enforced; configure none and nothing
 * changes.** Calling the existing `open()` from these would have refused every risk assessment,
 * document and contractor at every tenant who had not set one up — installing the release would
 * have broken working installations.
 */
class AllApprovalSubjectsTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $userId;

    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;

        $this->userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'appr-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->siteId = DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'A'.Str::random(5), 'name' => 'Plant', 'site_type' => 'PLANT',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A one-step workflow, the way the Approvals screen saves one. */
    private function workflow(string $subjectType): int
    {
        $workflowId = DB::table('sheq_approval_workflows')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'subject_type' => $subjectType, 'subject_subtype' => null, 'site_id' => null,
            'name' => $subjectType.' chain', 'version' => 1, 'is_active' => true,
            'created_by' => $this->userId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sheq_approval_steps')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'workflow_id' => $workflowId, 'position' => 1, 'name' => 'Manager',
            'approver_type' => 'ROLE', 'approver_ref' => 'SITE_MANAGER',
            'quorum' => 1, 'requires_signature' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $workflowId;
    }

    private function assessmentReadyToApprove(): array
    {
        $service = app(RiskAssessmentService::class);

        $assessment = $service->create($this->companyId, [
            'title' => 'Working at height', 'site_id' => $this->siteId,
        ], $this->userId);

        $service->addLine($this->companyId, $assessment['uuid'], [
            'activity' => 'Scaffold', 'hazard' => 'Fall',
            'inherent_likelihood' => 2, 'inherent_severity' => 2,
            'residual_likelihood' => 1, 'residual_severity' => 2,
        ], [['hierarchy_level' => 'ENGINEERING', 'description' => 'Edge protection']]);

        $service->addParticipant($this->companyId, $assessment['uuid'], ['name' => 'Crew']);

        return $assessment;
    }

    public function test_with_no_workflow_a_risk_assessment_approves_exactly_as_before(): void
    {
        $assessment = $this->assessmentReadyToApprove();

        // The whole point: installing this must not break a tenant who configured nothing.
        app(RiskAssessmentService::class)
            ->approve($this->companyId, $assessment['uuid'], $this->userId, ['SITE_MANAGER']);

        $this->assertSame('APPROVED',
            DB::table('sheq_risk_assessments')->where('uuid', $assessment['uuid'])->value('status'));
    }

    public function test_a_configured_workflow_holds_a_risk_assessment_until_it_is_signed(): void
    {
        $this->workflow('RISK_ASSESSMENT');

        $assessment = $this->assessmentReadyToApprove();

        $this->expectExceptionMessageMatches('/waiting on/i');

        app(RiskAssessmentService::class)
            ->approve($this->companyId, $assessment['uuid'], $this->userId, ['SITE_MANAGER']);
    }

    public function test_the_chain_is_opened_when_the_assessment_is_raised(): void
    {
        $this->workflow('RISK_ASSESSMENT');

        $assessment = $this->assessmentReadyToApprove();

        // Opened on creation, so an approver has the time in between to sign. Opening it at the
        // moment of effect would mean the first attempt always failed.
        $this->assertNotNull(
            app(ApprovalService::class)->statusFor($this->companyId, 'RISK_ASSESSMENT', $assessment['id']),
        );
    }

    public function test_a_contractor_is_held_by_an_outstanding_approval_as_well_as_by_paperwork(): void
    {
        $this->workflow('CONTRACTOR');

        $contractor = app(ContractorService::class)->register($this->companyId, [
            'name' => 'Scaffold Co', 'trade' => 'SCAFFOLDING',
        ], $this->userId);

        $file = app(ContractorService::class)
            ->complianceFile($this->companyId, $contractor['uuid'], []);

        // The gate has to answer in one place: somebody reading BLOCKED needs the reason whether
        // it is a lapsed certificate or a signature that has not happened.
        $this->assertSame('BLOCKED', $file['status']);
        $this->assertContains('APPROVAL_OUTSTANDING', array_column($file['blocking'], 'type'));
    }

    public function test_a_document_cannot_be_published_while_its_approval_stands(): void
    {
        $this->workflow('DOCUMENT');

        $document = app(DocumentService::class)->create($this->companyId, [
            'doc_number' => 'DOC-'.Str::random(5), 'title' => 'Working at height procedure',
            'doc_type' => 'PROCEDURE',
        ], $this->userId);

        DB::table('sheq_document_versions')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'document_id' => $document['id'], 'version' => 1, 'status' => 'DRAFT',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectExceptionMessageMatches('/waiting on/i');

        app(DocumentService::class)->publish($this->companyId, $document['uuid'], 1, $this->userId);
    }

    public function test_signing_the_chain_lets_the_subject_through(): void
    {
        $this->workflow('RISK_ASSESSMENT');

        $assessment = $this->assessmentReadyToApprove();

        // Approve the chain directly: this test is about the gate opening, not about who may sign.
        DB::table('sheq_approval_requests')
            ->where('company_id', $this->companyId)
            ->where('subject_type', 'RISK_ASSESSMENT')->where('subject_id', $assessment['id'])
            ->update(['status' => 'APPROVED', 'completed_at' => now()]);

        app(RiskAssessmentService::class)
            ->approve($this->companyId, $assessment['uuid'], $this->userId, ['SITE_MANAGER']);

        $this->assertSame('APPROVED',
            DB::table('sheq_risk_assessments')->where('uuid', $assessment['uuid'])->value('status'));
    }

    public function test_the_reason_names_the_step_rather_than_saying_approval_outstanding(): void
    {
        $this->workflow('RISK_ASSESSMENT');

        $assessment = $this->assessmentReadyToApprove();

        $reason = app(ApprovalService::class)
            ->blockingReason($this->companyId, 'RISK_ASSESSMENT', $assessment['id']);

        // "Approval outstanding" with no detail sends people to go and ask who is holding it up.
        $this->assertStringContainsString('Manager', (string) $reason);
    }

    /**
     * Every subject type named in a gate has to be one the Approvals screen can configure.
     *
     * This exists because a gate shipped reading `'INC(int) $incident->idENT_CLOSURE'` — a
     * find-and-replace that substituted inside the word INCIDENT_CLOSURE. The literal matched no
     * workflow, so `isCleared()` concluded nothing was configured and waved every closure through:
     * a gate that silently did the opposite of its job.
     *
     * Asserted against the source rather than through each flow, because the failure is a typo in
     * a string and the behaviour it produces is indistinguishable from "no workflow configured" —
     * which is the normal, correct state for most tenants, so no behavioural test notices.
     */
    public function test_every_gate_names_a_real_approval_subject(): void
    {
        $known = (new \ReflectionClass(\App\Modules\Operations\Sheq\Http\Controllers\ApprovalController::class))
            ->getConstant('SUBJECT_TYPES');

        $this->assertNotEmpty($known, 'The subject list moved; this test is checking nothing.');

        $offenders = [];

        foreach ($this->moduleFiles() as $file) {
            $source = (string) file_get_contents($file);

            /*
             * The first quoted argument to a gate IS the subject type. Anything there that is not
             * in the list is the bug — including, especially, a value that does not look like a
             * subject type at all.
             *
             * An earlier version of this test skipped malformed-looking values as "probably not a
             * subject", which excluded precisely the corruption it was written to catch.
             */
            preg_match_all("/(?:blockingReason|isCleared)\s*\(\s*[^,]+,\s*'([^']*)'/s", $source, $gates);

            /*
             * `openIfConfigured` takes an array, so the subject is the mapped value — but
             * `subject_type` is also an audit-log field used all over this module for an entirely
             * different idea. Matched only inside the call, or the audit writes drown the signal.
             */
            preg_match_all(
                "/openIfConfigured\\s*\\([^;]*?'subject_type'\\s*=>\\s*'([^']*)'/s",
                $source, $opens);

            foreach (array_merge($gates[1] ?? [], $opens[1] ?? []) as $subject) {
                if (! in_array($subject, $known, true)) {
                    $offenders[] = basename($file).": '{$subject}'";
                }
            }
        }

        $this->assertSame([], array_values(array_unique($offenders)),
            'These gates name a subject type no workflow can ever match, so they clear everything: '
            .implode(', ', array_unique($offenders)));
    }

    /** @return list<string> */
    private function moduleFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Modules/Operations/Sheq'),
                \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    public function test_a_completed_visitor_decision_reaches_the_visit(): void
    {
        $this->workflow('VISITOR');

        $visitId = DB::table('sheq_visits')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'VIS-'.Str::random(5), 'visitor_name' => 'A. Visitor',
            'site_id' => $this->siteId, 'status' => 'REQUESTED',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(\App\Modules\Operations\Sheq\Services\VisitorService::class)
            ->applyApproval($this->companyId, $visitId, true);

        // Until this was wired, deciding the approval recorded the decision and left the visit at
        // REQUESTED: the approver saw "Approved", the gatehouse saw an unapproved visitor.
        $this->assertSame('APPROVED',
            DB::table('sheq_visits')->where('id', $visitId)->value('status'));
    }

    public function test_a_workflow_for_another_company_does_not_gate_this_one(): void
    {
        $otherCompany = (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'slug' => 'other-'.Str::random(8),
            'name' => 'Other Employer', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sheq_approval_workflows')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $otherCompany,
            'subject_type' => 'RISK_ASSESSMENT', 'name' => 'Theirs', 'version' => 1,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $assessment = $this->assessmentReadyToApprove();

        app(RiskAssessmentService::class)
            ->approve($this->companyId, $assessment['uuid'], $this->userId, ['SITE_MANAGER']);

        $this->assertSame('APPROVED',
            DB::table('sheq_risk_assessments')->where('uuid', $assessment['uuid'])->value('status'));
    }
}
