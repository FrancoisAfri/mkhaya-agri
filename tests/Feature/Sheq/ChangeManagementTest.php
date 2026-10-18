<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Services\ChangeImpactDetector;
use App\Modules\Operations\Sheq\Services\ChangeManagementService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Management of change (MOC-1 to MOC-5).
 *
 * The only register in this module about something that has not happened yet, which is the whole
 * of its value — the one place a safety system can act before the consequences rather than after
 * them.
 *
 * The test that matters most is the gate. A register of changes with a checklist nobody has to
 * finish is a register of changes; MOC-4 is the line that makes the rest of it mean something.
 */
class ChangeManagementTest extends TestCase
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
        $this->user = $this->userWith(['sheq.hazard.report', 'sheq.configure']);

        $this->siteId = (int) DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'M'.Str::upper(Str::random(5)), 'name' => 'Process plant',
            'site_type' => 'PLANT', 'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function service(): ChangeManagementService
    {
        return app(ChangeManagementService::class);
    }

    private function register(array $overrides = []): array
    {
        return $this->service()->register($this->companyId, array_merge([
            'title' => 'Replace the transfer pump with a larger unit',
            'description' => 'P-101 swapped for a 40 kW unit.',
            'change_category' => 'EQUIPMENT',
            'site_id' => $this->siteId,
            'risk_rating' => 'HIGH',
        ], $overrides), $this->user->id);
    }

    /** An approved risk assessment in force at the site, so the detector finds something. */
    private function riskAssessment(): int
    {
        // Through the service, which creates the default matrix a risk assessment cannot exist
        // without — building the row by hand means keeping up with a NOT NULL column somebody
        // else owns.
        $matrix = app(\App\Modules\Operations\Sheq\Services\RiskAssessmentService::class)
            ->matrixFor($this->companyId);

        return (int) DB::table('sheq_risk_assessments')->insertGetId([
            'matrix_id' => $matrix->id,
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'RA-'.Str::upper(Str::random(5)), 'ra_type' => 'BASELINE',
            'title' => 'Transfer pump operation', 'site_id' => $this->siteId,
            'status' => 'APPROVED', 'version' => 1,
            'effective_from' => Carbon::today()->subMonth()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── MOC-1 ─────────────────────────────────────────────────────────────────────────────────

    public function test_all_nine_kinds_of_change_the_requirement_lists_are_supported(): void
    {
        foreach (['EQUIPMENT', 'PROCESS', 'CHEMICAL', 'CONTRACTOR', 'SITE', 'ORGANISATION',
            'LEGISLATION', 'WORK_METHOD', 'OPERATING_HOURS'] as $category) {
            $this->assertArrayHasKey($category, ChangeManagementService::CATEGORIES,
                "MOC-1 lists {$category} changes and the register cannot record one.");

            $created = $this->register(['change_category' => $category]);

            $this->assertSame($category, DB::table('sheq_changes')
                ->where('id', $created['id'])->value('change_category'));
        }
    }

    public function test_a_change_has_to_say_what_kind_it_is(): void
    {
        $this->expectException(RuntimeException::class);

        $this->register(['change_category' => 'SOMETHING']);
    }

    // ── MOC-2 ─────────────────────────────────────────────────────────────────────────────────

    public function test_all_eight_kinds_of_impact_the_requirement_lists_are_detected(): void
    {
        /*
         * A category silently missing from the detector is the failure this guards: the checklist
         * looks complete and the one thing nobody thought about is simply absent from it.
         */
        foreach (['HIRA', 'PERMIT', 'SOP', 'COMPETENCY', 'INDUCTION', 'ENVIRONMENTAL',
            'EMERGENCY_PLAN', 'QUALITY'] as $kind) {
            $this->assertArrayHasKey($kind, ChangeImpactDetector::IMPACT_TYPES,
                "MOC-2 lists {$kind} and nothing detects it.");

            $this->assertNotSame('', trim(ChangeImpactDetector::IMPACT_TYPES[$kind]['note']),
                "The {$kind} impact has no explanation, so a reviewer cannot tell why it is asked.");
        }

        // And every change category maps to kinds that actually exist.
        foreach (ChangeImpactDetector::CATEGORY_IMPACTS as $category => $kinds) {
            $this->assertArrayHasKey($category, ChangeManagementService::CATEGORIES);

            foreach ($kinds as $kind) {
                $this->assertArrayHasKey($kind, ChangeImpactDetector::IMPACT_TYPES,
                    "{$category} changes are mapped to a {$kind} impact that does not exist.");
            }
        }
    }

    public function test_registering_a_change_finds_what_is_in_force_at_the_site(): void
    {
        $this->riskAssessment();

        $created = $this->register();

        $impacts = DB::table('sheq_change_impacts')
            ->where('change_id', $created['id'])->get(['impact_type', 'subject_reference',
                'detected_automatically', 'status']);

        $this->assertGreaterThan(0, $created['impacts']);

        $hira = $impacts->firstWhere('impact_type', 'HIRA');

        $this->assertNotNull($hira, 'The risk assessment in force at the site was not found.');
        $this->assertTrue((bool) $hira->detected_automatically);
        $this->assertSame('OUTSTANDING', $hira->status);

        // Quality has no register of its own, so it is raised as a question rather than omitted.
        $this->assertNotNull($impacts->firstWhere('impact_type', 'QUALITY'));
    }

    public function test_re_running_the_detector_does_not_undo_somebodys_answers(): void
    {
        /*
         * An impact somebody marked done or not applicable is a decision with a reason attached.
         * A detector that cleared it on the next run would delete their work, and then they
         * would stop answering the list.
         */
        $this->riskAssessment();
        $created = $this->register();

        $impact = DB::table('sheq_change_impacts')
            ->where('change_id', $created['id'])->where('impact_type', 'HIRA')->first(['uuid']);

        $this->service()->decideImpact($this->companyId, $impact->uuid, 'DONE',
            'Assessment revised to version 2.', $this->user->id);

        $change = $this->service()->find($this->companyId, $created['uuid']);
        $added = $this->service()->refreshImpacts($this->companyId, $change);

        $this->assertSame(0, $added);
        $this->assertSame('DONE', DB::table('sheq_change_impacts')
            ->where('uuid', $impact->uuid)->value('status'));
    }

    public function test_dismissing_a_control_needs_a_reason_and_completing_one_does_not(): void
    {
        /*
         * NOT_APPLICABLE is the hole in this whole feature and the easiest to walk through under
         * deadline pressure: a mandatory control cleared with one click and no explanation looks
         * identical to one that was genuinely irrelevant.
         */
        $this->riskAssessment();
        $created = $this->register();

        $impacts = DB::table('sheq_change_impacts')
            ->where('change_id', $created['id'])->limit(2)->get(['uuid']);

        try {
            $this->service()->decideImpact($this->companyId, $impacts[0]->uuid,
                'NOT_APPLICABLE', null, $this->user->id);

            $this->fail('A mandatory control was dismissed with no reason given.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('why', strtolower($e->getMessage()));
        }

        // Completing one needs no justification — the work itself is the evidence.
        $this->service()->decideImpact($this->companyId, $impacts[1]->uuid, 'DONE',
            null, $this->user->id);

        $this->assertSame('DONE', DB::table('sheq_change_impacts')
            ->where('uuid', $impacts[1]->uuid)->value('status'));
    }

    // ── MOC-3 ─────────────────────────────────────────────────────────────────────────────────

    public function test_a_change_cannot_be_submitted_without_a_risk_rating(): void
    {
        // The rating decides who is authorised to approve it, so without one there is nobody to
        // send it to.
        $created = $this->register(['risk_rating' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/rate the risk/i');

        $this->service()->submit($this->companyId, $created['uuid'], $this->user->id);
    }

    public function test_the_risk_rating_is_what_routes_the_approval(): void
    {
        /*
         * Carried as the approval subtype, so a host configures "who approves a critical change"
         * once and every critical change routes there. A single approver for every change is the
         * version of this that gets rubber-stamped.
         */
        $created = $this->register(['risk_rating' => 'CRITICAL']);

        $result = $this->service()->submit($this->companyId, $created['uuid'], $this->user->id);

        $this->assertSame(ChangeManagementService::SUBMITTED, $result['status']);

        /*
         * No workflow is configured in this fixture, so none was opened — and that is reported
         * rather than hidden. A tenant with no workflow for a risk band has the change advance
         * with nobody having approved it, and the absence of an approver must not read as an
         * approval.
         */
        $this->assertFalse($result['approval_opened']);
    }

    public function test_segregation_of_duties_is_not_reimplemented_here(): void
    {
        /*
         * MOC-3 says a requester cannot approve their own change. `ApprovalService` already
         * refuses that at every step, whatever roles the person holds — so this asserts the
         * reliance rather than duplicating the rule, because a second implementation is a second
         * place for it to be wrong.
         */
        $source = file_get_contents(app_path(
            'Modules/Operations/Sheq/Services/ChangeManagementService.php'));

        $this->assertStringContainsString('ApprovalService', $source);

        $approvals = file_get_contents(app_path(
            'Modules/Operations/Sheq/Approvals/ApprovalService.php'));

        $this->assertStringContainsString('Self-approval is impossible', $approvals,
            'The guarantee this feature relies on is no longer documented in ApprovalService.');
    }

    // ── MOC-4: the gate ───────────────────────────────────────────────────────────────────────

    public function test_a_change_cannot_be_implemented_while_a_mandatory_control_is_outstanding(): void
    {
        /*
         * The line that makes the rest of the feature mean anything. Everything else here is a
         * register, and a register whose checklist is advisory changes nothing about how changes
         * are made.
         */
        $this->riskAssessment();
        $created = $this->register();

        try {
            $this->service()->implement($this->companyId, $created['uuid'], $this->user->id);

            $this->fail('A change was implemented with a mandatory control outstanding.');
        } catch (RuntimeException $e) {
            // Named, not merely refused: "cannot be implemented" with no reason is how somebody
            // makes the change anyway and records it afterwards.
            $this->assertStringContainsString('Risk assessments', $e->getMessage());
        }

        $this->assertNotSame(ChangeManagementService::IMPLEMENTED,
            DB::table('sheq_changes')->where('id', $created['id'])->value('status'));
    }

    public function test_a_change_can_be_implemented_once_everything_mandatory_is_handled(): void
    {
        $this->riskAssessment();
        $created = $this->register();

        foreach (DB::table('sheq_change_impacts')->where('change_id', $created['id'])
            ->where('is_mandatory', true)->get(['uuid']) as $impact) {
            $this->service()->decideImpact($this->companyId, $impact->uuid, 'DONE',
                null, $this->user->id);
        }

        $this->service()->submit($this->companyId, $created['uuid'], $this->user->id);
        $this->service()->implement($this->companyId, $created['uuid'], $this->user->id);

        $row = DB::table('sheq_changes')->where('id', $created['id'])->first();

        $this->assertSame(ChangeManagementService::IMPLEMENTED, $row->status);
        $this->assertNotNull($row->implemented_at);
    }

    public function test_a_non_mandatory_impact_does_not_block_the_change(): void
    {
        /*
         * Reviewing a quality specification can reasonably follow a change; re-inducting a crew
         * cannot. Making everything mandatory means the first change is implemented by somebody
         * switching the gate off.
         */
        $created = $this->register(['change_category' => 'OPERATING_HOURS']);

        DB::table('sheq_change_impacts')->where('change_id', $created['id'])
            ->update(['is_mandatory' => false]);

        $this->service()->submit($this->companyId, $created['uuid'], $this->user->id);
        $this->service()->implement($this->companyId, $created['uuid'], $this->user->id);

        $this->assertSame(ChangeManagementService::IMPLEMENTED,
            DB::table('sheq_changes')->where('id', $created['id'])->value('status'));
    }

    public function test_a_rejected_change_cannot_be_implemented(): void
    {
        $created = $this->register();

        DB::table('sheq_change_impacts')->where('change_id', $created['id'])
            ->update(['status' => 'DONE']);

        $this->service()->reject($this->companyId, $created['uuid'],
            'The larger pump exceeds the bund capacity.', $this->user->id);

        $this->expectException(RuntimeException::class);
        $this->service()->implement($this->companyId, $created['uuid'], $this->user->id);
    }

    // ── MOC-5 ─────────────────────────────────────────────────────────────────────────────────

    public function test_implementing_a_change_diarises_the_review_by_risk(): void
    {
        /*
         * A date rather than an intention. "We will review it" with no date is the same as no
         * review, and a field somebody fills in at the end of a long form is a field left empty.
         */
        foreach (['CRITICAL' => 14, 'LOW' => 90] as $rating => $days) {
            $created = $this->register(['risk_rating' => $rating]);

            DB::table('sheq_change_impacts')->where('change_id', $created['id'])
                ->update(['status' => 'DONE']);

            $this->service()->submit($this->companyId, $created['uuid'], $this->user->id);
            $this->service()->implement($this->companyId, $created['uuid'], $this->user->id);

            $this->assertSame(Carbon::today()->addDays($days)->toDateString(),
                DB::table('sheq_changes')->where('id', $created['id'])
                    ->value('effectiveness_review_due'),
                "A {$rating} change was not diarised {$days} days out.");
        }
    }

    public function test_a_change_cannot_be_reviewed_before_it_is_implemented(): void
    {
        $created = $this->register();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/implemented/i');

        $this->service()->review($this->companyId, $created['uuid'],
            ['effectiveness_result' => 'EFFECTIVE'], $this->user->id);
    }

    public function test_a_change_that_did_not_work_has_to_say_what_happened(): void
    {
        /*
         * NOT_EFFECTIVE with no notes is the review that tells nobody anything: the next person
         * cannot tell whether the change was wrong or merely unfinished, and the review has to be
         * done again from memory.
         */
        $created = $this->implemented();

        try {
            $this->service()->review($this->companyId, $created['uuid'],
                ['effectiveness_result' => 'NOT_EFFECTIVE'], $this->user->id);

            $this->fail('A failed change was reviewed with no explanation.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('what did not work', strtolower($e->getMessage()));
        }

        // And one that worked needs none.
        $this->service()->review($this->companyId, $created['uuid'],
            ['effectiveness_result' => 'EFFECTIVE'], $this->user->id);

        $row = DB::table('sheq_changes')->where('id', $created['id'])->first();

        $this->assertSame(ChangeManagementService::REVIEWED, $row->status);
        $this->assertSame('EFFECTIVE', $row->effectiveness_result);
        $this->assertNotNull($row->effectiveness_reviewed_at);
    }

    /** A change taken all the way to implemented. */
    private function implemented(): array
    {
        $created = $this->register();

        DB::table('sheq_change_impacts')->where('change_id', $created['id'])
            ->update(['status' => 'DONE']);

        $this->service()->submit($this->companyId, $created['uuid'], $this->user->id);
        $this->service()->implement($this->companyId, $created['uuid'], $this->user->id);

        return $created;
    }

    // ── The screen ────────────────────────────────────────────────────────────────────────────

    public function test_the_page_shows_the_change_with_what_is_stopping_it(): void
    {
        $this->riskAssessment();
        $created = $this->register();

        $props = $this->actingAs($this->user)
            ->get('/sheq/changes?change='.$created['uuid'])
            ->assertOk()->viewData('page')['props'];

        $this->assertNotEmpty($props['rows']);
        $this->assertNotNull($props['detail']);
        $this->assertNotEmpty($props['detail']['impacts']);
        // The blockers as sentences, because a change can be blocked for half a dozen reasons at
        // once and a disabled button cannot say which.
        $this->assertNotEmpty($props['detail']['blockers']);
        $this->assertCount(count(ChangeImpactDetector::IMPACT_TYPES), $props['impactTypes']);
    }

    public function test_the_register_counts_blocking_separately_from_outstanding(): void
    {
        // Four outstanding of which none is mandatory is a different morning from one of which
        // it is.
        $this->riskAssessment();
        $created = $this->register();

        DB::table('sheq_change_impacts')->where('change_id', $created['id'])
            ->update(['is_mandatory' => false]);

        $row = collect($this->service()->all($this->companyId))
            ->firstWhere('reference', $created['reference']);

        $this->assertGreaterThan(0, $row['impacts_outstanding']);
        $this->assertSame(0, $row['blocking_outstanding']);
    }

    public function test_implementing_requires_the_entitlement(): void
    {
        $created = $this->register();

        $this->actingAs($this->userWith(['sheq.hazard.report']))
            ->post("/sheq/changes/{$created['uuid']}/implement")
            ->assertForbidden();
    }

    public function test_the_screen_refuses_to_implement_with_the_reasons_listed(): void
    {
        $this->riskAssessment();
        $created = $this->register();

        $this->actingAs($this->user)
            ->post("/sheq/changes/{$created['uuid']}/implement")
            ->assertRedirect()
            ->assertSessionHasErrors('change');
    }

    public function test_anybody_who_can_report_a_hazard_can_raise_a_change(): void
    {
        // A change is raised by whoever is making it — an engineer, a buyer, a site manager —
        // not by whoever administers SHEQ.
        $this->actingAs($this->userWith(['sheq.hazard.report']))
            ->post('/sheq/changes', [
                'title' => 'Switch to a water-based degreaser',
                'change_category' => 'CHEMICAL',
                'risk_rating' => 'MEDIUM',
                'site_id' => $this->siteId,
            ])->assertRedirect()->assertSessionHasNoErrors();
    }

    private function userWith(array $permissions): User
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'moc-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

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

        $user = User::withoutGlobalScopes()->findOrFail($userId);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        $user->forgetCachedPermissions();

        return $user;
    }
}
