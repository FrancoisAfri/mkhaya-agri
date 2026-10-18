<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Services\RiskIntelligenceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Risk intelligence (RISK-1 to RISK-5).
 *
 * The HIRA engine was the most complete thing in the module and could not notice that any of it
 * had stopped working. A site could fail the same checklist question eleven times and the
 * assessment would still read "control implemented: yes", because somebody ticked that box in
 * 2024 and nothing had contradicted it since.
 *
 * The test that matters most is the contradiction: evidence of repeated failure AND an approved
 * assessment that still counts the control. Either alone is ordinary; together they are what
 * makes a risk assessment worth less than the paper it is printed on.
 */
class RiskIntelligenceTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

    private int $siteId;

    private int $assessmentId;

    private int $lineId;

    private int $controlId;

    private string $controlUuid;

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
        $this->user = $this->userWith(['sheq.risk_assessment.author', 'sheq.configure']);

        $this->siteId = (int) DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'R'.Str::upper(Str::random(5)), 'name' => 'Decline',
            'site_type' => 'MINE', 'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $matrix = app(\App\Modules\Operations\Sheq\Services\RiskAssessmentService::class)
            ->matrixFor($this->companyId);

        $this->assessmentId = (int) DB::table('sheq_risk_assessments')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'RA-'.Str::upper(Str::random(5)), 'ra_type' => 'BASELINE',
            'title' => 'Working at height', 'site_id' => $this->siteId,
            'matrix_id' => $matrix->id, 'status' => 'APPROVED', 'version' => 1,
            'effective_from' => Carbon::today()->subMonths(6)->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->lineId = (int) DB::table('sheq_risk_lines')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'risk_assessment_id' => $this->assessmentId, 'sequence' => 1,
            'activity' => 'Working at height', 'hazard' => 'Fall from scaffold',
            'potential_harm' => 'Fatality',
            // `inherent_score` and `residual_score` are generated columns — Postgres computes
            // them from the likelihood and severity, and inserting them is refused outright.
            'inherent_likelihood' => 4, 'inherent_severity' => 5,
            'residual_likelihood' => 2, 'residual_severity' => 5,
            'is_acceptable' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->controlUuid = (string) Str::uuid();

        $this->controlId = (int) DB::table('sheq_controls')->insertGetId([
            'uuid' => $this->controlUuid, 'company_id' => $this->companyId,
            'risk_line_id' => $this->lineId, 'hierarchy_level' => 'ENGINEERING',
            'description' => 'Edge protection to all open sides',
            'control_type' => 'PHYSICAL', 'is_implemented' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function service(): RiskIntelligenceService
    {
        return app(RiskIntelligenceService::class);
    }

    // ── RISK-1 ────────────────────────────────────────────────────────────────────────────────

    public function test_all_five_deterioration_signals_the_requirement_names_are_detected(): void
    {
        foreach (['RESIDUAL_INCREASED', 'CONTROL_REPEATEDLY_FAILED', 'REPEATED_INCIDENT',
            'REPEATED_INSPECTION_FAILURE', 'REPEATED_UNSAFE_OBSERVATION'] as $signal) {
            $this->assertArrayHasKey($signal, RiskIntelligenceService::SIGNALS,
                "RISK-1 lists {$signal} and nothing detects it.");

            $this->assertNotSame('', trim(RiskIntelligenceService::SIGNALS[$signal]['note']),
                "The {$signal} signal has no explanation, so a reader cannot act on it.");
        }

        $deterioration = $this->service()->deterioration($this->companyId);

        foreach (['residual_increased', 'controls_failing', 'repeated_incidents',
            'repeated_inspection_failures', 'repeated_observations'] as $list) {
            $this->assertArrayHasKey($list, $deterioration);
        }
    }

    public function test_a_residual_risk_that_goes_up_is_reported_and_one_that_goes_down_is_not(): void
    {
        /*
         * A residual risk going UP after a reassessment means either the controls stopped working
         * or somebody was optimistic the first time, and both deserve attention. A decrease is
         * the system working, and reporting it as deterioration would bury the increases.
         */
        $this->service()->recordMovement($this->companyId, [
            'risk_assessment_id' => $this->assessmentId, 'risk_line_id' => $this->lineId,
            'activity' => 'Working at height', 'hazard' => 'Fall from scaffold',
            'site_id' => $this->siteId, 'from_score' => 10, 'to_score' => 16,
            'trigger' => 'REASSESSMENT',
        ]);

        $this->service()->recordMovement($this->companyId, [
            'risk_assessment_id' => $this->assessmentId, 'risk_line_id' => $this->lineId,
            'activity' => 'Scaffold erection', 'hazard' => 'Collapse',
            'site_id' => $this->siteId, 'from_score' => 16, 'to_score' => 8,
        ]);

        $increased = $this->service()->deterioration($this->companyId)['residual_increased'];

        $this->assertCount(1, $increased);
        $this->assertSame('Working at height', $increased[0]['activity']);
        $this->assertSame(16, (int) $increased[0]['to_score']);
    }

    public function test_a_control_verified_as_failed_twice_is_reported_as_failing(): void
    {
        foreach (range(1, 2) as $n) {
            $this->service()->verifyControl($this->companyId, $this->controlId, 'FAILED',
                ['source_type' => 'INSPECTION', 'source_reference' => 'INSP-'.$n],
                $this->user->id);
        }

        $failing = $this->service()->deterioration($this->companyId)['controls_failing'];

        $this->assertCount(1, $failing);
        $this->assertSame(2, (int) $failing[0]['consecutive_failures']);

        // The contradiction worth leading with: still recorded as implemented.
        $this->assertTrue($failing[0]['still_claimed_implemented']);
    }

    public function test_an_effective_result_resets_the_consecutive_count(): void
    {
        /*
         * What matters is whether the control is failing NOW. A control that failed three times
         * last year and has been fine since is not a current problem, and a count that never
         * reset would keep insisting it is.
         */
        foreach (range(1, 3) as $n) {
            $this->service()->verifyControl($this->companyId, $this->controlId, 'FAILED',
                [], $this->user->id);
        }

        $this->assertSame(3, (int) DB::table('sheq_controls')
            ->where('id', $this->controlId)->value('consecutive_failures'));

        $this->service()->verifyControl($this->companyId, $this->controlId, 'EFFECTIVE',
            [], $this->user->id);

        $this->assertSame(0, (int) DB::table('sheq_controls')
            ->where('id', $this->controlId)->value('consecutive_failures'));

        $this->assertSame([], $this->service()
            ->deterioration($this->companyId)['controls_failing']);
    }

    public function test_the_same_incident_twice_at_one_site_is_reported_and_once_is_not(): void
    {
        foreach (range(1, 2) as $n) {
            DB::table('sheq_incidents')->insert([
                'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
                'reference' => 'INC-'.Str::upper(Str::random(6)), 'site_id' => $this->siteId,
                'incident_type' => 'FALL_FROM_HEIGHT', 'occurred_at' => now()->subDays($n * 10),
                'reported_at' => now(), 'description' => 'Fell from the second lift.',
                'potential_severity' => 5, 'status' => 'OPEN',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('sheq_incidents')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'INC-'.Str::upper(Str::random(6)), 'site_id' => $this->siteId,
            'incident_type' => 'HAND_INJURY', 'occurred_at' => now()->subDays(5),
            'reported_at' => now(), 'description' => 'Cut hand.',
            'potential_severity' => 2, 'status' => 'OPEN',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $repeated = $this->service()->deterioration($this->companyId)['repeated_incidents'];

        $types = array_column($repeated, 'incident_type');

        $this->assertContains('FALL_FROM_HEIGHT', $types);
        // One is an event; the same one twice is a cause nobody has found.
        $this->assertNotContains('HAND_INJURY', $types);

        $fall = collect($repeated)->firstWhere('incident_type', 'FALL_FROM_HEIGHT');
        $this->assertSame(2, (int) $fall['total']);
        $this->assertSame(2, (int) $fall['high_potential']);
    }

    public function test_a_positive_observation_recurring_is_not_deterioration(): void
    {
        // Reporting good practice repeatedly as deterioration is how a behavioural programme
        // gets switched off.
        DB::table('sheq_behaviours')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'GOOD_PRACTICE', 'name' => 'Good practice', 'category' => 'POSITIVE',
            'is_high_consequence' => false, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (range(1, 3) as $n) {
            DB::table('sheq_observations')->insert([
                'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
                'reference' => 'OBS-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
                'observation_type' => 'POSITIVE', 'site_id' => $this->siteId,
                'behaviour_code' => 'GOOD_PRACTICE', 'behaviour' => 'Clipped on throughout.',
                'observed_at' => now()->subDays($n), 'status' => 'OPEN',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->assertSame([], $this->service()
            ->deterioration($this->companyId)['repeated_observations']);
    }

    // ── RISK-2 ────────────────────────────────────────────────────────────────────────────────

    public function test_trends_are_reported_every_way_the_requirement_asks_for(): void
    {
        $trends = $this->service()->trends($this->companyId);

        foreach (['by_site', 'by_activity', 'by_hazard', 'by_contractor',
            'by_department'] as $cut) {
            $this->assertArrayHasKey($cut, $trends,
                "RISK-2 asks for a trend by {$cut} and nothing produces one.");
        }

        // The assessed-risk cuts come from the risk lines in force.
        $site = collect($trends['by_site'])->firstWhere('site_id', $this->siteId);

        $this->assertNotNull($site);
        $this->assertSame(1, (int) $site['lines']);
        $this->assertSame(10, (int) $site['highest_residual']);
    }

    public function test_an_unapproved_assessment_is_not_counted_in_the_trend(): void
    {
        /*
         * A draft assessment is somebody's working document. Counting it would let an
         * unreviewed guess move the figure a manager reads.
         */
        DB::table('sheq_risk_assessments')->where('id', $this->assessmentId)
            ->update(['status' => 'DRAFT']);

        $this->assertSame([], $this->service()->trends($this->companyId)['by_site']);
    }

    // ── RISK-3 ────────────────────────────────────────────────────────────────────────────────

    public function test_a_control_recorded_as_implemented_while_failing_is_the_headline(): void
    {
        /*
         * The finding the whole section exists for. Either half alone is ordinary — a control
         * that failed and has been marked not implemented is being managed — and together they
         * are a risk assessment asserting a barrier that is not there.
         */
        foreach (range(1, 3) as $n) {
            $this->service()->verifyControl($this->companyId, $this->controlId, 'FAILED',
                ['source_type' => 'INSPECTION'], $this->user->id);
        }

        $result = $this->service()->ineffectiveControls($this->companyId);

        $this->assertSame(1, $result['counts']['contradictions']);

        $control = $result['controls'][0];

        $this->assertTrue($control['contradiction']);
        $this->assertSame(3, $control['failed']);
        $this->assertSame(100, $control['failure_rate']);

        // Marking it not implemented removes the contradiction without hiding the failures.
        DB::table('sheq_controls')->where('id', $this->controlId)
            ->update(['is_implemented' => false]);

        $after = $this->service()->ineffectiveControls($this->companyId);

        $this->assertSame(0, $after['counts']['contradictions']);
        $this->assertSame(1, $after['counts']['failing']);
    }

    public function test_capa_reviewed_as_ineffective_counts_as_evidence_against_a_control(): void
    {
        /*
         * The most telling of the four sources the requirement names: an action raised to fix a
         * control, completed, and then reviewed as not effective is the system itself reporting
         * that the fix did not work.
         */
        DB::table('sheq_actions')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'ACT-'.Str::upper(Str::random(6)),
            'action_type' => 'CORRECTIVE', 'title' => 'Install edge protection',
            'source_type' => 'RISK_ASSESSMENT', 'source_id' => $this->controlId,
            'status' => 'VERIFIED', 'effectiveness_result' => 'NOT_EFFECTIVE',
            // NOT NULL on this table: an action with no due date is one nobody is late on.
            'due_date' => Carbon::today()->subMonth()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $control = $this->service()->ineffectiveControls($this->companyId)['controls'][0];

        $this->assertSame(1, $control['ineffective_capa']);
    }

    public function test_a_control_nobody_has_ever_checked_is_reported_as_an_assumption(): void
    {
        // Its own, quieter problem: a control nobody has verified is an assumption, and the
        // assessment presents it as a barrier.
        $result = $this->service()->ineffectiveControls($this->companyId);

        $this->assertSame(1, $result['counts']['never_verified']);
        $this->assertTrue($result['controls'][0]['never_verified']);
    }

    // ── RISK-4 ────────────────────────────────────────────────────────────────────────────────

    public function test_a_control_can_be_linked_to_everything_that_enforces_it(): void
    {
        $this->actingAs($this->user)
            ->post("/sheq/risk-intelligence/controls/{$this->controlUuid}/links", [
                'linked_permit_type' => 'WORKING_AT_HEIGHT',
                'linked_activity' => 'working_at_height',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $row = DB::table('sheq_controls')->where('id', $this->controlId)
            ->first(['linked_permit_type', 'linked_activity']);

        $this->assertSame('WORKING_AT_HEIGHT', $row->linked_permit_type);
        // Normalised, so the board and the gate agree on the spelling.
        $this->assertSame('WORKING_AT_HEIGHT', $row->linked_activity);

        $control = $this->service()->ineffectiveControls($this->companyId)['controls'][0];

        $this->assertContains('a permit', $control['enforced_by']);
        $this->assertContains('an activity', $control['enforced_by']);
    }

    public function test_a_control_with_nothing_enforcing_it_is_counted(): void
    {
        /*
         * A control that is only a sentence in a document nobody is trained on, that no
         * inspection checks and no permit requires, is a control in name only — and that is most
         * of them on most assessments.
         */
        $this->assertSame(1,
            $this->service()->ineffectiveControls($this->companyId)['counts']['unenforced']);

        DB::table('sheq_controls')->where('id', $this->controlId)
            ->update(['linked_activity' => 'WORKING_AT_HEIGHT']);

        $this->assertSame(0,
            $this->service()->ineffectiveControls($this->companyId)['counts']['unenforced']);
    }

    // ── RISK-5 ────────────────────────────────────────────────────────────────────────────────

    public function test_the_board_shows_everything_the_requirement_lists(): void
    {
        $contractorId = (int) DB::table('sheq_contractors')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'name' => 'Height Specialists', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sheq_controls')->where('id', $this->controlId)
            ->update(['linked_permit_type' => 'WORKING_AT_HEIGHT']);

        $permitId = (int) DB::table('sheq_permits')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'PTW-'.Str::upper(Str::random(5)),
            'permit_type' => 'WORKING_AT_HEIGHT', 'site_id' => $this->siteId,
            'contractor_id' => $contractorId,
            'risk_assessment_id' => $this->assessmentId,
            'work_description' => 'Install handrail on the third lift.',
            'location_text' => 'North elevation',
            'valid_from' => now()->subHour(), 'valid_to' => now()->addHours(6),
            'status' => 'ACTIVE', 'issuer_id' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sheq_permit_workers')->insert([
            'company_id' => $this->companyId, 'permit_id' => $permitId,
            'name' => 'T. Mokoena', 'worker_role' => 'ERECTOR',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $board = $this->service()->highRiskBoard($this->companyId);

        $this->assertCount(1, $board['permits']);

        $p = $board['permits'][0];

        // RISK-5's list: activity, site, workers, contractor, HIRA, permit, controls,
        // responsible person.
        $this->assertSame('WORKING_AT_HEIGHT', $p['permit_type']);
        $this->assertSame('Decline', $p['site_name']);
        $this->assertSame(1, $p['worker_count']);
        $this->assertSame('Height Specialists', $p['contractor_name']);
        $this->assertNotNull($p['assessment_reference']);
        $this->assertNotEmpty($p['controls']);
        $this->assertNotNull($p['responsible']);
        $this->assertFalse($p['no_assessment']);
    }

    public function test_the_board_flags_a_live_permit_with_no_risk_assessment(): void
    {
        DB::table('sheq_permits')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'PTW-'.Str::upper(Str::random(5)), 'permit_type' => 'HOT_WORK',
            'site_id' => $this->siteId, 'work_description' => 'Cutting a bracket.',
            'valid_from' => now()->subHour(), 'valid_to' => now()->addHours(3),
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $board = $this->service()->highRiskBoard($this->companyId);

        $this->assertSame(1, $board['counts']['without_assessment']);
        $this->assertTrue($board['permits'][0]['no_assessment']);
    }

    public function test_the_board_counts_who_is_actually_on_site_not_who_was_authorised(): void
    {
        /*
         * From the attendance register rather than the permits. In an evacuation the question is
         * who is inside, and a board showing only permits is showing paperwork.
         */
        DB::table('sheq_site_attendance')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'site_id' => $this->siteId, 'subject_type' => 'CONTRACTOR_WORKER',
            'subject_id' => 1, 'person_name' => 'Somebody', 'entered_at' => now()->subHours(2),
            'entry_method' => 'QR', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(1, $this->service()->highRiskBoard($this->companyId)['on_site']);
    }

    public function test_a_closed_permit_is_not_on_the_board(): void
    {
        // The board is about work in progress. A closed permit records work that is finished.
        DB::table('sheq_permits')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'PTW-'.Str::upper(Str::random(5)), 'permit_type' => 'HOT_WORK',
            'site_id' => $this->siteId, 'work_description' => 'Finished yesterday.',
            'valid_from' => now()->subDays(2), 'valid_to' => now()->subDay(),
            'status' => 'CLOSED', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame([], $this->service()->highRiskBoard($this->companyId)['permits']);
    }

    // ── The screen ────────────────────────────────────────────────────────────────────────────

    public function test_the_page_shows_the_board_the_signals_the_controls_and_the_trends(): void
    {
        $props = $this->actingAs($this->user)->get('/sheq/risk-intelligence')
            ->assertOk()->viewData('page')['props'];

        $this->assertNotNull($props['board']);
        $this->assertNotNull($props['deterioration']);
        $this->assertNotNull($props['trends']);
        $this->assertNotNull($props['ineffective']);
        $this->assertCount(count(RiskIntelligenceService::SIGNALS),
            $props['deterioration']['signals']);
    }

    public function test_verifying_a_control_needs_the_author_entitlement(): void
    {
        // It changes what an approved assessment asserts about a barrier.
        $this->actingAs($this->userWith([]))
            ->post("/sheq/risk-intelligence/controls/{$this->controlUuid}/verify",
                ['result' => 'FAILED'])
            ->assertForbidden();
    }

    private function userWith(array $permissions): User
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'risk-'.Str::random(8).'@example.test',
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
