<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Services\PermitService;
use App\Modules\Operations\Sheq\Services\RiskAssessmentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * An approval workflow a tenant configures has to be the one that runs.
 *
 * The Approvals screen offers a workflow for ten subject types. A tenant could build a three-step
 * chain for "Permits to work", save it, see it listed — and every permit would continue to ask one
 * person, because the stages came only from the caller and the configured workflow was never read.
 * The screen described a control that did not exist, which is worse than not offering it: somebody
 * believes the chain is in force.
 */
class ConfiguredWorkflowsTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $userId;

    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests read what a page hands its component, not the compiled front end. Without
        // this the suite depends on `public/build/manifest.json`, so an unrelated `vite build`
        // running in another checkout turns every page assertion below into a 500.
        $this->withoutVite();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;

        $this->userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'wf-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->siteId = DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'W'.Str::random(5), 'name' => 'Plant', 'site_type' => 'PLANT',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A workflow, the way the Approvals screen saves one. */
    private function workflow(array $steps, ?string $subtype = null): void
    {
        $workflowId = DB::table('sheq_approval_workflows')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'subject_type' => 'PERMIT', 'subject_subtype' => $subtype, 'site_id' => null,
            'name' => 'Hot work chain', 'version' => 1, 'is_active' => true,
            'created_by' => $this->userId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (array_values($steps) as $i => $step) {
            DB::table('sheq_approval_steps')->insert([
                'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
                'workflow_id' => $workflowId, 'position' => $i + 1,
                'name' => $step, 'approver_type' => 'ROLE', 'approver_ref' => $step,
                'quorum' => 1, 'requires_signature' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function approvedAssessment(): int
    {
        $service = app(RiskAssessmentService::class);

        $assessment = $service->create($this->companyId, [
            'title' => 'Hot work', 'site_id' => $this->siteId,
        ], $this->userId);

        $service->addLine($this->companyId, $assessment['uuid'], [
            'activity' => 'Welding', 'hazard' => 'Ignition',
            'inherent_likelihood' => 3, 'inherent_severity' => 3,
            'residual_likelihood' => 2, 'residual_severity' => 2,
        ], [['hierarchy_level' => 'ENGINEERING', 'description' => 'Purge the line']]);

        $service->addParticipant($this->companyId, $assessment['uuid'], ['name' => 'Crew']);
        $service->approve($this->companyId, $assessment['uuid'], $this->userId, ['SITE_MANAGER']);

        return $assessment['id'];
    }

    private function requestPermit(): array
    {
        return app(PermitService::class)->request($this->companyId, [
            'permit_type' => 'HOT_WORK',
            'site_id' => $this->siteId,
            'work_description' => 'Weld a bracket.',
            'valid_from' => Carbon::now()->toDateTimeString(),
            'valid_to' => Carbon::now()->addHours(8)->toDateTimeString(),
            'risk_assessment_id' => $this->approvedAssessment(),
        ], [['name' => 'T. Mokoena', 'worker_role' => 'WORKER']], $this->userId);
    }

    private function stagesFor(int $permitId): array
    {
        return DB::table('sheq_permit_approvals')
            ->where('permit_id', $permitId)->orderBy('stage')
            ->pluck('approver_role')->all();
    }

    public function test_a_configured_chain_is_the_one_the_permit_asks_for(): void
    {
        $this->workflow(['SUPERVISOR', 'SHEQ_PRACTITIONER', 'SITE_MANAGER']);

        $permit = $this->requestPermit();

        $this->assertSame(
            ['SUPERVISOR', 'SHEQ_PRACTITIONER', 'SITE_MANAGER'],
            $this->stagesFor($permit['id']),
            'The configured workflow was saved and ignored, so the screen described a control that '
            .'did not exist.',
        );
    }

    public function test_a_chain_for_this_permit_type_beats_a_general_one(): void
    {
        $this->workflow(['GENERAL_APPROVER']);
        $this->workflow(['HOT_WORK_APPROVER'], subtype: 'HOT_WORK');

        $permit = $this->requestPermit();

        // Most specific wins, which is the precedence an approver would assume.
        $this->assertSame(['HOT_WORK_APPROVER'], $this->stagesFor($permit['id']));
    }

    public function test_with_no_workflow_a_permit_still_needs_one_decision(): void
    {
        $permit = $this->requestPermit();

        // An unapproved permit must never become active by default.
        $this->assertSame(['SHEQ_PRACTITIONER'], $this->stagesFor($permit['id']));
    }

    public function test_a_retired_workflow_stops_applying(): void
    {
        $this->workflow(['OLD_APPROVER']);

        DB::table('sheq_approval_workflows')
            ->where('company_id', $this->companyId)->where('subject_type', 'PERMIT')
            ->update(['is_active' => false]);

        $permit = $this->requestPermit();

        $this->assertSame(['SHEQ_PRACTITIONER'], $this->stagesFor($permit['id']));
    }

    public function test_every_stage_must_be_approved_before_the_permit_can_issue(): void
    {
        $this->workflow(['SUPERVISOR', 'SITE_MANAGER']);

        $permit = $this->requestPermit();
        $service = app(PermitService::class);

        $approvals = DB::table('sheq_permit_approvals')
            ->where('permit_id', $permit['id'])->orderBy('stage')->get();

        // One of two approved is not an approved chain.
        $service->approve($this->companyId, $permit['uuid'], $approvals[0]->id, $this->userId);

        $blocking = array_column(
            $service->preIssueChecks($this->companyId, $permit['uuid'])['blocking'], 'type');

        $this->assertNotEmpty($blocking,
            'A permit with an outstanding approval must not be issuable.');
    }
}
