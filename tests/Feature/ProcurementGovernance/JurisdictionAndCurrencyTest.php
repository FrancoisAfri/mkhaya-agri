<?php

namespace Tests\Feature\ProcurementGovernance;

use App\Modules\Procurement\Governance\Exceptions\DomainRuleException;
use App\Modules\Procurement\Governance\Services\PreferenceCalculator;
use App\Modules\Procurement\Governance\Services\ScoringEngine;
use App\Modules\Procurement\Governance\Services\SettingsService;
use App\Modules\Procurement\Governance\Support\Integration\CurrencyBridge;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * §1.6 across three jurisdictions: bids compared in one currency, preference derived from a
 * configured framework rather than a typed-in number.
 *
 * The bug these tests exist for is the quietest kind. The engine ranked on bid_amount while
 * ignoring the currency column beside it, so a 900,000 BWP bid beat a 1,000,000 ZAR bid on the
 * arithmetic alone. Nothing errored, the audit ledger recorded the result faithfully, and the wrong
 * bidder won. A single-country installation would never have shown it.
 */
class JurisdictionAndCurrencyTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $reviewId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        Cache::flush();
        app(CurrentCompany::class)->set($this->companyId);

        $this->reviewId = $this->makeReview();
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    private function makeReview(string $currency = 'ZAR', ?string $jurisdiction = null): int
    {
        return (int) DB::table('procgov_tender_reviews')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'reference' => 'TR-'.Str::upper(Str::random(6)),
            'title' => 'Cross-border tender',
            'currency' => $currency,
            'jurisdiction' => $jurisdiction,
            'methodology' => 'PRICE_ONLY',
            'status' => 'EVALUATION',
            'tender_closing_at' => Carbon::parse('2026-06-30 12:00:00'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeBidder(string $name, float $amount, string $currency, array $extra = []): int
    {
        return (int) DB::table('procgov_bidders')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'review_id' => $this->reviewId,
            'supplier_name' => $name,
            'bid_amount' => $amount,
            'currency' => $currency,
            'is_disqualified' => false,
            'status' => 'SUBMITTED',
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    private function loadRate(string $base, string $quote, float $rate, string $date = '2026-06-30'): void
    {
        DB::table('fin_exchange_rates')->insert([
            'company_id' => $this->companyId,
            'base_currency' => $base,
            'quote_currency' => $quote,
            'rate' => $rate,
            'rate_type' => 'spot',
            'source' => 'test',
            'effective_date' => $date,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── Currency ────────────────────────────────────────────────────────────────────────────────

    public function test_a_cheaper_looking_foreign_bid_does_not_win_on_the_arithmetic(): void
    {
        // 900,000 BWP at 1.30 ZAR/BWP is 1,170,000 ZAR — dearer than the 1,000,000 ZAR bid, though
        // the raw numbers say otherwise. This is the whole point.
        $this->loadRate('BWP', 'ZAR', 1.30);

        $local = $this->makeBidder('Local', 1_000_000, 'ZAR');
        $foreign = $this->makeBidder('Foreign', 900_000, 'BWP');

        app(ScoringEngine::class)->computeReview($this->companyId, $this->reviewId);

        $rows = DB::table('procgov_bidders')->whereIn('id', [$local, $foreign])->get()->keyBy('id');

        $this->assertEqualsWithDelta(1_170_000, (float) $rows[$foreign]->bid_amount_base, 0.01);
        $this->assertEqualsWithDelta(1_000_000, (float) $rows[$local]->bid_amount_base, 0.01);
        $this->assertSame(1, (int) $rows[$local]->financial_rank, 'The genuinely cheaper bid did not rank first.');
        $this->assertSame(2, (int) $rows[$foreign]->financial_rank);
    }

    public function test_the_original_bid_amount_is_never_overwritten(): void
    {
        // The bid was made in pula and the record has to keep saying so.
        $this->loadRate('BWP', 'ZAR', 1.30);
        $foreign = $this->makeBidder('Foreign', 900_000, 'BWP');

        app(ScoringEngine::class)->computeReview($this->companyId, $this->reviewId);

        $row = DB::table('procgov_bidders')->find($foreign);

        $this->assertEqualsWithDelta(900_000, (float) $row->bid_amount, 0.01);
        $this->assertSame('BWP', $row->currency);
    }

    public function test_the_rate_used_is_recorded_so_a_ranking_can_be_reproduced(): void
    {
        $this->loadRate('BWP', 'ZAR', 1.30);
        $foreign = $this->makeBidder('Foreign', 900_000, 'BWP');

        app(ScoringEngine::class)->computeReview($this->companyId, $this->reviewId);

        $row = DB::table('procgov_bidders')->find($foreign);

        $this->assertEqualsWithDelta(1.30, (float) $row->fx_rate, 0.00001);
        $this->assertNotNull($row->fx_rate_date);
        $this->assertNotNull($row->fx_rate_source);
    }

    public function test_a_missing_rate_stops_the_ranking_rather_than_guessing(): void
    {
        // Ranking on an unconverted figure is the silent wrong award. Refusing is the safe failure.
        $this->makeBidder('Local', 1_000_000, 'ZAR');
        $this->makeBidder('Foreign', 900_000, 'BWP');

        $this->expectException(DomainRuleException::class);

        app(ScoringEngine::class)->computeReview($this->companyId, $this->reviewId);
    }

    public function test_eswatini_needs_no_rate_because_the_lilangeni_is_pegged_to_the_rand(): void
    {
        // SZL is fixed 1:1 to ZAR by treaty. Requiring a daily 1.0 row would stop Eswatini
        // evaluations on any day someone forgot to load one.
        $bidder = $this->makeBidder('Mbabane supplier', 1_000_000, 'SZL');

        app(ScoringEngine::class)->computeReview($this->companyId, $this->reviewId);

        $row = DB::table('procgov_bidders')->find($bidder);

        $this->assertEqualsWithDelta(1_000_000, (float) $row->bid_amount_base, 0.01);
        $this->assertSame('cma_parity', $row->fx_rate_source);
    }

    public function test_a_rate_can_be_inverted_so_only_one_direction_need_be_maintained(): void
    {
        $bridge = app(CurrencyBridge::class);
        $this->loadRate('ZAR', 'BWP', 0.7692308);

        $rate = $bridge->rate($this->companyId, 'BWP', 'ZAR', Carbon::parse('2026-06-30'));

        $this->assertNotNull($rate);
        $this->assertEqualsWithDelta(1.30, $rate['rate'], 0.001);
        $this->assertStringContainsString('inverted', $rate['source']);
    }

    public function test_the_most_recent_rate_on_or_before_the_date_is_used(): void
    {
        // A tender closing on a Saturday is not a reason to stop an evaluation.
        $bridge = app(CurrencyBridge::class);
        $this->loadRate('BWP', 'ZAR', 1.20, '2026-06-25');
        $this->loadRate('BWP', 'ZAR', 1.30, '2026-06-29');
        $this->loadRate('BWP', 'ZAR', 9.99, '2026-07-05');

        $rate = $bridge->rate($this->companyId, 'BWP', 'ZAR', Carbon::parse('2026-06-30'));

        $this->assertEqualsWithDelta(1.30, $rate['rate'], 0.001);
    }

    public function test_every_bid_is_converted_at_the_same_date_by_default(): void
    {
        // Under the CLOSING basis two identical offers must convert identically regardless of when
        // each arrived, otherwise submission timing quietly becomes a ranking factor.
        $this->loadRate('BWP', 'ZAR', 1.20, '2026-06-01');
        $this->loadRate('BWP', 'ZAR', 1.30, '2026-06-29');

        $early = $this->makeBidder('Early', 900_000, 'BWP', ['submitted_at' => Carbon::parse('2026-06-02')]);
        $late = $this->makeBidder('Late', 900_000, 'BWP', ['submitted_at' => Carbon::parse('2026-06-29')]);

        app(ScoringEngine::class)->computeReview($this->companyId, $this->reviewId);

        $rows = DB::table('procgov_bidders')->whereIn('id', [$early, $late])->get()->keyBy('id');

        $this->assertSame(
            (float) $rows[$early]->bid_amount_base,
            (float) $rows[$late]->bid_amount_base,
            'Identical bids converted differently because of when they arrived.',
        );
    }

    public function test_a_single_currency_tender_ranks_exactly_as_it_did_before(): void
    {
        $a = $this->makeBidder('A', 1_000_000, 'ZAR');
        $b = $this->makeBidder('B', 900_000, 'ZAR');

        app(ScoringEngine::class)->computeReview($this->companyId, $this->reviewId);

        $rows = DB::table('procgov_bidders')->whereIn('id', [$a, $b])->get()->keyBy('id');

        $this->assertSame(1, (int) $rows[$b]->financial_rank);
        $this->assertSame(2, (int) $rows[$a]->financial_rank);
    }

    // ── Preference ──────────────────────────────────────────────────────────────────────────────

    private function framework(string $code): ?object
    {
        return DB::table('procgov_preference_frameworks')
            ->where('company_id', $this->companyId)
            ->where('code', $code)
            ->first();
    }

    public function test_preference_points_are_derived_from_the_bidders_level(): void
    {
        $framework = $this->framework('ZA_PPPFA_8020');

        if (! $framework) {
            $this->markTestSkipped('Preference frameworks are not seeded in this database.');
        }

        $result = app(PreferenceCalculator::class)
            ->pointsFor($this->companyId, $framework, (object) ['bbbee_level' => 2]);

        $this->assertSame(18.0, $result['points']);
        $this->assertStringContainsString('level 2', strtolower((string) $result['band']));
    }

    public function test_the_9010_framework_awards_a_smaller_maximum(): void
    {
        $framework = $this->framework('ZA_PPPFA_9010');

        if (! $framework) {
            $this->markTestSkipped('Preference frameworks are not seeded in this database.');
        }

        $result = app(PreferenceCalculator::class)
            ->pointsFor($this->companyId, $framework, (object) ['bbbee_level' => 1]);

        $this->assertSame(10.0, $result['points']);
    }

    public function test_a_bidder_matching_no_band_yields_no_points_rather_than_zero(): void
    {
        // "Qualified for nothing" and "we could not tell" are different claims and only one of them
        // is safe to award on.
        $framework = $this->framework('ZA_PPPFA_8020');

        if (! $framework) {
            $this->markTestSkipped('Preference frameworks are not seeded in this database.');
        }

        $result = app(PreferenceCalculator::class)
            ->pointsFor($this->companyId, $framework, (object) ['bbbee_level' => null]);

        $this->assertNull($result['points']);
        $this->assertStringContainsString('no band', strtolower($result['reason']));
    }

    public function test_seeded_frameworks_are_marked_unverified_so_nobody_mistakes_them_for_law(): void
    {
        $frameworks = DB::table('procgov_preference_frameworks')
            ->where('company_id', $this->companyId)
            ->get();

        if ($frameworks->isEmpty()) {
            $this->markTestSkipped('Preference frameworks are not seeded in this database.');
        }

        foreach ($frameworks as $framework) {
            $this->assertFalse(
                (bool) $framework->is_verified,
                "{$framework->code} ships marked verified — a seeded preference table must be confirmed first.",
            );
        }
    }

    public function test_the_regional_frameworks_ship_empty_rather_than_invented(): void
    {
        foreach (['SZ_LOCAL_PREFERENCE', 'BW_CITIZEN_PREFERENCE'] as $code) {
            $framework = $this->framework($code);

            if (! $framework) {
                $this->markTestSkipped('Preference frameworks are not seeded in this database.');
            }

            $bands = DB::table('procgov_preference_bands')->where('framework_id', $framework->id)->count();

            $this->assertSame(0, $bands, "{$code} ships with invented figures.");
            $this->assertSame('MARGIN', $framework->basis);
        }
    }

    public function test_a_tender_uses_the_framework_for_its_own_jurisdiction(): void
    {
        if (! $this->framework('BW_CITIZEN_PREFERENCE')) {
            $this->markTestSkipped('Preference frameworks are not seeded in this database.');
        }

        $review = (object) ['jurisdiction' => 'BW', 'preference_framework_code' => null];

        $chosen = app(PreferenceCalculator::class)->frameworkFor($this->companyId, $review, 'ZA');

        $this->assertSame('BW', $chosen->jurisdiction, 'A Botswana tender fell back to the ZA framework.');
    }

    // ── Persistence ─────────────────────────────────────────────────────────────────────────────

    public function test_the_tenant_jurisdiction_and_rate_basis_are_saved(): void
    {
        app(SettingsService::class)->update($this->companyId, [
            'jurisdiction' => 'BW',
            'fx_rate_basis' => 'EVALUATION',
            'fx_rate_type' => 'closing',
        ]);

        $settings = DB::table('procgov_settings')->where('company_id', $this->companyId)->first();

        $this->assertSame('BW', $settings->jurisdiction);
        $this->assertSame('EVALUATION', $settings->fx_rate_basis);
        $this->assertSame('closing', $settings->fx_rate_type);
    }

    public function test_a_tender_keeps_the_jurisdiction_it_was_raised_under(): void
    {
        $reviewId = $this->makeReview('BWP', 'BW');

        $review = DB::table('procgov_tender_reviews')->find($reviewId);

        $this->assertSame('BW', $review->jurisdiction);
        $this->assertSame('BWP', $review->currency);
    }

    public function test_a_tender_without_a_jurisdiction_follows_the_tenant(): void
    {
        // Existing tenders predate the column entirely and must keep behaving as they did.
        $review = (object) ['jurisdiction' => null, 'preference_framework_code' => null];

        $chosen = app(PreferenceCalculator::class)->frameworkFor($this->companyId, $review, 'ZA');

        if (! $chosen) {
            $this->markTestSkipped('Preference frameworks are not seeded in this database.');
        }

        $this->assertSame('ZA', $chosen->jurisdiction);
    }

    public function test_a_named_framework_overrides_the_jurisdiction_default(): void
    {
        if (! $this->framework('ZA_PPPFA_9010')) {
            $this->markTestSkipped('Preference frameworks are not seeded in this database.');
        }

        $review = (object) ['jurisdiction' => 'ZA', 'preference_framework_code' => 'ZA_PPPFA_9010'];

        $this->assertSame(
            'ZA_PPPFA_9010',
            app(PreferenceCalculator::class)->frameworkFor($this->companyId, $review, 'ZA')->code,
        );
    }

    public function test_a_framework_belonging_to_another_tenant_is_never_chosen(): void
    {
        $other = DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'slug' => 'pref-'.Str::lower(Str::random(8)),
            'name' => 'Other tenant',
            'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('procgov_preference_frameworks')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => $other,
            'code' => 'OTHER_ONLY',
            'name' => 'Other tenant framework',
            'jurisdiction' => 'ZW',
            'basis' => 'POINTS',
            'max_points' => 20,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $review = (object) ['jurisdiction' => 'ZW', 'preference_framework_code' => null];

        $this->assertNull(
            app(PreferenceCalculator::class)->frameworkFor($this->companyId, $review, 'ZA'),
            'A framework from another tenant was offered.',
        );
    }
}
