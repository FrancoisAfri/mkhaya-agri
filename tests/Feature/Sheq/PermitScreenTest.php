<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Services\PermitService;
use App\Modules\Operations\Sheq\Services\RiskAssessmentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The permit screen, and the decisions that move a permit.
 *
 * PermitService has carried the whole lifecycle since it was written — pre-issue checks, issue,
 * approve, suspend, reinstate, close, competency override — and until now not one of them could be
 * reached from a screen. A permit could be requested and then nothing could be done with it: no
 * issuer could see what was blocking it, nobody could issue or close one, and a permit left ACTIVE
 * after the work finished stayed that way.
 *
 * PermitToWorkTest covers the rules at the service. This file covers the half that was missing:
 * that a person can actually reach them, and that the entitlements hold on the way in.
 */
class PermitScreenTest extends TestCase
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
            'uuid' => (string) Str::uuid(), 'email' => 'ptw-ui-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->user = User::withoutGlobalScopes()->findOrFail($userId);
        $this->grantModuleAccess($userId);
        $this->ensureModuleActive();
        $this->grantPermissions(['sheq.permit.approve', 'sheq.permit.override', 'sheq.permit.request']);

        $this->siteId = DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'P'.Str::random(5), 'name' => 'Refinery', 'site_type' => 'PLANT',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function grantModuleAccess(int $userId, int $level = 4): void
    {
        $moduleId = DB::table('security_modules')->where('code_name', 'sheq')->value('id');

        if ($moduleId === null) {
            $this->markTestSkipped('SHEQ module is not registered.');
        }

        DB::table('security_module_access')->insert([
            'module_id' => $moduleId, 'user_id' => $userId, 'access_level' => $level,
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** The entitlements the screen gates on, assigned directly so the test is about the screen. */
    private function grantPermissions(array $names): void
    {
        foreach ($names as $name) {
            $this->user->givePermissionTo($name);
        }

        $this->user->forgetCachedPermissions();
        $this->user->load('permissions');
    }

    private function ensureModuleActive(): void
    {
        $module = DB::table('security_modules')->where('code_name', 'sheq')->first(['id', 'active']);

        if ($module === null) {
            $this->markTestSkipped('SHEQ module is not registered.');
        }

        if (! $module->active) {
            DB::table('security_modules')->where('id', $module->id)->update(['active' => 1]);
        }

        Cache::forget('module_active_sheq');
    }

    /** A permit whose risk assessment is approved, so only approvals stand between it and issue. */
    private function permit(bool $withAssessment = true): array
    {
        $assessmentId = null;

        if ($withAssessment) {
            $service = app(RiskAssessmentService::class);
            $assessment = $service->create($this->companyId, [
                'title' => 'Hot work in process area', 'site_id' => $this->siteId,
            ], $this->user->id);

            $service->addLine($this->companyId, $assessment['uuid'], [
                'activity' => 'Welding', 'hazard' => 'Ignition of flammable vapour',
                'inherent_likelihood' => 4, 'inherent_severity' => 5,
                'residual_likelihood' => 2, 'residual_severity' => 3,
            ], [['hierarchy_level' => 'ENGINEERING', 'description' => 'Purge and isolate the line']]);

            $service->addParticipant($this->companyId, $assessment['uuid'], ['name' => 'Crew']);
            $service->approve($this->companyId, $assessment['uuid'], $this->user->id, ['SITE_MANAGER']);

            $assessmentId = $assessment['id'];
        }

        return app(PermitService::class)->request($this->companyId, [
            'permit_type' => 'HOT_WORK',
            'site_id' => $this->siteId,
            'work_description' => 'Weld a support bracket on line 4.',
            'valid_from' => Carbon::now()->toDateTimeString(),
            'valid_to' => Carbon::now()->addHours(8)->toDateTimeString(),
            'risk_assessment_id' => $assessmentId,
        ], [['name' => 'T. Mokoena', 'worker_role' => 'WORKER']], $this->user->id);
    }

    public function test_a_permit_row_now_leads_to_a_screen_that_shows_what_is_blocking_it(): void
    {
        $permit = $this->permit(withAssessment: false);

        $response = $this->actingAs($this->user)->get("/sheq/permits/{$permit['uuid']}");

        $response->assertOk();

        $props = $response->viewData('page')['props'];

        $this->assertSame($permit['uuid'], $props['permit']['uuid']);

        // The gate is the point of the screen: an issuer needs the reason, not just a refusal.
        $types = array_column($props['checks']['blocking'], 'type');
        $this->assertContains('NO_RISK_ASSESSMENT', $types);
    }

    public function test_the_checks_are_run_on_view_rather_than_cached_from_request_time(): void
    {
        $permit = $this->permit();

        // Nothing blocking at request time beyond approvals. Now expire the assessment underneath
        // it, the way a real one falls due for review while a permit sits waiting.
        DB::table('sheq_risk_assessments')
            ->where('company_id', $this->companyId)
            ->update(['review_due' => Carbon::now()->subDay()->toDateString()]);

        $response = $this->actingAs($this->user)->get("/sheq/permits/{$permit['uuid']}");

        $types = array_column($response->viewData('page')['props']['checks']['blocking'], 'type');

        $this->assertContains('RISK_ASSESSMENT_EXPIRED', $types,
            'A stale check means an issuer decides from the wrong facts.');
    }

    public function test_the_screen_carries_the_workers_and_the_approval_steps(): void
    {
        $permit = $this->permit();

        $props = $this->actingAs($this->user)
            ->get("/sheq/permits/{$permit['uuid']}")
            ->viewData('page')['props'];

        $this->assertSame('T. Mokoena', $props['workers'][0]['name']);
        $this->assertNotEmpty($props['approvals'], 'Approvals drive the issue gate and must be shown.');
    }

    public function test_issuing_moves_the_permit_and_is_reachable_over_http(): void
    {
        $permit = $this->permit();
        $service = app(PermitService::class);

        foreach (DB::table('sheq_permit_approvals')
            ->where('permit_id', $permit['id'])->get() as $approval) {
            $service->approve($this->companyId, $permit['uuid'], $approval->id, $this->user->id);
        }

        $this->actingAs($this->user)
            ->post("/sheq/permits/{$permit['uuid']}/issue")
            ->assertRedirect();

        $this->assertSame('ACTIVE',
            DB::table('sheq_permits')->where('id', $permit['id'])->value('status'));
    }

    public function test_a_suspension_without_a_reason_is_refused(): void
    {
        $permit = $this->permit();

        // The crew sees the reason. A suspension with none tells them nothing about what to fix.
        $this->actingAs($this->user)
            ->post("/sheq/permits/{$permit['uuid']}/suspend", ['reason' => ''])
            ->assertSessionHasErrors('reason');
    }

    public function test_a_competency_override_needs_a_real_reason(): void
    {
        $permit = $this->permit();

        // Ten characters, because "ok" is not an acceptance of personal liability.
        $this->actingAs($this->user)
            ->post("/sheq/permits/{$permit['uuid']}/override-competency", ['reason' => 'fine'])
            ->assertSessionHasErrors('reason');
    }

    public function test_another_tenants_permit_is_not_reachable(): void
    {
        $permit = $this->permit();

        $otherCompany = DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'slug' => 'other-'.Str::random(8),
            'name' => 'Other Employer', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // A permit names the people doing hazardous work and the hazards they face. Reaching one
        // from another tenant is disclosure of another employer's operation.
        $this->expectException(\RuntimeException::class);

        app(PermitService::class)->preIssueChecks((int) $otherCompany, $permit['uuid']);
    }
}
