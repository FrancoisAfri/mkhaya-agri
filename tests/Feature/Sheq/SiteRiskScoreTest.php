<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Services\ObservationService;
use App\Modules\Operations\Sheq\Services\SiteRiskScoreService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The site safety risk score (§16).
 *
 * §16's requirement is not really "produce a number" — it is "the score must be explainable", and
 * the tests here are mostly about the ways a score can be produced and be worthless:
 *
 *   - built on counts rather than rates, so the biggest site is always the most dangerous one
 *   - scoring a site that reports nothing as perfectly safe
 *   - penalising near-miss and hazard reporting, after which reporting stops and the score
 *     improves while the site gets worse
 *   - counting safe observations as a penalty, so a good behavioural programme looks like a bad
 *     site
 *   - recomputing the explanation on read, so last month's figure explains itself with this
 *     month's data and the two drift apart in silence
 *
 * And one that is specific to this module having two scores: this one runs the opposite way to
 * the contractor score, and a number labelled 82 must never be ambiguous about whether somebody
 * is being praised.
 */
class SiteRiskScoreTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

    private int $siteId;

    private string $period;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        $this->user = $this->userWith(['sheq.configure']);
        $this->siteId = $this->site('Tower block');
        $this->period = Carbon::now()->format('Y-m');

        // Weights are per tenant and the test database is shared, so a previous run's settings
        // would silently change every number below.
        DB::table('sheq_settings')->updateOrInsert(['company_id' => $this->companyId],
            ['site_risk_weights' => null, 'updated_at' => now()]);
    }

    private function service(): SiteRiskScoreService
    {
        return app(SiteRiskScoreService::class);
    }

    // ── The inputs §16 names ──────────────────────────────────────────────────────────────────

    public function test_every_input_the_requirement_lists_is_a_signal(): void
    {
        /*
         * Walked against the requirement's own words rather than against the implementation, so a
         * signal dropped during a refactor fails here instead of becoming a weight nobody notices
         * is missing.
         */
        $required = [
            'incidents' => 'incidents',
            'near misses' => 'near_misses',
            'hazards' => 'hazards',
            'inspections' => 'inspection_failures',
            'observations' => 'unsafe_observations',
            'contractor compliance' => 'contractor_compliance_gaps',
            'overdue CAPA' => 'overdue_actions',
            'permit violations' => 'permit_violations',
            'competency failures' => 'competency_failures',
            'NCRs' => 'ncrs',
            'repeated control failures' => 'repeated_control_failures',
        ];

        foreach ($required as $words => $key) {
            $this->assertArrayHasKey($key, SiteRiskScoreService::SIGNALS,
                "§16 lists '{$words}' as an input and nothing counts it.");

            $signal = SiteRiskScoreService::SIGNALS[$key];

            $this->assertNotSame('', trim($signal['label']));
            $this->assertGreaterThan(40, strlen($signal['note']),
                "The {$key} signal has no explanation, and §16 asks for the score to be "
                .'explainable — a weight with no reasoning cannot be argued with or tuned.');
        }
    }

    public function test_every_signal_has_a_column_to_store_its_count_in(): void
    {
        $columns = DB::getSchemaBuilder()->getColumnListing('sheq_site_risk_scores');

        foreach (SiteRiskScoreService::signalKeys() as $key) {
            $this->assertContains($key, $columns,
                "The {$key} signal has nowhere to be stored, so it contributes to the score and "
                .'cannot be queried or trended.');
        }
    }

    // ── The arithmetic ────────────────────────────────────────────────────────────────────────

    public function test_the_score_is_a_rate_so_the_biggest_site_is_not_always_the_worst(): void
    {
        /*
         * The single most important property. A site with forty incidents and nine thousand
         * people is safer than one with four and twelve, and a score built on raw counts says the
         * opposite — which makes the whole board a headcount ranking with a safety label on it.
         */
        $big = $this->service()->score(['incidents' => 40], 9000, $this->weights());
        $small = $this->service()->score(['incidents' => 4], 120, $this->weights());

        $this->assertLessThan($small['risk_score'], $big['risk_score'],
            'A site with ten times the incidents and seventy-five times the people scored worse. '
            .'The score is counting people, not risk.');
    }

    public function test_a_site_with_nobody_on_it_has_no_score_rather_than_a_perfect_one(): void
    {
        /*
         * Zero is the best possible score, so a site that has never reported anything would sit
         * at the top of the board looking exemplary. A site reporting nothing is either empty or
         * not reporting, and both have to read as "no data".
         */
        $result = $this->service()->score([], 0, $this->weights());

        $this->assertNull($result['risk_score'],
            'An empty site scored 0, which is the best possible score, so it ranks above every '
            .'site that is actually being managed.');
        $this->assertNull($result['risk_band']);
        $this->assertFalse($result['explained']);
        $this->assertStringContainsString('noise', $result['why'],
            'Nothing explains why there is no score, so the gap reads as a bug.');
    }

    public function test_the_board_puts_sites_with_no_score_at_the_bottom_not_the_top(): void
    {
        $quiet = $this->site('Nobody here');

        $this->attendance(60);
        $this->incident(3);

        $this->service()->compute($this->companyId, $this->siteId, $this->period);
        $this->service()->compute($this->companyId, $quiet, $this->period);

        $board = $this->service()->board($this->companyId, $this->period);
        $ids = array_column($board, 'site_id');

        $this->assertSame($this->siteId, $ids[0],
            'The site with incidents is not at the top of a board sorted worst-first.');
        $this->assertSame($quiet, $ids[count($ids) - 1],
            'A site with no score sorted above a site with a real one, so the board\'s worst '
            .'entries are the ones nobody has data for.');
    }

    public function test_reporting_a_near_miss_barely_moves_the_score(): void
    {
        /*
         * This is the property that decides whether a safety score is worth having. If reporting
         * hurts the number, reporting stops — and then the score improves while the site gets
         * more dangerous, which is worse than having no score at all.
         */
        $weights = $this->weights();

        $reported = $this->service()->score(['near_misses' => 20], 200, $weights);
        $silent = $this->service()->score([], 200, $weights);

        $this->assertLessThan(15, $reported['risk_score'] - $silent['risk_score'],
            'Twenty near-miss reports moved the score by more than fifteen points. A site '
            .'manager watching this figure will tell their people to stop reporting.');

        // And an actual incident must cost far more than the near miss that did not happen.
        $hurt = $this->service()->score(['incidents' => 1], 200, $weights);

        $this->assertGreaterThan(
            ($reported['risk_score'] - $silent['risk_score']) / 20,
            $hurt['risk_score'] - $silent['risk_score'],
            'One injury costs less per event than one near-miss report.'
        );
    }

    public function test_a_safe_observation_is_not_counted_against_the_site(): void
    {
        $this->attendance(100);

        $this->observation(ObservationService::POSITIVE, 10);

        $clean = $this->service()->compute($this->companyId, $this->siteId, $this->period);

        $this->assertSame(0, $clean['counts']['unsafe_observations'],
            'Positive observations were counted as unsafe ones, so a site running a good '
            .'behavioural programme scores worse than one running none.');

        $this->observation(ObservationService::UNSAFE, 4);

        $dirty = $this->service()->compute($this->companyId, $this->siteId, $this->period);

        $this->assertSame(4, $dirty['counts']['unsafe_observations']);
    }

    public function test_the_unsafe_kinds_are_derived_from_their_own_declaration(): void
    {
        /*
         * Not from a list in the query. A fifth observation kind added later has to be counted or
         * not counted by its own `concerning` flag, which is the thing the rest of the module
         * already reads — otherwise it is included or excluded by whether somebody remembered
         * this one query.
         */
        $this->attendance(100);

        $concerning = array_keys(array_filter(ObservationService::TYPES,
            fn (array $t) => $t['concerning']));

        $this->assertNotEmpty($concerning);

        foreach ($concerning as $type) {
            $this->observation($type, 1);
        }

        $result = $this->service()->compute($this->companyId, $this->siteId, $this->period);

        $this->assertSame(count($concerning), $result['counts']['unsafe_observations'],
            'Some concerning observation kinds are not counted, so the signal under-reports by '
            .'whichever kinds the query forgot.');
    }

    public function test_the_score_is_capped_rather_than_running_past_a_hundred(): void
    {
        $result = $this->service()->score(['incidents' => 500, 'ncrs' => 500], 50, $this->weights());

        $this->assertSame(100, $result['risk_score'],
            'The score exceeded 100, so "82/100" is not a scale and the bands mean nothing.');
        $this->assertSame('SEVERE', $result['risk_band']);
    }

    public function test_the_bands_are_named_so_a_high_number_is_never_read_as_praise(): void
    {
        /*
         * This module has two scores and they run in opposite directions: a contractor on 90 is
         * doing well, a site on 90 is about to hurt somebody. The band names are the only thing
         * standing between those two facts on a dashboard that shows both.
         */
        foreach ([5 => 'LOW', 30 => 'MODERATE', 60 => 'HIGH', 90 => 'SEVERE'] as $score => $band) {
            $result = $this->service()->score(['incidents' => 0], 100, $this->weights());

            $this->assertArrayHasKey($band, SiteRiskScoreService::BANDS,
                "There is no {$band} band, so a score of {$score} has no label.");
        }

        foreach (array_keys(SiteRiskScoreService::BANDS) as $band) {
            $this->assertNotContains($band, ['GOOD', 'ACCEPTABLE', 'EXCELLENT'],
                "The band '{$band}' reads as a compliment on a scale where high means dangerous.");
        }
    }

    // ── §16's explainability ──────────────────────────────────────────────────────────────────

    public function test_the_breakdown_names_the_three_biggest_contributors_as_shares(): void
    {
        /*
         * §16's own example:
         *
         *   Site Safety Risk: 82/100 — HIGH
         *   1. Contractor compliance — 28%
         *   2. Working-at-height observations — 24%
         *   3. Overdue CAPA — 19%
         *
         * So the explanation is an ordered list of shares, not a list of counts — a site manager
         * acts on "contractor compliance is 28% of my score", not on "there were four of them".
         */
        $result = $this->service()->score([
            'contractor_compliance_gaps' => 6,
            'unsafe_observations' => 8,
            'overdue_actions' => 4,
            'near_misses' => 2,
        ], 200, $this->weights());

        $this->assertTrue($result['explained']);
        $this->assertGreaterThanOrEqual(3, count($result['breakdown']));

        // Ordered largest first, because the explanation is read as a to-do list.
        $points = array_column($result['breakdown'], 'points');
        $sorted = $points;
        rsort($sorted);

        $this->assertSame($sorted, $points,
            'The breakdown is not ordered by contribution, so the first thing a reader sees is '
            .'not the first thing to fix.');

        foreach ($result['breakdown'] as $row) {
            foreach (['key', 'label', 'note', 'count', 'weight', 'points', 'share'] as $field) {
                $this->assertArrayHasKey($field, $row,
                    "A breakdown row has no {$field}, so the score cannot be explained.");
            }

            $this->assertGreaterThan(0, $row['share']);
        }

        // The shares account for the whole figure, give or take rounding.
        $this->assertGreaterThanOrEqual(97, array_sum(array_column($result['breakdown'], 'share')));
        $this->assertLessThanOrEqual(103, array_sum(array_column($result['breakdown'], 'share')));
    }

    public function test_a_signal_with_no_events_is_not_listed_as_a_reason(): void
    {
        $result = $this->service()->score(['incidents' => 2], 200, $this->weights());

        $keys = array_column($result['breakdown'], 'key');

        $this->assertContains('incidents', $keys);
        $this->assertNotContains('ncrs', $keys,
            'Signals with no events appear in the explanation at 0%, which buries the ones that '
            .'matter in a list of eleven.');
    }

    public function test_the_stored_explanation_is_not_recomputed_when_it_is_read_back(): void
    {
        /*
         * The failure this guards against is subtle and total: if the breakdown is regenerated on
         * read, last month's figure explains itself with this month's data. The number says 82
         * and the reasons add up to 41, and nobody can tell which is wrong.
         */
        $this->attendance(100);
        $this->incident(4);

        $computed = $this->service()->compute($this->companyId, $this->siteId, $this->period);

        $this->assertNotNull($computed['risk_score']);

        // The registers move after the fact, which is what always happens.
        DB::table('sheq_incidents')->where('company_id', $this->companyId)
            ->where('site_id', $this->siteId)->update(['deleted_at' => now()]);

        $explained = $this->service()->explain($this->companyId, $this->siteId, $this->period);

        $this->assertSame($computed['risk_score'], (int) $explained['risk_score'],
            'The stored score changed when the registers behind it did, so a trend rewrites its '
            .'own history.');
        $this->assertNotEmpty($explained['breakdown'],
            'The stored explanation emptied itself once the underlying rows were gone.');
        $this->assertSame('incidents', $explained['breakdown'][0]['key']);
    }

    public function test_the_weights_are_stored_with_the_score_not_read_from_today(): void
    {
        $this->attendance(100);
        $this->incident(3);

        $this->service()->compute($this->companyId, $this->siteId, $this->period);

        // Somebody retunes the model next quarter.
        $this->service()->saveWeights($this->companyId, ['incidents' => 1], $this->user->id);

        $explained = $this->service()->explain($this->companyId, $this->siteId, $this->period);

        $this->assertSame(14, (int) $explained['weights']['incidents'],
            'Reading back an old score shows today\'s weights, so last month\'s arithmetic looks '
            .'wrong and the trend becomes unreadable.');
    }

    // ── Configuration ─────────────────────────────────────────────────────────────────────────

    public function test_the_weights_are_configurable_and_a_change_is_audited(): void
    {
        $saved = $this->service()->saveWeights($this->companyId, [
            'incidents' => 20, 'near_misses' => 0,
        ], $this->user->id);

        $this->assertSame(20, $saved['incidents']);
        $this->assertSame(0, $saved['near_misses']);

        // Untouched signals keep their shipped weight rather than falling to zero.
        $this->assertSame(SiteRiskScoreService::SIGNALS['ncrs']['weight'], $saved['ncrs'],
            'Saving two weights silently zeroed the other nine.');

        $this->assertTrue(DB::table('sheq_audit_log')
            ->where('company_id', $this->companyId)
            ->where('action', 'SITE_RISK_WEIGHTS_CHANGED')->exists(),
            'Reweighting the model that decides which sites get attention is not audited.');
    }

    public function test_an_absurd_weight_is_capped(): void
    {
        $saved = $this->service()->saveWeights($this->companyId, [
            'incidents' => 9000, 'ncrs' => -5,
        ], $this->user->id);

        $this->assertSame(50, $saved['incidents'],
            'A weight of 9,000 makes every site with one incident score 100, and the figure stops '
            .'distinguishing them — which is the same as not having one.');
        $this->assertSame(0, $saved['ncrs']);
    }

    public function test_a_signal_added_after_a_tenant_saved_their_weights_still_has_one(): void
    {
        DB::table('sheq_settings')->updateOrInsert(['company_id' => $this->companyId],
            ['site_risk_weights' => json_encode(['incidents' => 11]), 'updated_at' => now()]);

        $weights = $this->service()->weightsFor($this->companyId);

        $this->assertSame(11, $weights['incidents']);

        foreach (SiteRiskScoreService::signalKeys() as $key) {
            $this->assertArrayHasKey($key, $weights,
                "The {$key} signal is undefined for a tenant who saved weights before it "
                .'existed, so it contributes nothing and nobody is told.');
        }
    }

    // ── Scoping ───────────────────────────────────────────────────────────────────────────────

    public function test_another_sites_events_do_not_count_against_this_one(): void
    {
        $other = $this->site('Yard');

        $this->attendance(100);
        $this->attendance(100, $other);
        $this->incident(5, $other);

        $mine = $this->service()->compute($this->companyId, $this->siteId, $this->period);

        $this->assertSame(0, $mine['counts']['incidents'],
            'Incidents from another site counted against this one, which is the bug the '
            .'contractor score had — every site carrying every other site\'s failures.');
    }

    public function test_computing_every_site_covers_the_active_ones(): void
    {
        $this->site('Yard');
        $this->attendance(50);

        $count = $this->service()->computeAll($this->companyId, $this->period);

        $this->assertGreaterThanOrEqual(2, $count);
        $this->assertSame($count, DB::table('sheq_site_risk_scores')
            ->where('company_id', $this->companyId)->where('period', $this->period)->count());
    }

    public function test_recomputing_the_same_period_replaces_rather_than_duplicates(): void
    {
        $this->attendance(100);
        $this->incident(2);

        $this->service()->compute($this->companyId, $this->siteId, $this->period);
        $this->service()->compute($this->companyId, $this->siteId, $this->period);
        $this->service()->compute($this->companyId, $this->siteId, $this->period);

        $this->assertSame(1, DB::table('sheq_site_risk_scores')
            ->where('company_id', $this->companyId)->where('site_id', $this->siteId)
            ->where('period', $this->period)->count(),
            'Recomputation appended, so a site has three scores for one month and the trend '
            .'shows whichever the query happens to order first.');
    }

    public function test_the_trend_runs_oldest_first(): void
    {
        $this->attendance(100);
        $this->incident(2);

        $this->service()->compute($this->companyId, $this->siteId,
            Carbon::now()->subMonths(2)->format('Y-m'));
        $this->service()->compute($this->companyId, $this->siteId,
            Carbon::now()->subMonth()->format('Y-m'));
        $this->service()->compute($this->companyId, $this->siteId, $this->period);

        $trend = $this->service()->trend($this->companyId, $this->siteId, 12);

        $this->assertCount(3, $trend);
        $this->assertSame(Carbon::now()->subMonths(2)->format('Y-m'), $trend[0]['period'],
            'The trend is newest-first, so every chart drawn from it runs backwards.');
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    private function weights(): array
    {
        return array_map(fn (array $s) => $s['weight'], SiteRiskScoreService::SIGNALS);
    }

    private function site(string $name): int
    {
        return (int) DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'S'.Str::upper(Str::random(5)), 'name' => $name,
            'site_type' => 'CONSTRUCTION', 'timezone' => 'Africa/Johannesburg',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function attendance(int $people, ?int $siteId = null): void
    {
        $rows = [];

        for ($i = 0; $i < $people; $i++) {
            $rows[] = [
                'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
                'site_id' => $siteId ?? $this->siteId, 'subject_type' => 'EMPLOYEE',
                'subject_id' => ($siteId ?? $this->siteId) * 100000 + $i,
                'person_name' => 'Person '.$i,
                'entered_at' => Carbon::now()->startOfMonth()->addDays(1),
                'created_at' => now(), 'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('sheq_site_attendance')->insert($chunk);
        }
    }

    private function incident(int $count, ?int $siteId = null): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('sheq_incidents')->insert([
                'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
                'reference' => 'INC-'.Str::upper(Str::random(8)),
                'site_id' => $siteId ?? $this->siteId,
                'incident_type' => 'INJURY', 'actual_severity' => 2, 'potential_severity' => 3,
                'occurred_at' => Carbon::now()->startOfMonth()->addDays(2),
                'reported_at' => Carbon::now()->startOfMonth()->addDays(2),
                'description' => 'Test incident', 'status' => 'REPORTED',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function observation(string $type, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('sheq_observations')->insert([
                'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
                'reference' => 'OBS-'.Str::upper(Str::random(8)),
                'observation_type' => $type, 'site_id' => $this->siteId,
                'behaviour' => 'Test behaviour', 'risk_rating' => 3,
                'observed_at' => Carbon::now()->startOfMonth()->addDays(3),
                'observed_by' => $this->user->id, 'status' => 'OPEN',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
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
