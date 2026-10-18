<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Services\RiskAssessmentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The risk assessment screen.
 *
 * RiskAssessmentService has carried all of this since it was written — hazard lines, controls,
 * participants, the derived approval authority, revisions — and none of it could be reached. An
 * assessment could be created from the register and then nothing could be done with it, which also
 * blocked every permit that referenced it, because a permit needs an approved assessment covering
 * the work.
 *
 * RiskAssessmentTest covers the rules. This covers that a person can reach them.
 */
class RiskAssessmentScreenTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

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

        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'ra-ui-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->user = User::withoutGlobalScopes()->findOrFail($userId);

        $moduleId = DB::table('security_modules')->where('code_name', 'sheq')->value('id');

        if ($moduleId === null) {
            $this->markTestSkipped('SHEQ module is not registered.');
        }

        DB::table('security_module_access')->insert([
            'module_id' => $moduleId, 'user_id' => $userId, 'access_level' => 4,
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('security_modules')->where('id', $moduleId)->update(['active' => 1]);
        Cache::forget('module_active_sheq');

        $this->user->givePermissionTo('sheq.risk_assessment.author');
        $this->user->forgetCachedPermissions();

        $this->siteId = DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'R'.Str::random(5), 'name' => 'Workshop', 'site_type' => 'PLANT',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function assessment(): array
    {
        return app(RiskAssessmentService::class)->create($this->companyId, [
            'title' => 'Working at height', 'site_id' => $this->siteId,
        ], $this->user->id);
    }

    public function test_an_assessment_row_now_leads_somewhere(): void
    {
        $assessment = $this->assessment();

        $response = $this->actingAs($this->user)
            ->get("/sheq/risk-assessments/{$assessment['uuid']}");

        $response->assertOk();

        $props = $response->viewData('page')['props'];

        $this->assertSame($assessment['uuid'], $props['assessment']['uuid']);
        $this->assertSame([], $props['lines']);
    }

    public function test_a_hazard_line_can_be_added_with_its_controls(): void
    {
        $assessment = $this->assessment();

        $this->actingAs($this->user)
            ->post("/sheq/risk-assessments/{$assessment['uuid']}/lines", [
                'activity' => 'Working from a scaffold',
                'hazard' => 'Fall from height',
                'inherent_likelihood' => 4,
                'inherent_severity' => 5,
                'residual_likelihood' => 2,
                'residual_severity' => 4,
                'controls' => [
                    ['hierarchy_level' => 'ENGINEERING', 'description' => 'Edge protection on all open sides'],
                ],
            ])
            ->assertRedirect();

        $props = $this->actingAs($this->user)
            ->get("/sheq/risk-assessments/{$assessment['uuid']}")
            ->viewData('page')['props'];

        $this->assertCount(1, $props['lines']);
        $this->assertSame('Fall from height', $props['lines'][0]['hazard']);
        $this->assertCount(1, $props['lines'][0]['controls']);
    }

    public function test_the_approval_authority_is_derived_and_moves_as_lines_are_added(): void
    {
        $assessment = $this->assessment();

        $before = $this->actingAs($this->user)
            ->get("/sheq/risk-assessments/{$assessment['uuid']}")
            ->viewData('page')['props']['approval'];

        $this->assertNull($before['highest_score'], 'An empty assessment demands nothing yet.');

        app(RiskAssessmentService::class)->addLine($this->companyId, $assessment['uuid'], [
            'activity' => 'Hot work', 'hazard' => 'Ignition',
            'inherent_likelihood' => 5, 'inherent_severity' => 5,
            'residual_likelihood' => 5, 'residual_severity' => 5,
        ], []);

        $after = $this->actingAs($this->user)
            ->get("/sheq/risk-assessments/{$assessment['uuid']}")
            ->viewData('page')['props']['approval'];

        // SHEQ-RA-3: authority follows the matrix, so a severe line raises who must sign.
        $this->assertNotNull($after['highest_score']);
        $this->assertNotNull($after['role'],
            'A residual band with no approval role means nobody can ever sign this off.');
    }

    public function test_the_score_arrives_with_the_scale_it_is_measured_on(): void
    {
        $assessment = $this->assessment();

        app(RiskAssessmentService::class)->addLine($this->companyId, $assessment['uuid'], [
            'activity' => 'Hot work', 'hazard' => 'Ignition',
            'inherent_likelihood' => 4, 'inherent_severity' => 4,
            'residual_likelihood' => 3, 'residual_severity' => 3,
        ], []);

        $props = $this->actingAs($this->user)
            ->get("/sheq/risk-assessments/{$assessment['uuid']}")
            ->viewData('page')['props'];

        /*
         * `RiskMatrix::maxScore()` is the denominator, and it was computed by nothing.
         *
         * A bare "9" means nothing on its own: the matrix is per tenant and may be 3×3 or 6×6, so
         * the same number is a different risk in two companies. The screen shows "9 / 25" only
         * because this travels with it.
         */
        $this->assertSame(
            $props['matrix']['likelihood_levels'] * $props['matrix']['severity_levels'],
            $props['matrix']['max_score'],
        );
        $this->assertGreaterThanOrEqual($props['approval']['highest_score'],
            $props['matrix']['max_score']);
    }

    public function test_a_high_risk_held_only_by_ppe_is_flagged_on_the_screen(): void
    {
        $assessment = $this->assessment();

        app(RiskAssessmentService::class)->addLine($this->companyId, $assessment['uuid'], [
            'activity' => 'Grinding', 'hazard' => 'Eye injury from fragments',
            'inherent_likelihood' => 5, 'inherent_severity' => 5,
            'residual_likelihood' => 5, 'residual_severity' => 5,
        ], [['hierarchy_level' => 'PPE', 'description' => 'Safety glasses']]);

        $props = $this->actingAs($this->user)
            ->get("/sheq/risk-assessments/{$assessment['uuid']}")
            ->viewData('page')['props'];

        // The finding the assessment exists to produce, shown rather than left for an auditor.
        $this->assertNotEmpty($props['weakControls']);
    }

    public function test_an_approved_assessment_refuses_a_new_line_and_says_why(): void
    {
        $service = app(RiskAssessmentService::class);
        $assessment = $this->assessment();

        $service->addLine($this->companyId, $assessment['uuid'], [
            'activity' => 'Lifting', 'hazard' => 'Dropped load',
            'inherent_likelihood' => 2, 'inherent_severity' => 2,
            'residual_likelihood' => 1, 'residual_severity' => 2,
        ], [['hierarchy_level' => 'ENGINEERING', 'description' => 'Certified slings']]);

        $service->addParticipant($this->companyId, $assessment['uuid'], ['name' => 'Crew']);
        $service->approve($this->companyId, $assessment['uuid'], $this->user->id, ['SITE_MANAGER']);

        // The refusal now reaches the person as a message rather than a stack trace.
        $this->actingAs($this->user)
            ->post("/sheq/risk-assessments/{$assessment['uuid']}/lines", [
                'activity' => 'Another', 'hazard' => 'Another',
                'inherent_likelihood' => 1, 'inherent_severity' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_authoring_requires_the_entitlement(): void
    {
        $assessment = $this->assessment();

        $outsiderId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'ra-no-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $outsider = User::withoutGlobalScopes()->findOrFail($outsiderId);

        DB::table('security_module_access')->insert([
            'module_id' => DB::table('security_modules')->where('code_name', 'sheq')->value('id'),
            'user_id' => $outsiderId, 'access_level' => 4, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($outsider)
            ->post("/sheq/risk-assessments/{$assessment['uuid']}/lines", [
                'activity' => 'x', 'hazard' => 'y',
                'inherent_likelihood' => 1, 'inherent_severity' => 1,
            ])
            ->assertForbidden();
    }
}
