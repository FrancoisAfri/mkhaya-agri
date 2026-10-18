<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Services\QualityAnalytics;
use App\Modules\Operations\Sheq\Services\QualityService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * The Q in SHEQ (QLT-1 to QLT-8).
 *
 * The module was a safety system with quality mentioned in passing: an inspection could record a
 * score, and nothing could record that the concrete failed its 28-day strength, who owed the
 * rework, what it cost, or that the same defect had happened four times on the same floor.
 *
 * The test that matters most is QLT-7. Self-verification is the commonest way a quality system
 * produces clean records and defective work, and it is never malicious — the person who fixed it
 * knows it is fixed, so they tick the box.
 */
class QualityTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

    private int $siteId;

    private int $contractorId;

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
        $this->user = $this->userWith(['sheq.hazard.report', 'sheq.hazard.triage',
            'sheq.action.verify', 'sheq.configure']);

        $this->siteId = (int) DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'Q'.Str::upper(Str::random(5)), 'name' => 'Tower B',
            'site_type' => 'CONSTRUCTION', 'timezone' => 'Africa/Johannesburg',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->contractorId = (int) DB::table('sheq_contractors')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'name' => 'Formwork Contractor', 'trade' => 'CONCRETE', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function service(): QualityService
    {
        return app(QualityService::class);
    }

    private function ncr(array $overrides = []): array
    {
        return $this->service()->raiseNcr($this->companyId, array_merge([
            'defect_code' => 'CONC_HONEYCOMB',
            'defect' => 'Honeycombing on the south face of column C4.',
            'discipline' => 'CONCRETE',
            'site_id' => $this->siteId,
            'location_text' => 'Block B, level 3, column C4',
            'contractor_id' => $this->contractorId,
            'severity' => QualityService::MINOR,
        ], $overrides), $this->user->id);
    }

    // ── QLT-1 ─────────────────────────────────────────────────────────────────────────────────

    public function test_all_five_kinds_of_requirement_can_be_configured(): void
    {
        /*
         * One table for objectives, standards, specifications, project requirements and
         * acceptance criteria, because they are the same shape. Five tables would mean an NCR
         * had to decide which of five things it breached.
         */
        foreach (['OBJECTIVE', 'STANDARD', 'SPECIFICATION', 'PROJECT_REQUIREMENT',
            'ACCEPTANCE_CRITERION'] as $type) {
            $this->assertArrayHasKey($type, QualityService::REQUIREMENT_TYPES,
                "QLT-1 lists {$type} and it cannot be configured.");

            $saved = $this->service()->saveRequirement($this->companyId, null, [
                'requirement_type' => $type,
                'title' => 'Concrete strength at 28 days',
                'source' => 'SANS 10100-2',
                'acceptance_criteria' => '25 MPa at 28 days',
                'target_value' => 25, 'target_unit' => 'MPa',
                'discipline' => 'CONCRETE',
            ], $this->user->id);

            $this->assertStringStartsWith('QR-', $saved['reference']);
        }
    }

    // ── QLT-2 ─────────────────────────────────────────────────────────────────────────────────

    public function test_every_discipline_the_requirement_names_has_a_versioned_template(): void
    {
        /*
         * The same versioned engine the safety checklists use, with one new column saying which
         * discipline a template belongs to — without it a concrete checklist appears in a safety
         * inspector's list and both lists grow long enough that nobody reads them.
         */
        foreach (array_keys(QualityService::DISCIPLINES) as $discipline) {
            $template = DB::table('sheq_inspection_templates')
                ->where('discipline', $discipline)->where('status', 'PUBLISHED')
                ->first(['code', 'version', 'schema']);

            $this->assertNotNull($template,
                "QLT-2 names {$discipline} and no inspection template covers it.");
            $this->assertGreaterThanOrEqual(1, (int) $template->version);

            $schema = json_decode((string) $template->schema, true);

            $this->assertNotEmpty($schema['sections'] ?? [],
                "The {$discipline} template has no questions.");
        }
    }

    public function test_a_quality_template_marks_the_failures_that_cannot_be_scored_away(): void
    {
        // A strength failure or an unqualified welder is not a score deduction, it is a stop —
        // the same meaning `critical` has on the safety templates.
        $welding = DB::table('sheq_inspection_templates')
            ->where('discipline', 'WELDING')->where('status', 'PUBLISHED')->value('schema');

        $questions = collect(json_decode((string) $welding, true)['sections'])
            ->flatMap(fn (array $s) => $s['questions']);

        $this->assertTrue($questions->contains(fn (array $q) => $q['critical'] === true),
            'No welding question is marked critical, so an unqualified welder is a deduction.');
    }

    // ── QLT-3 ─────────────────────────────────────────────────────────────────────────────────

    public function test_an_ncr_captures_everything_the_requirement_lists(): void
    {
        $requirement = $this->service()->saveRequirement($this->companyId, null, [
            'requirement_type' => 'SPECIFICATION', 'title' => 'Surface finish',
            'discipline' => 'CONCRETE',
        ], $this->user->id);

        $created = $this->ncr([
            'quality_requirement_id' => $requirement['id'],
            'responsible_id' => $this->user->id,
            'severity' => QualityService::MAJOR,
        ]);

        $row = DB::table('sheq_ncrs')->where('id', $created['id'])->first();

        // QLT-3's list: requirement, defect, location, contractor, severity, responsible, due.
        $this->assertSame($requirement['id'], (int) $row->quality_requirement_id);
        $this->assertSame('CONC_HONEYCOMB', $row->defect_code);
        $this->assertStringContainsString('Honeycombing', $row->defect);
        $this->assertSame('Block B, level 3, column C4', $row->location_text);
        $this->assertSame($this->contractorId, (int) $row->contractor_id);
        $this->assertSame(QualityService::MAJOR, $row->severity);
        $this->assertSame($this->user->id, (int) $row->responsible_id);
        $this->assertNotNull($row->due_date);

        // Evidence has somewhere to go, through the file service like everything else.
        $this->assertStringStartsWith('NCR-', $row->reference);
    }

    public function test_a_due_date_is_set_from_the_severity_when_none_is_given(): void
    {
        // An NCR with no due date is an NCR nobody is late on, and the overdue figure is the
        // only thing that makes the register move.
        $critical = $this->ncr(['severity' => QualityService::CRITICAL]);
        $minor = $this->ncr(['severity' => QualityService::MINOR]);

        $this->assertSame(Carbon::today()->addDays(3)->toDateString(),
            DB::table('sheq_ncrs')->where('id', $critical['id'])->value('due_date'));
        $this->assertSame(Carbon::today()->addDays(21)->toDateString(),
            DB::table('sheq_ncrs')->where('id', $minor['id'])->value('due_date'));
    }

    // ── QLT-6 ─────────────────────────────────────────────────────────────────────────────────

    public function test_an_ncr_raises_a_capa_in_the_existing_unified_register(): void
    {
        // The requirement says "the existing unified CAPA system" in so many words.
        $created = $this->ncr();

        $action = $this->service()->raiseAction($this->companyId,
            DB::table('sheq_ncrs')->where('id', $created['id'])->value('uuid'),
            [], $this->user->id);

        $row = DB::table('sheq_actions')->where('id', $action['id'])->first();

        $this->assertSame('NCR', $row->source_type);
        $this->assertSame($created['id'], (int) $row->source_id);
        $this->assertSame($this->contractorId, (int) $row->contractor_id);
        $this->assertStringStartsWith('ACT', (string) $row->reference);

        $this->assertSame(QualityService::IN_PROGRESS,
            DB::table('sheq_ncrs')->where('id', $created['id'])->value('status'));
    }

    // ── QLT-5 ─────────────────────────────────────────────────────────────────────────────────

    public function test_root_cause_uses_the_same_engine_incidents_use(): void
    {
        /*
         * A second root-cause engine for quality would drift from the first within a release —
         * one would accept a 5-Why that stopped at two levels and the other would not. So an
         * investigation now attaches to a non-conformance.
         */
        $created = $this->ncr(['severity' => QualityService::CRITICAL]);
        $uuid = DB::table('sheq_ncrs')->where('id', $created['id'])->value('uuid');

        $investigation = $this->service()->investigate($this->companyId, $uuid, null,
            $this->user->id);

        // A critical non-conformance gets an ICAM, not three whys — the same depth table
        // incidents are routed through.
        $this->assertSame('ICAM', $investigation['required_method']);
        $this->assertGreaterThanOrEqual(4, $investigation['minimum_causes']);

        $row = DB::table('sheq_investigations')
            ->where('uuid', $investigation['uuid'])->first(['incident_id', 'subject_type',
                'subject_id']);

        $this->assertNull($row->incident_id);
        $this->assertSame('NCR', $row->subject_type);
        $this->assertSame($created['id'], (int) $row->subject_id);
    }

    public function test_a_minor_non_conformance_does_not_demand_an_icam(): void
    {
        // Demanding a four-layer ICAM for a chipped tile is how a method requirement gets
        // ignored entirely.
        $created = $this->ncr(['severity' => QualityService::MINOR]);

        $investigation = $this->service()->investigate($this->companyId,
            DB::table('sheq_ncrs')->where('id', $created['id'])->value('uuid'),
            null, $this->user->id);

        $this->assertSame('FIVE_WHY', $investigation['required_method']);
    }

    public function test_an_existing_incident_investigation_still_works(): void
    {
        // `incident_id` became nullable for QLT-5. Every existing row keeps its incident id and
        // every existing query has to keep working.
        $this->assertSame(0, (int) DB::table('sheq_investigations')
            ->whereNull('incident_id')->whereNull('subject_type')->count(),
            'An investigation exists with neither an incident nor a subject.');
    }

    // ── QLT-7 ─────────────────────────────────────────────────────────────────────────────────

    public function test_a_high_severity_ncr_cannot_be_verified_by_whoever_did_the_work(): void
    {
        /*
         * The heart of the section. Self-verification is never malicious — the person who fixed
         * it is the person who knows it is fixed, so they tick the box, and the record then says
         * the defect was independently checked when it was not.
         */
        $created = $this->ncr(['severity' => QualityService::MAJOR]);
        $uuid = DB::table('sheq_ncrs')->where('id', $created['id'])->value('uuid');

        $this->service()->recordCorrection($this->companyId, $uuid,
            ['corrected_by' => $this->user->id, 'correction_notes' => 'Cut out and recast.'],
            $this->user->id);

        try {
            $this->service()->verify($this->companyId, $uuid, [], $this->user->id);

            $this->fail('A major non-conformance was closed by the person who corrected it.');
        } catch (RuntimeException $e) {
            // Explained, not merely refused: somebody told only "not permitted" asks an
            // administrator to do it instead, which produces the record the rule prevents.
            $this->assertStringContainsString('cannot be verified by the person',
                $e->getMessage());
        }

        $this->assertNotSame(QualityService::CLOSED,
            DB::table('sheq_ncrs')->where('id', $created['id'])->value('status'));
    }

    public function test_somebody_else_can_verify_it(): void
    {
        $created = $this->ncr(['severity' => QualityService::CRITICAL]);
        $uuid = DB::table('sheq_ncrs')->where('id', $created['id'])->value('uuid');

        $this->service()->recordCorrection($this->companyId, $uuid,
            ['corrected_by' => $this->user->id], $this->user->id);

        $other = $this->userWith(['sheq.action.verify']);

        $this->service()->verify($this->companyId, $uuid,
            ['verification_notes' => 'Inspected and accepted.'], $other->id);

        $row = DB::table('sheq_ncrs')->where('id', $created['id'])->first();

        $this->assertSame(QualityService::CLOSED, $row->status);
        $this->assertSame($this->user->id, (int) $row->corrected_by);
        $this->assertSame($other->id, (int) $row->verified_by);

        // Both ids on the record, so an auditor can see the separation was real rather than
        // asserted.
        $this->assertNotSame((int) $row->corrected_by, (int) $row->verified_by);
    }

    public function test_a_minor_ncr_may_be_closed_by_the_same_person(): void
    {
        /*
         * The rule is for high severity only. Applying it to every chipped tile would mean a
         * second person walking to every snag, and the rule would be switched off wholesale.
         */
        $created = $this->ncr(['severity' => QualityService::MINOR]);
        $uuid = DB::table('sheq_ncrs')->where('id', $created['id'])->value('uuid');

        $this->service()->recordCorrection($this->companyId, $uuid,
            ['corrected_by' => $this->user->id], $this->user->id);

        $this->service()->verify($this->companyId, $uuid, [], $this->user->id);

        $this->assertSame(QualityService::CLOSED,
            DB::table('sheq_ncrs')->where('id', $created['id'])->value('status'));
    }

    public function test_a_high_severity_ncr_cannot_be_verified_before_the_work_is_recorded(): void
    {
        // Otherwise the separation is unenforceable: with nobody recorded as having done the
        // work, any verifier passes the test.
        $created = $this->ncr(['severity' => QualityService::MAJOR]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/who carried out/i');

        $this->service()->verify($this->companyId,
            DB::table('sheq_ncrs')->where('id', $created['id'])->value('uuid'),
            [], $this->user->id);
    }

    public function test_the_screen_refuses_with_the_reason_rather_than_a_403(): void
    {
        $created = $this->ncr(['severity' => QualityService::MAJOR]);
        $uuid = DB::table('sheq_ncrs')->where('id', $created['id'])->value('uuid');

        $this->service()->recordCorrection($this->companyId, $uuid,
            ['corrected_by' => $this->user->id], $this->user->id);

        $this->actingAs($this->user)
            ->post("/sheq/quality/ncrs/{$uuid}/verify")
            ->assertRedirect()
            ->assertSessionHasErrors('verify');
    }

    // ── QLT-4 ─────────────────────────────────────────────────────────────────────────────────

    public function test_rework_records_cost_time_reason_and_who_bears_it(): void
    {
        $id = $this->service()->recordRework($this->companyId, [
            'description' => 'Recast column C4',
            'reason_code' => 'DEFECTIVE_WORK',
            'site_id' => $this->siteId,
            'contractor_id' => $this->contractorId,
            'cost' => 48500.50,
            'hours' => 36,
        ], $this->user->id);

        $row = DB::table('sheq_rework')->where('id', $id)->first();

        // QLT-4's list: rework, cost, time, reason, responsible contractor, verification.
        $this->assertSame('DEFECTIVE_WORK', $row->reason_code);
        $this->assertSame($this->contractorId, (int) $row->contractor_id);
        $this->assertSame(36.0, (float) $row->hours);
        $this->assertFalse((bool) $row->is_verified);

        /*
         * Money in minor units. A float is wrong by a cent somewhere and this figure ends up in
         * a commercial argument, where being wrong by a cent is the whole argument.
         */
        $this->assertSame(4850050, (int) $row->cost_minor);
        $this->assertSame('ZAR', $row->cost_currency);
    }

    public function test_rework_can_be_verified(): void
    {
        $this->service()->recordRework($this->companyId,
            ['description' => 'Redo the screed', 'contractor_id' => $this->contractorId],
            $this->user->id);

        $uuid = DB::table('sheq_rework')->where('company_id', $this->companyId)
            ->orderByDesc('id')->value('uuid');

        $this->service()->verifyRework($this->companyId, $uuid, 'Checked and level.',
            $this->user->id);

        $row = DB::table('sheq_rework')->where('uuid', $uuid)->first();

        $this->assertTrue((bool) $row->is_verified);
        $this->assertSame($this->user->id, (int) $row->verified_by);
    }

    // ── QLT-8 ─────────────────────────────────────────────────────────────────────────────────

    public function test_repeat_defects_are_counted_on_the_code_not_on_the_words(): void
    {
        /*
         * "Honeycombing on the column again" is unfindable prose; the same defect against a code
         * is countable. One failure is an event, the same failure eleven times is a cause, and
         * nothing in a list of individual NCRs will ever say so.
         */
        foreach (range(1, 4) as $n) {
            $this->ncr(['location_text' => 'Column C'.$n]);
        }

        $this->ncr(['defect_code' => 'CONC_COVER', 'defect' => 'Cover short on the beam.']);

        $summary = app(QualityAnalytics::class)->summary($this->companyId);

        $repeat = collect($summary['repeat_defects'])->firstWhere('defect_code', 'CONC_HONEYCOMB');

        $this->assertNotNull($repeat);
        $this->assertSame(4, (int) $repeat['total']);
        $this->assertSame(4, (int) $repeat['locations']);

        // A defect seen once is not a repeat.
        $this->assertNull(collect($summary['repeat_defects'])
            ->firstWhere('defect_code', 'CONC_COVER'));
    }

    public function test_the_first_pass_rate_is_not_the_same_as_the_pass_rate(): void
    {
        /*
         * The difference is the point: an inspector passes the element and raises the defects
         * separately, so an inspection can be recorded as a pass with three NCRs against it.
         * The pass rate counts it as a pass; the first-pass rate does not.
         */
        $templateId = (int) DB::table('sheq_inspection_templates')
            ->where('discipline', 'CONCRETE')->value('id');

        $inspectionId = (int) DB::table('sheq_inspections')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'INSP-'.Str::upper(Str::random(5)), 'template_id' => $templateId,
            'template_version' => 1, 'site_id' => $this->siteId,
            'status' => 'COMPLETED', 'passed' => true, 'completed_at' => now()->subDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->ncr(['inspection_id' => $inspectionId]);

        $rates = app(QualityAnalytics::class)->summary($this->companyId)['inspections'];

        $this->assertSame(1, $rates['completed']);
        $this->assertSame(100, $rates['pass_rate']);
        $this->assertSame(1, $rates['with_ncr']);
        $this->assertSame(0, $rates['first_pass_rate'],
            'An inspection that raised an NCR was counted as a first-time pass.');
    }

    public function test_nothing_inspected_is_not_reported_as_nothing_passing(): void
    {
        // "No inspections" and "nothing passed" are different, and a rate of 0% reads as the
        // second.
        $rates = app(QualityAnalytics::class)
            ->summary($this->companyId, Carbon::today()->addYear(),
                Carbon::today()->addYears(2))['inspections'];

        $this->assertNull($rates['pass_rate']);
        $this->assertNull($rates['first_pass_rate']);
    }

    public function test_the_contractor_quality_score_is_separate_from_the_safety_score(): void
    {
        /*
         * Deliberately not blended. A safety manager decides whether a firm may be on site; a
         * quantity surveyor decides whether to use them again. A single number is the one figure
         * neither of them trusts.
         */
        $this->ncr(['severity' => QualityService::CRITICAL]);
        $this->service()->recordRework($this->companyId,
            ['description' => 'Recast', 'contractor_id' => $this->contractorId, 'cost' => 1000],
            $this->user->id);

        $scores = app(QualityAnalytics::class)->summary($this->companyId)['by_contractor'];

        $mine = collect($scores)->firstWhere('contractor_id', $this->contractorId);

        $this->assertNotNull($mine);
        $this->assertSame(1, $mine['critical']);
        $this->assertSame(1, $mine['rework_events']);
        $this->assertLessThan(100, $mine['score']);

        // And the safety score table is untouched by any of it.
        $this->assertSame(0, DB::table('sheq_contractor_scores')
            ->where('company_id', $this->companyId)
            ->where('contractor_id', $this->contractorId)->count());
    }

    public function test_independent_verification_is_counted_so_the_rule_can_be_audited(): void
    {
        // A rule nobody can audit is a rule that quietly stops applying.
        $created = $this->ncr(['severity' => QualityService::MAJOR]);
        $uuid = DB::table('sheq_ncrs')->where('id', $created['id'])->value('uuid');

        $this->service()->recordCorrection($this->companyId, $uuid,
            ['corrected_by' => $this->user->id], $this->user->id);

        $this->service()->verify($this->companyId, $uuid, [],
            $this->userWith(['sheq.action.verify'])->id);

        $counts = app(QualityAnalytics::class)->summary($this->companyId)['ncrs'];

        $this->assertSame(1, $counts['closed']);
        $this->assertSame(1, $counts['independently_verified']);
    }

    // ── The screen ────────────────────────────────────────────────────────────────────────────

    public function test_the_page_shows_the_register_the_analytics_and_the_requirements(): void
    {
        $this->ncr();

        $props = $this->actingAs($this->user)->get('/sheq/quality')
            ->assertOk()->viewData('page')['props'];

        $this->assertNotEmpty($props['ncrs']);
        $this->assertNotNull($props['analytics']);
        $this->assertCount(count(QualityService::DISCIPLINES), $props['disciplines']);
        // The eight shipped templates, so QLT-2 is reachable rather than merely installed.
        $this->assertCount(count(QualityService::DISCIPLINES), $props['templates']);
    }

    public function test_the_page_tells_this_reader_whether_they_may_verify(): void
    {
        /*
         * Asked on their behalf rather than rendered and discovered on submit: whether somebody
         * may verify depends on who they are.
         */
        $created = $this->ncr(['severity' => QualityService::MAJOR]);
        $uuid = DB::table('sheq_ncrs')->where('id', $created['id'])->value('uuid');

        $this->service()->recordCorrection($this->companyId, $uuid,
            ['corrected_by' => $this->user->id], $this->user->id);

        $props = $this->actingAs($this->user)->get('/sheq/quality?ncr='.$uuid)
            ->assertOk()->viewData('page')['props'];

        $this->assertNotNull($props['detail']['verification_blocker']);

        $other = $this->userWith(['sheq.action.verify', 'sheq.hazard.report']);

        $theirs = $this->actingAs($other)->get('/sheq/quality?ncr='.$uuid)
            ->assertOk()->viewData('page')['props'];

        $this->assertNull($theirs['detail']['verification_blocker']);
    }

    public function test_raising_an_ncr_needs_the_entitlement(): void
    {
        $this->actingAs($this->userWith([]))
            ->post('/sheq/quality/ncrs', [
                'defect' => 'Something', 'severity' => 'MINOR',
            ])->assertForbidden();
    }

    private function userWith(array $permissions): User
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'qlt-'.Str::random(8).'@example.test',
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
