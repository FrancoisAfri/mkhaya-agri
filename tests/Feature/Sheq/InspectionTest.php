<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Services\InspectionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Inspections (§5.4).
 *
 * The behaviours worth defending: a failed answer raises its own CAPA, a critical failure fails the
 * whole inspection however good the score, N/A does not inflate a percentage, published templates
 * are immutable, and a location mismatch is flagged rather than blocked.
 */
class InspectionTest extends TestCase
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
            'uuid' => (string) Str::uuid(), 'email' => 'insp-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->siteId = DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'I'.Str::random(5), 'name' => 'Depot', 'site_type' => 'DEPOT',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function service(): InspectionService
    {
        return app(InspectionService::class);
    }

    /** A SANS 1475-style extinguisher check: one critical question, one that raises an action. */
    private function template(array $overrides = []): array
    {
        return $this->service()->publishTemplate($this->companyId, array_merge([
            'code' => 'FIRE-EXT-'.Str::random(4),
            'title' => 'Fire extinguisher monthly check',
            'scoring_mode' => 'WEIGHTED',
            'pass_threshold' => 80,
            'subject_type' => 'ASSET',
            'schema' => [
                'sections' => [[
                    'title' => 'Condition',
                    'questions' => [
                        ['id' => 'q1', 'label' => 'Extinguisher present in its bracket', 'weight' => 1,
                            'critical' => true, 'raise_action_on_fail' => true,
                            'action_priority' => 'CRITICAL', 'action_due_in_days' => 1],
                        ['id' => 'q2', 'label' => 'Pressure gauge in the green', 'weight' => 1,
                            'raise_action_on_fail' => true, 'action_due_in_days' => 7],
                        ['id' => 'q3', 'label' => 'Service label within date', 'weight' => 1],
                        ['id' => 'q4', 'label' => 'Signage visible', 'weight' => 1],
                    ],
                ]],
            ],
        ], $overrides), $this->userId);
    }

    // ── Template versioning (SHEQ-INS-3) ────────────────────────────────────────────────────────

    public function test_publishing_over_a_template_creates_a_new_version_and_retires_the_old(): void
    {
        $first = $this->template(['code' => 'LADDER']);
        $second = $this->service()->publishTemplate($this->companyId, [
            'code' => 'LADDER', 'title' => 'Ladder check v2',
            'schema' => ['sections' => [['questions' => [['id' => 'q1', 'label' => 'Feet intact']]]]],
        ], $this->userId);

        $this->assertSame(1, $first['version']);
        $this->assertSame(2, $second['version']);
        $this->assertSame('RETIRED', DB::table('sheq_inspection_templates')->find($first['id'])->status);
        $this->assertSame('PUBLISHED', DB::table('sheq_inspection_templates')->find($second['id'])->status);
    }

    public function test_a_completed_inspection_records_the_template_version_it_used(): void
    {
        // Without this an old inspection would render against a checklist it never saw.
        $template = $this->template();

        $result = $this->service()->complete($this->companyId, [
            'template_id' => $template['id'], 'site_id' => $this->siteId,
        ], ['q1' => 'YES', 'q2' => 'YES', 'q3' => 'YES', 'q4' => 'YES'], $this->userId);

        $this->assertSame(1, (int) DB::table('sheq_inspections')->find($result['id'])->template_version);
    }

    public function test_a_template_with_duplicate_question_ids_is_refused(): void
    {
        // Duplicates would silently overwrite each other's answers.
        $this->expectExceptionMessageMatches('/Duplicate question id/');

        $this->service()->publishTemplate($this->companyId, [
            'code' => 'DUP', 'title' => 'Broken',
            'schema' => ['sections' => [['questions' => [
                ['id' => 'q1', 'label' => 'A'], ['id' => 'q1', 'label' => 'B'],
            ]]]],
        ], $this->userId);
    }

    public function test_a_template_with_no_questions_is_refused(): void
    {
        $this->expectExceptionMessageMatches('/at least one question/');

        $this->service()->publishTemplate($this->companyId, [
            'code' => 'EMPTY', 'title' => 'Nothing', 'schema' => ['sections' => [['questions' => []]]],
        ], $this->userId);
    }

    // ── Scoring ─────────────────────────────────────────────────────────────────────────────────

    public function test_a_clean_inspection_scores_full_marks_and_passes(): void
    {
        $template = $this->template();

        $result = $this->service()->complete($this->companyId, [
            'template_id' => $template['id'], 'site_id' => $this->siteId,
        ], ['q1' => 'YES', 'q2' => 'YES', 'q3' => 'YES', 'q4' => 'YES'], $this->userId);

        $this->assertSame(100.0, (float) $result['score_percent']);
        $this->assertTrue($result['passed']);
        $this->assertSame([], $result['actions']);
    }

    public function test_not_applicable_answers_are_excluded_from_the_denominator(): void
    {
        // Scoring 100% because half the questions did not apply would make the number meaningless.
        $template = $this->template();

        $result = $this->service()->complete($this->companyId, [
            'template_id' => $template['id'], 'site_id' => $this->siteId,
        ], ['q1' => 'YES', 'q2' => 'YES', 'q3' => 'N/A', 'q4' => 'N/A'], $this->userId);

        $row = DB::table('sheq_inspections')->find($result['id']);

        $this->assertSame(2.0, (float) $row->max_score, 'Only the two applicable questions count.');
        $this->assertSame(100.0, (float) $result['score_percent']);
    }

    public function test_a_critical_failure_fails_the_whole_inspection_however_good_the_score(): void
    {
        // 75% would clear the 80%… it does not, because the extinguisher was missing.
        $template = $this->template();

        $result = $this->service()->complete($this->companyId, [
            'template_id' => $template['id'], 'site_id' => $this->siteId,
        ], ['q1' => 'NO', 'q2' => 'YES', 'q3' => 'YES', 'q4' => 'YES'], $this->userId);

        $this->assertSame(75.0, (float) $result['score_percent']);
        $this->assertFalse($result['passed'], 'A critical failure must fail the inspection.');
    }

    public function test_a_non_critical_failure_above_the_threshold_still_passes(): void
    {
        $template = $this->template();

        $result = $this->service()->complete($this->companyId, [
            'template_id' => $template['id'], 'site_id' => $this->siteId,
        ], ['q1' => 'YES', 'q2' => 'YES', 'q3' => 'YES', 'q4' => 'NO'], $this->userId);

        $this->assertSame(75.0, (float) $result['score_percent']);
        $this->assertFalse($result['passed'], '75% is below the template threshold of 80%.');
    }

    // ── Failures raise CAPAs by themselves (SHEQ-INS-2) ────────────────────────────────────────

    public function test_a_failed_answer_raises_an_action_with_the_templates_priority_and_due_date(): void
    {
        $template = $this->template();

        $result = $this->service()->complete($this->companyId, [
            'template_id' => $template['id'], 'site_id' => $this->siteId,
            'default_assignee' => $this->userId,
        ], [
            'q1' => 'NO', 'q2' => 'NO', 'q3' => 'YES',
            'q4' => ['value' => 'YES', 'comment' => 'Sign repainted'],
        ], $this->userId);

        $this->assertCount(2, $result['actions'], 'Both failing questions ask for an action.');

        $actions = DB::table('sheq_actions')
            ->where('source_type', 'INSPECTION')->where('source_id', $result['id'])
            ->orderBy('id')->get();

        $this->assertSame('CRITICAL', $actions[0]->priority);
        $this->assertSame(now()->addDay()->toDateString(), $actions[0]->due_date);
        $this->assertSame('MEDIUM', $actions[1]->priority);
        $this->assertSame(now()->addDays(7)->toDateString(), $actions[1]->due_date);
        $this->assertStringContainsString($result['reference'], $actions[0]->description);
    }

    public function test_a_failure_the_template_does_not_flag_raises_no_action(): void
    {
        // q3 has no raise_action_on_fail — the template decides, not the failure itself.
        $template = $this->template();

        $result = $this->service()->complete($this->companyId, [
            'template_id' => $template['id'], 'site_id' => $this->siteId,
        ], ['q1' => 'YES', 'q2' => 'YES', 'q3' => 'NO', 'q4' => 'YES'], $this->userId);

        $this->assertSame([], $result['actions']);
        $this->assertSame(1, (int) DB::table('sheq_inspections')->find($result['id'])->failed_count);
    }

    public function test_an_inspectors_comment_travels_onto_the_action(): void
    {
        $template = $this->template();

        $result = $this->service()->complete($this->companyId, [
            'template_id' => $template['id'], 'site_id' => $this->siteId,
        ], [
            'q1' => 'YES',
            'q2' => ['value' => 'NO', 'comment' => 'Gauge reads in the red, needs recharging'],
            'q3' => 'YES', 'q4' => 'YES',
        ], $this->userId);

        $action = DB::table('sheq_actions')->where('source_id', $result['id'])->first();

        $this->assertStringContainsString('Gauge reads in the red', $action->description);
    }

    // ── Location (SHEQ-INS-7) ───────────────────────────────────────────────────────────────────

    public function test_an_inspection_far_from_the_site_is_flagged_but_not_blocked(): void
    {
        // Johannesburg CBD site, inspection recorded in Pretoria — ~50 km away.
        DB::statement(
            'update sheq_sites set centre_point = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
             geofence_radius_m = 500 where id = ?',
            [28.0473, -26.2041, $this->siteId]
        );

        $template = $this->template();

        $result = $this->service()->complete($this->companyId, [
            'template_id' => $template['id'], 'site_id' => $this->siteId,
            'location_lat' => -25.7479, 'location_lng' => 28.2293,
        ], ['q1' => 'YES', 'q2' => 'YES', 'q3' => 'YES', 'q4' => 'YES'], $this->userId);

        $row = DB::table('sheq_inspections')->find($result['id']);

        $this->assertTrue((bool) $row->location_mismatch);
        $this->assertGreaterThan(40000, (int) $row->location_distance_m);
        // Flagged, not blocked — the inspection is still recorded and scored.
        $this->assertSame('COMPLETED', $row->status);
        $this->assertTrue($result['passed']);
    }

    public function test_an_inspection_inside_the_geofence_is_not_flagged(): void
    {
        DB::statement(
            'update sheq_sites set centre_point = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
             geofence_radius_m = 500 where id = ?',
            [28.0473, -26.2041, $this->siteId]
        );

        $template = $this->template();

        $result = $this->service()->complete($this->companyId, [
            'template_id' => $template['id'], 'site_id' => $this->siteId,
            'location_lat' => -26.2045, 'location_lng' => 28.0478,     // ~60 m away
        ], ['q1' => 'YES', 'q2' => 'YES', 'q3' => 'YES', 'q4' => 'YES'], $this->userId);

        $row = DB::table('sheq_inspections')->find($result['id']);

        $this->assertFalse((bool) $row->location_mismatch);
        $this->assertLessThan(500, (int) $row->location_distance_m);
    }

    public function test_a_site_with_no_geofence_never_flags(): void
    {
        $template = $this->template();

        $result = $this->service()->complete($this->companyId, [
            'template_id' => $template['id'], 'site_id' => $this->siteId,
            'location_lat' => -33.9249, 'location_lng' => 18.4241,
        ], ['q1' => 'YES', 'q2' => 'YES', 'q3' => 'YES', 'q4' => 'YES'], $this->userId);

        $this->assertFalse((bool) DB::table('sheq_inspections')->find($result['id'])->location_mismatch);
    }

    // ── Offline (SHEQ-INS-6, SHEQ-OFF-2) ────────────────────────────────────────────────────────

    public function test_a_replayed_offline_inspection_does_not_duplicate_or_re_raise_actions(): void
    {
        $template = $this->template();
        $clientId = (string) Str::uuid();

        $payload = ['template_id' => $template['id'], 'site_id' => $this->siteId,
            'client_id' => $clientId, 'captured_offline' => true];
        $responses = ['q1' => 'NO', 'q2' => 'YES', 'q3' => 'YES', 'q4' => 'YES'];

        $first = $this->service()->complete($this->companyId, $payload, $responses, $this->userId);
        $second = $this->service()->complete($this->companyId, $payload, $responses, $this->userId);

        $this->assertSame($first['id'], $second['id']);
        $this->assertTrue($second['replayed'] ?? false);
        $this->assertSame(1, DB::table('sheq_inspections')->where('client_id', $clientId)->count());
        // The critical failure must not produce a second CAPA on replay.
        $this->assertSame(1, DB::table('sheq_actions')
            ->where('source_type', 'INSPECTION')->where('source_id', $first['id'])->count());
    }
}
