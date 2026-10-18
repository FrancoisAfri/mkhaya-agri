<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Services\ContractorPerformanceService;
use App\Modules\Operations\Sheq\Services\ContractorService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A contractor score that counts the right things, for the right contractor, and can be argued with.
 *
 * Two defects sat in this quietly. Five of the ten factors CON-20 lists were never counted. And
 * two of the five that were counted did not filter by contractor at all — so every contractor on
 * the estate was charged with every failed inspection and every gate refusal anywhere. A
 * contractor with a spotless record carried their competitors' penalties, and nothing looked
 * wrong because the number was plausible and nobody could see its working.
 */
class ContractorScoreTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

    private array $contractor;

    private array $other;

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
        $this->user = $this->userWith(['sheq.contractor.manage', 'sheq.configure']);

        $this->siteId = (int) DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'S'.Str::upper(Str::random(5)), 'name' => 'Smelter', 'site_type' => 'PLANT',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sheq_settings')->updateOrInsert(
            ['company_id' => $this->companyId],
            ['contractor_required_documents' => json_encode([]),
                'contractor_score_weights' => null, 'updated_at' => now()],
        );

        $contractors = app(ContractorService::class);

        $this->contractor = $contractors->register($this->companyId,
            ['name' => 'Alpha Rigging', 'trade' => 'RIGGING'], $this->user->id);
        $this->other = $contractors->register($this->companyId,
            ['name' => 'Beta Rigging', 'trade' => 'RIGGING'], $this->user->id);

        foreach ([$this->contractor, $this->other] as $contractor) {
            // Ten people each, so the per-hundred normalisation has something to divide by and
            // the two are directly comparable.
            foreach (range(1, 10) as $n) {
                DB::table('sheq_contractor_workers')->insert([
                    'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
                    'contractor_id' => $contractor['id'], 'name' => 'Worker '.$n,
                    'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    private function performance(): ContractorPerformanceService
    {
        return app(ContractorPerformanceService::class);
    }

    private function scoreFor(array $contractor): array
    {
        return $this->performance()->computeMonth($this->companyId, $contractor['id']);
    }

    // ── CON-20: the factors ───────────────────────────────────────────────────────────────────

    public function test_every_factor_the_requirement_lists_is_declared(): void
    {
        /*
         * CON-20 names ten. Five were missing: document compliance, safety file completeness,
         * near misses, audit findings and competency compliance — every one of which bears on
         * whether a contractor is safe to use, and none of which touched the figure a
         * procurement manager renewed a contract on.
         */
        foreach (['incidents', 'near_misses', 'audit_findings', 'inspection_failures',
            'overdue_actions', 'document_gaps', 'safety_file_gaps', 'competency_gaps',
            'access_refusals', 'permit_violations'] as $factor) {
            $this->assertArrayHasKey($factor, ContractorPerformanceService::FACTORS,
                "CON-20 lists {$factor} and the score does not know about it.");
        }
    }

    public function test_every_factor_can_now_actually_be_counted(): void
    {
        /*
         * Permit violations shipped declared-and-uncountable, because `sheq_permits` recorded no
         * contractor and attributing a suspended permit to whichever firm had people at that
         * site would have been inference presented as a fact. A permit now says whose work it
         * is, so all ten count.
         *
         * A factor marked available must also have a weight that can be set, or it is a switch
         * that appears to work and does not.
         */
        foreach (ContractorPerformanceService::FACTORS as $key => $factor) {
            $this->assertTrue($factor['available'],
                "The {$key} factor is declared and still cannot be counted.");
        }

        $this->assertContains('permit_violations',
            ContractorPerformanceService::countableFactors());
    }

    public function test_a_suspended_permit_counts_against_the_contractor_whose_work_it_was(): void
    {
        $this->permit($this->contractor['id'], [
            'suspended_at' => Carbon::today()->startOfMonth()->addDays(5),
            'suspension_reason' => 'Gas test not repeated after the break.',
        ]);

        $mine = $this->scoreFor($this->contractor);
        $theirs = $this->scoreFor($this->other);

        $this->assertSame(1, $mine['permit_violations']);
        $this->assertSame(0, $theirs['permit_violations'],
            'A contractor was charged with another contractor\'s suspended permit.');
    }

    public function test_a_permit_issued_with_the_competency_check_overridden_counts(): void
    {
        /*
         * Sometimes the right call — a contractor's people have no personnel record here, so
         * their tickets are the contractor's to evidence — and still a permit issued without the
         * check, on a named person's authority. That is exactly what a safety record should hold.
         */
        $this->permit($this->contractor['id'], [
            'competency_overridden' => true,
            'competency_overridden_at' => Carbon::today()->startOfMonth()->addDays(2),
            'competency_override_reason' => 'Tickets supplied on paper by the contractor.',
        ]);

        $this->assertSame(1, $this->scoreFor($this->contractor)['permit_violations']);
    }

    public function test_an_expired_permit_is_not_counted_as_a_violation(): void
    {
        /*
         * A permit running past its valid_to is overwhelmingly a crew finishing a job and not
         * closing the paperwork. Charging a safety score for an administrative lapse would swamp
         * the two real signals with noise.
         */
        $this->permit($this->contractor['id'], [
            'valid_to' => Carbon::today()->startOfMonth()->addDays(1),
            'status' => 'EXPIRED',
        ]);

        $this->assertSame(0, $this->scoreFor($this->contractor)['permit_violations']);
    }

    public function test_a_violation_is_dated_on_when_it_happened_not_when_the_permit_was_raised(): void
    {
        // A permit opened in February and suspended in March is a March event: it is the
        // suspension being counted, not the permit.
        $lastMonth = Carbon::today()->startOfMonth()->subMonth();

        $this->permit($this->contractor['id'], [
            'created_at' => $lastMonth->copy()->addDays(3),
            'valid_from' => $lastMonth->copy()->addDays(3),
            'suspended_at' => Carbon::today()->startOfMonth()->addDays(4),
            'suspension_reason' => 'Edge protection removed.',
        ]);

        $this->assertSame(0, $this->performance()
            ->computeMonth($this->companyId, $this->contractor['id'], $lastMonth)
            ['permit_violations']);

        $this->assertSame(1, $this->scoreFor($this->contractor)['permit_violations']);
    }

    public function test_a_permit_for_the_hosts_own_staff_is_charged_to_nobody(): void
    {
        // Most permits on most sites are for the host's own employees, and stamping those with a
        // contractor would be worse than leaving it blank.
        $this->permit(null, [
            'suspended_at' => Carbon::today()->startOfMonth()->addDays(6),
            'suspension_reason' => 'Isolation not verified.',
        ]);

        $this->assertSame(0, $this->scoreFor($this->contractor)['permit_violations']);
        $this->assertSame(0, $this->scoreFor($this->other)['permit_violations']);
    }

    public function test_the_permit_register_records_and_shows_whose_work_it_is(): void
    {
        $props = $this->actingAs($this->user)->get('/sheq/permits')
            ->assertOk()->viewData('page')['props'];

        // The picker has to be there, or the column can only ever be set by an importer.
        $this->assertNotEmpty($props['contractors']);
        $this->assertContains('Alpha Rigging', array_column($props['contractors'], 'name'));
    }

    public function test_a_permit_cannot_be_stamped_with_another_tenants_contractor(): void
    {
        /*
         * A permit carrying a contractor id from another company would attribute a high-risk job
         * to a firm that has never been on the site — and the score would report it.
         */
        $foreign = (int) DB::table('sheq_contractors')->max('id') + 1000;

        $this->actingAs($this->userWith(['sheq.permit.request']))
            ->post('/sheq/permits', [
                'permit_type' => 'HOT_WORK',
                'work_description' => 'Welding a handrail.',
                'contractor_id' => $foreign,
                'valid_from' => Carbon::now()->toDateTimeString(),
                'valid_to' => Carbon::now()->addDay()->toDateTimeString(),
            ])
            ->assertSessionHasErrors('contractor_id');
    }

    /** A permit for one contractor's work, with whatever state the test needs. */
    private function permit(?int $contractorId, array $overrides = []): int
    {
        return (int) DB::table('sheq_permits')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'PTW-'.Str::upper(Str::random(5)),
            'permit_type' => 'HOT_WORK', 'site_id' => $this->siteId,
            'contractor_id' => $contractorId,
            'work_description' => 'Cutting and welding.',
            'valid_from' => Carbon::today()->startOfMonth()->addDay(),
            'valid_to' => Carbon::today()->startOfMonth()->addDays(10),
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    // ── The bug ───────────────────────────────────────────────────────────────────────────────

    public function test_one_contractors_refusals_are_not_charged_to_another(): void
    {
        /*
         * The defect, in one assertion. `access_refusals` filtered on the company and not on the
         * contractor, so every refusal at every gate was charged to everybody.
         */
        foreach (range(1, 4) as $n) {
            DB::table('sheq_access_events')->insert([
                'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
                'site_id' => $this->siteId, 'subject_type' => 'CONTRACTOR_WORKER',
                'subject_id' => $n, 'contractor_id' => $this->contractor['id'],
                'person_name' => 'Worker '.$n, 'direction' => 'ENTRY', 'allowed' => false,
                'reasons' => json_encode(['Induction expired.']),
                'occurred_at' => Carbon::today()->startOfMonth()->addDays(2),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $mine = $this->scoreFor($this->contractor);
        $theirs = $this->scoreFor($this->other);

        $this->assertSame(4, $mine['access_refusals']);
        $this->assertSame(0, $theirs['access_refusals'],
            'A contractor was charged with another contractor\'s gate refusals.');
    }

    public function test_one_contractors_failed_inspections_are_not_charged_to_another(): void
    {
        $templateId = DB::table('sheq_inspection_templates')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'T'.Str::upper(Str::random(5)), 'title' => 'Contractor site walk',
            'version' => 1, 'status' => 'PUBLISHED', 'schema' => json_encode([]),
            'scoring_mode' => 'NONE', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sheq_inspections')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'INSP-'.Str::upper(Str::random(5)), 'site_id' => $this->siteId,
            'template_id' => $templateId, 'template_version' => 1,
            'subject_type' => 'CONTRACTOR', 'subject_id' => $this->contractor['id'],
            'status' => 'COMPLETED', 'passed' => false,
            'completed_at' => Carbon::today()->startOfMonth()->addDays(3),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(1, $this->scoreFor($this->contractor)['inspection_failures']);
        $this->assertSame(0, $this->scoreFor($this->other)['inspection_failures'],
            'A contractor was charged with another contractor\'s failed inspection.');
    }

    // ── CON-20: the explanation ───────────────────────────────────────────────────────────────

    public function test_the_score_explains_itself_and_the_arithmetic_adds_up(): void
    {
        DB::table('sheq_incidents')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'INC-'.Str::upper(Str::random(5)), 'site_id' => $this->siteId,
            'contractor_id' => $this->contractor['id'], 'incident_type' => 'INJURY',
            'occurred_at' => Carbon::today()->startOfMonth()->addDays(4),
            'reported_at' => now(), 'description' => 'Crushed finger.',
            'potential_severity' => 2, 'status' => 'OPEN',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->scoreFor($this->contractor);
        $breakdown = $result['breakdown'];

        $this->assertSame(10, $breakdown['workers_on_site']);
        $this->assertSame(100, $breakdown['started_at']);

        // The arithmetic on the screen has to come to the number at the top of it, or the
        // explanation is worse than no explanation.
        $this->assertEqualsWithDelta(
            $breakdown['started_at'] - $breakdown['deducted'], $breakdown['score'], 0.01);

        $incidents = collect($breakdown['factors'])->firstWhere('key', 'incidents');

        $this->assertSame(1, $incidents['count']);
        $this->assertSame(3, $incidents['weight']);
        // One incident, weight three, ten people: 3/10 × 100 = 30.
        $this->assertEqualsWithDelta(30.0, $incidents['cost'], 0.01);

        // Every factor appears, including the ones that cost nothing — a reader needs to see
        // that documents were checked and were fine, not merely that they are absent from a list.
        $this->assertCount(count(ContractorPerformanceService::FACTORS), $breakdown['factors']);
    }

    public function test_the_breakdown_is_stored_so_a_past_score_still_adds_up(): void
    {
        /*
         * A score recomputed on demand can quietly change its own history: the registers behind
         * it move — an incident is reclassified, an action closed late — and last March's figure
         * would then explain itself with this March's data.
         */
        $this->scoreFor($this->contractor);

        $row = DB::table('sheq_contractor_scores')
            ->where('contractor_id', $this->contractor['id'])
            ->first(['breakdown', 'weights']);

        $this->assertNotNull($row->breakdown);
        $stored = json_decode($row->breakdown, true);
        $this->assertArrayHasKey('factors', $stored);

        // The weights in force at the time, too: without them, changing a weight makes every
        // past month unexplainable by its own stored breakdown.
        $this->assertNotNull($row->weights);
        $this->assertEquals($this->performance()->weightsFor($this->companyId),
            json_decode($row->weights, true));
    }

    public function test_a_near_miss_is_not_counted_as_an_incident(): void
    {
        /*
         * Weighted lightly and counted separately on purpose. Penalise a contractor for
         * reporting near misses and they stop reporting them: the score improves while the site
         * gets more dangerous, which is the exact opposite of what the figure is for.
         */
        DB::table('sheq_incidents')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'INC-'.Str::upper(Str::random(5)), 'site_id' => $this->siteId,
            'contractor_id' => $this->contractor['id'], 'incident_type' => 'NEAR_MISS',
            'occurred_at' => Carbon::today()->startOfMonth()->addDays(5),
            'reported_at' => now(), 'description' => 'Dropped spanner, nobody below.',
            'potential_severity' => 2, 'status' => 'OPEN',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->scoreFor($this->contractor);

        $this->assertSame(1, $result['near_misses']);
        $this->assertSame(0, $result['incidents']);

        $weights = $this->performance()->weightsFor($this->companyId);
        $this->assertLessThan($weights['incidents'], $weights['near_misses']);
    }

    public function test_a_contractor_with_nobody_on_site_is_not_given_a_perfect_score(): void
    {
        $empty = app(ContractorService::class)->register($this->companyId,
            ['name' => 'Dormant Contractor', 'trade' => 'PAINTING'], $this->user->id);

        $result = $this->scoreFor($empty);

        // An absent contractor is not a safe one; they are simply absent.
        $this->assertNull($result['score']);
        $this->assertNull($result['rating']);
        $this->assertStringContainsString('absent', $result['breakdown']['note']);
    }

    // ── CON-20: configurable ──────────────────────────────────────────────────────────────────

    public function test_a_host_can_change_what_a_factor_costs(): void
    {
        DB::table('sheq_incidents')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'INC-'.Str::upper(Str::random(5)), 'site_id' => $this->siteId,
            'contractor_id' => $this->contractor['id'], 'incident_type' => 'INJURY',
            'occurred_at' => Carbon::today()->startOfMonth()->addDays(6),
            'reported_at' => now(), 'description' => 'Sprain.', 'potential_severity' => 1,
            'status' => 'OPEN', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $before = $this->scoreFor($this->contractor)['score'];

        $this->performance()->saveWeights($this->companyId, ['incidents' => 10], $this->user->id);

        $after = $this->scoreFor($this->contractor)['score'];

        $this->assertLessThan($before, $after, 'Reweighting a factor changed nothing.');

        // On the record: this setting changes who gets work.
        $this->assertTrue(DB::table('sheq_audit_log')->where('company_id', $this->companyId)
            ->where('action', 'contractor.score_weights_changed')->exists());
    }

    public function test_a_weight_cannot_be_set_high_enough_to_make_the_score_meaningless(): void
    {
        // A weight of 9,000 does not express strong feeling, it makes every contractor with one
        // incident score zero and the figure stops distinguishing between them.
        $saved = $this->performance()->saveWeights($this->companyId,
            ['incidents' => 9000], $this->user->id);

        $this->assertLessThanOrEqual(50, $saved['incidents']);
    }

    // ── CON-21: trend ─────────────────────────────────────────────────────────────────────────

    public function test_the_trend_runs_oldest_first_and_compares_with_the_month_before(): void
    {
        $thisMonth = Carbon::today()->startOfMonth();
        $lastMonth = $thisMonth->copy()->subMonth();

        DB::table('sheq_incidents')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'INC-'.Str::upper(Str::random(5)), 'site_id' => $this->siteId,
            'contractor_id' => $this->contractor['id'], 'incident_type' => 'INJURY',
            'occurred_at' => $lastMonth->copy()->addDays(3), 'reported_at' => now(),
            'description' => 'Laceration.', 'potential_severity' => 2, 'status' => 'CLOSED',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->performance()->computeMonth($this->companyId, $this->contractor['id'], $lastMonth);
        $this->performance()->computeMonth($this->companyId, $this->contractor['id'], $thisMonth);

        $history = $this->performance()->history($this->companyId, $this->contractor['id']);

        // Oldest first: a chart handed its points backwards slopes the wrong way.
        $this->assertSame($lastMonth->format('Y-m'), $history[0]['period']);

        $comparison = $this->performance()->comparison($this->companyId,
            $this->contractor['id'], $thisMonth->format('Y-m'));

        $this->assertSame($lastMonth->format('Y-m'), $comparison['previous_period']);
        $this->assertGreaterThan(0, $comparison['change'],
            'An improvement from last month was not reported as one.');

        $incidents = collect($comparison['factors'])->firstWhere('key', 'incidents');

        $this->assertSame(1, $incidents['previous']);
        $this->assertSame(0, $incidents['current']);
        $this->assertTrue($incidents['improved']);
    }

    public function test_nothing_to_compare_with_is_not_reported_as_no_change(): void
    {
        // "No change" and "there is nothing to compare with" are different, and a chart that
        // draws the second as the first invents a flat line out of an absence.
        $this->scoreFor($this->contractor);

        $comparison = $this->performance()->comparison($this->companyId, $this->contractor['id']);

        $this->assertNull($comparison['previous_score']);
        $this->assertNull($comparison['change']);
    }

    // ── The screen ────────────────────────────────────────────────────────────────────────────

    public function test_the_page_shows_the_arithmetic_the_factors_and_the_trend(): void
    {
        $this->scoreFor($this->contractor);

        $props = $this->actingAs($this->user)
            ->get('/sheq/contractor-performance?contractor='.$this->contractor['uuid'])
            ->assertOk()->viewData('page')['props'];

        $this->assertCount(count(ContractorPerformanceService::FACTORS), $props['factors']);
        $this->assertNotNull($props['comparison']['breakdown']);
        $this->assertNotEmpty($props['leaderboard']);

        // The uncountable factor is shown too: a host needs to know what is NOT in the score,
        // because a missing factor they assume is included is worse than one they know is absent.
        $this->assertContains('permit_violations', array_column($props['factors'], 'key'));
    }

    public function test_weights_cannot_be_changed_without_the_entitlement(): void
    {
        $this->actingAs($this->userWith([]))
            ->post('/sheq/contractor-performance/weights', ['weights' => ['incidents' => 1]])
            ->assertForbidden();
    }

    public function test_the_page_can_recount_a_month_on_demand(): void
    {
        $this->actingAs($this->user)
            ->post('/sheq/contractor-performance/recompute?period='
                .Carbon::today()->format('Y-m'))
            ->assertRedirect();

        $this->assertTrue(DB::table('sheq_contractor_scores')
            ->where('contractor_id', $this->contractor['id'])->exists());
    }

    private function userWith(array $permissions): User
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'score-'.Str::random(8).'@example.test',
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
