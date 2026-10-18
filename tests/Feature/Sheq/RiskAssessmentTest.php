<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Services\RiskAssessmentService;
use App\Modules\Operations\Sheq\Services\RiskMatrix;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * HIRA (§5.3).
 *
 * The assertions here are the difference between a risk register and a risk assessment: approval
 * authority derived from the residual band, weak-control detection, immutable approved versions,
 * and recorded worker participation.
 */
class RiskAssessmentTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }
        $this->companyId = (int) $companyId;

        $this->userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'ra-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function service(): RiskAssessmentService
    {
        return app(RiskAssessmentService::class);
    }

    /** An assessment with one line, its controls, and a participant so it is approvable. */
    private function assessmentWith(array $line, array $controls = [], bool $withParticipant = true): array
    {
        $assessment = $this->service()->create($this->companyId, [
            'title' => 'Working at heights — roof sheeting', 'ra_type' => 'TASK',
        ], $this->userId);

        $this->service()->addLine($this->companyId, $assessment['uuid'], $line, $controls);

        if ($withParticipant) {
            $this->service()->addParticipant($this->companyId, $assessment['uuid'], [
                'name' => 'S. Dlamini', 'participant_role' => 'WORKER', 'user_id' => $this->userId,
            ]);
        }

        return $assessment;
    }

    // ── The matrix is configuration, not code (SHEQ-RA-1) ───────────────────────────────────────

    public function test_a_matrix_may_be_any_size_from_three_to_six(): void
    {
        foreach ([[3, 3], [4, 5], [6, 6]] as [$likelihood, $severity]) {
            $matrix = new RiskMatrix(1, 'Custom', $likelihood, $severity,
                [['min' => 1, 'max' => $likelihood * $severity, 'label' => 'All']]);

            $this->assertSame($likelihood * $severity, $matrix->maxScore());
        }
    }

    public function test_a_matrix_outside_three_to_six_is_rejected(): void
    {
        $this->expectExceptionMessageMatches('/between 3×3 and 6×6/');

        new RiskMatrix(1, 'Too big', 8, 8, [['min' => 1, 'max' => 64, 'label' => 'All']]);
    }

    public function test_a_rating_outside_the_matrix_is_rejected(): void
    {
        // A 3×3 matrix has no severity 5. Silently accepting it would store a score the matrix
        // cannot band, and the assessment would carry a rating nobody can interpret.
        $matrix = new RiskMatrix(1, 'Small', 3, 3, [['min' => 1, 'max' => 9, 'label' => 'All']]);

        $this->expectExceptionMessageMatches('/Severity 5 is outside this matrix/');

        $matrix->score(2, 5);
    }

    public function test_bands_and_approval_roles_come_from_the_matrix(): void
    {
        $matrix = $this->service()->matrixFor($this->companyId);

        $this->assertSame('Low', $matrix->bandLabel(1, 2));
        $this->assertSame('Critical', $matrix->bandLabel(5, 5));
        $this->assertSame('EXECUTIVE', $matrix->approvalRoleFor(25));
        $this->assertSame('SHEQ_PRACTITIONER', $matrix->approvalRoleFor(4));
    }

    // ── Approval authority is derived (SHEQ-RA-3) ───────────────────────────────────────────────

    public function test_a_critical_residual_risk_cannot_be_approved_by_a_site_officer(): void
    {
        $assessment = $this->assessmentWith([
            'activity' => 'Roof work', 'hazard' => 'Fall from height',
            'inherent_likelihood' => 5, 'inherent_severity' => 5,
            'residual_likelihood' => 4, 'residual_severity' => 5,     // score 20 → Critical
        ], [['hierarchy_level' => 'ENGINEERING', 'description' => 'Edge protection installed']]);

        $required = $this->service()->requiredApprovalRole($this->companyId, $assessment['uuid']);
        $this->assertSame('Critical', $required['band']);
        $this->assertSame('EXECUTIVE', $required['role']);

        $this->expectExceptionMessageMatches('/must be approved by EXECUTIVE/');

        $this->service()->approve($this->companyId, $assessment['uuid'], $this->userId, ['SHEQ_PRACTITIONER']);
    }

    public function test_an_executive_may_approve_the_same_assessment(): void
    {
        $assessment = $this->assessmentWith([
            'activity' => 'Roof work', 'hazard' => 'Fall from height',
            'inherent_likelihood' => 5, 'inherent_severity' => 5,
            'residual_likelihood' => 4, 'residual_severity' => 5,
        ], [['hierarchy_level' => 'ENGINEERING', 'description' => 'Edge protection']]);

        $this->service()->approve($this->companyId, $assessment['uuid'], $this->userId, ['EXECUTIVE']);

        $row = DB::table('sheq_risk_assessments')->find($assessment['id']);

        $this->assertSame('APPROVED', $row->status);
        // Frozen at approval so a later matrix edit cannot make a past approval look wrong.
        $this->assertSame('Critical', $row->approval_band);
        $this->assertSame('EXECUTIVE', $row->approval_role_required);
    }

    public function test_a_low_risk_assessment_is_approvable_by_a_practitioner(): void
    {
        $assessment = $this->assessmentWith([
            'activity' => 'Office filing', 'hazard' => 'Paper cut',
            'inherent_likelihood' => 2, 'inherent_severity' => 1,
            'residual_likelihood' => 1, 'residual_severity' => 1,
        ], [['hierarchy_level' => 'ADMINISTRATIVE', 'description' => 'Care when handling paper']]);

        $this->service()->approve($this->companyId, $assessment['uuid'], $this->userId, ['SHEQ_PRACTITIONER']);

        $this->assertSame('APPROVED', DB::table('sheq_risk_assessments')->find($assessment['id'])->status);
    }

    // ── Participation (SHEQ-RA-5) ───────────────────────────────────────────────────────────────

    public function test_an_assessment_with_no_participants_cannot_be_approved(): void
    {
        $assessment = $this->assessmentWith([
            'activity' => 'Welding', 'hazard' => 'Arc flash',
            'inherent_likelihood' => 3, 'inherent_severity' => 3,
            'residual_likelihood' => 1, 'residual_severity' => 2,
        ], [['hierarchy_level' => 'PPE', 'description' => 'Welding screen']], withParticipant: false);

        $this->expectExceptionMessageMatches('/no recorded participants/');

        $this->service()->approve($this->companyId, $assessment['uuid'], $this->userId, ['EXECUTIVE']);
    }

    // ── Weak controls (SHEQ-RA-2) ───────────────────────────────────────────────────────────────

    public function test_a_high_risk_controlled_only_by_ppe_is_reported_as_a_finding(): void
    {
        $assessment = $this->assessmentWith([
            'activity' => 'Angle grinding', 'hazard' => 'Fragment to the eye',
            'inherent_likelihood' => 4, 'inherent_severity' => 4,
            'residual_likelihood' => 3, 'residual_severity' => 4,     // score 12 → High
        ], [
            ['hierarchy_level' => 'PPE', 'description' => 'Face shield'],
            ['hierarchy_level' => 'ADMINISTRATIVE', 'description' => 'Toolbox talk'],
        ]);

        $findings = $this->service()->weakControlFindings($this->companyId, $assessment['uuid']);

        $this->assertCount(1, $findings);
        $this->assertSame('High', $findings[0]['residual_band']);
        $this->assertStringContainsString('hierarchy of control', $findings[0]['message']);
    }

    public function test_a_high_risk_with_an_engineering_control_is_not_a_finding(): void
    {
        $assessment = $this->assessmentWith([
            'activity' => 'Angle grinding', 'hazard' => 'Fragment to the eye',
            'inherent_likelihood' => 4, 'inherent_severity' => 4,
            'residual_likelihood' => 3, 'residual_severity' => 4,
        ], [
            ['hierarchy_level' => 'ENGINEERING', 'description' => 'Fixed guard and extraction'],
            ['hierarchy_level' => 'PPE', 'description' => 'Face shield'],
        ]);

        $this->assertSame([], $this->service()->weakControlFindings($this->companyId, $assessment['uuid']));
    }

    public function test_a_high_risk_with_no_controls_at_all_is_a_finding(): void
    {
        $assessment = $this->assessmentWith([
            'activity' => 'Confined space entry', 'hazard' => 'Oxygen deficiency',
            'inherent_likelihood' => 4, 'inherent_severity' => 5,
            'residual_likelihood' => 4, 'residual_severity' => 4,
        ], []);

        $findings = $this->service()->weakControlFindings($this->companyId, $assessment['uuid']);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('nothing recorded', $findings[0]['message']);
    }

    public function test_a_low_residual_risk_with_ppe_only_is_acceptable(): void
    {
        // The finding is about *high* risks managed weakly, not about PPE existing.
        $assessment = $this->assessmentWith([
            'activity' => 'Cleaning', 'hazard' => 'Detergent contact',
            'inherent_likelihood' => 2, 'inherent_severity' => 2,
            'residual_likelihood' => 1, 'residual_severity' => 2,
        ], [['hierarchy_level' => 'PPE', 'description' => 'Gloves']]);

        $this->assertSame([], $this->service()->weakControlFindings($this->companyId, $assessment['uuid']));
    }

    // ── Versioning (SHEQ-RA-4) ──────────────────────────────────────────────────────────────────

    public function test_an_approved_assessment_cannot_be_edited(): void
    {
        $assessment = $this->assessmentWith([
            'activity' => 'Stacking', 'hazard' => 'Falling stock',
            'inherent_likelihood' => 2, 'inherent_severity' => 2,
            'residual_likelihood' => 1, 'residual_severity' => 2,
        ], [['hierarchy_level' => 'ENGINEERING', 'description' => 'Racking restraints']]);

        $this->service()->approve($this->companyId, $assessment['uuid'], $this->userId, ['SHEQ_PRACTITIONER']);

        $this->expectExceptionMessageMatches('/cannot be edited/');

        $this->service()->addLine($this->companyId, $assessment['uuid'], [
            'activity' => 'Sneaky change', 'hazard' => 'x',
            'inherent_likelihood' => 1, 'inherent_severity' => 1,
        ]);
    }

    public function test_a_revision_copies_lines_forward_and_supersedes_only_on_approval(): void
    {
        $assessment = $this->assessmentWith([
            'activity' => 'Stacking', 'hazard' => 'Falling stock',
            'inherent_likelihood' => 3, 'inherent_severity' => 3,
            'residual_likelihood' => 2, 'residual_severity' => 2,
        ], [['hierarchy_level' => 'ENGINEERING', 'description' => 'Racking restraints']]);

        $this->service()->approve($this->companyId, $assessment['uuid'], $this->userId, ['SHEQ_PRACTITIONER']);

        $revision = $this->service()->createRevision($this->companyId, $assessment['uuid'], $this->userId, 'INCIDENT');

        $this->assertSame(2, $revision['version']);
        $this->assertSame($assessment['reference'], $revision['reference'], 'A revision keeps the reference.');
        $this->assertSame(1, DB::table('sheq_risk_lines')->where('risk_assessment_id', $revision['id'])->count());
        $this->assertSame(1, DB::table('sheq_controls')
            ->whereIn('risk_line_id', DB::table('sheq_risk_lines')
                ->where('risk_assessment_id', $revision['id'])->pluck('id'))->count());

        // The predecessor stays APPROVED until the revision is approved — never a gap with none in force.
        $this->assertSame('APPROVED', DB::table('sheq_risk_assessments')->find($assessment['id'])->status);

        $this->service()->addParticipant($this->companyId, $revision['uuid'], ['name' => 'S. Dlamini']);
        $this->service()->approve($this->companyId, $revision['uuid'], $this->userId, ['SHEQ_PRACTITIONER']);

        $this->assertSame('SUPERSEDED', DB::table('sheq_risk_assessments')->find($assessment['id'])->status);
        $this->assertSame('APPROVED', DB::table('sheq_risk_assessments')->find($revision['id'])->status);
    }

    public function test_the_score_is_computed_by_the_database_not_the_application(): void
    {
        $assessment = $this->assessmentWith([
            'activity' => 'Lifting', 'hazard' => 'Load drop',
            'inherent_likelihood' => 4, 'inherent_severity' => 3,
            'residual_likelihood' => 2, 'residual_severity' => 3,
        ]);

        $line = DB::table('sheq_risk_lines')->where('risk_assessment_id', $assessment['id'])->first();

        $this->assertSame(12, (int) $line->inherent_score);
        $this->assertSame(6, (int) $line->residual_score);
        $this->assertSame('High', $line->inherent_band);
        $this->assertSame('Medium', $line->residual_band);
    }

    public function test_the_active_assessment_for_a_site_is_the_approved_one(): void
    {
        $assessment = $this->assessmentWith([
            'activity' => 'General', 'hazard' => 'Slips',
            'inherent_likelihood' => 2, 'inherent_severity' => 2,
            'residual_likelihood' => 1, 'residual_severity' => 1,
        ], [['hierarchy_level' => 'ENGINEERING', 'description' => 'Non-slip flooring']]);

        $this->assertNull($this->service()->activeForSite($this->companyId, null),
            'A draft assessment is not in force.');

        $this->service()->approve($this->companyId, $assessment['uuid'], $this->userId, ['SHEQ_PRACTITIONER']);

        $this->assertNotNull($this->service()->activeForSite($this->companyId, null));
    }
}
