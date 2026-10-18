<?php

namespace Tests\Feature\ProcurementGovernance;

use App\Modules\Procurement\Governance\Services\AnalyticsService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The three contracts that decide whether this dashboard informs anybody or merely reassures them.
 *
 * A management dashboard is read by people who will not go and check, which is what makes its
 * failure modes expensive. All three tested here produce a number that looks correct:
 *
 *  - **Null, not zero, when nothing was measured.** "No deviations were raised" and "no deviation
 *    data exists for this period" render identically as a zero, and the first is a clean bill of
 *    health nobody earned.
 *  - **Median, not average, for durations.** One request abandoned over a holiday shutdown drags a
 *    mean far past anything that ever happened, and the mean is the statistic people quote.
 *  - **Every rate travels with its denominator.** 50% single-bid tenders is a scandal over two
 *    hundred tenders and a rounding artefact over two.
 *
 * A fourth is tested because it is the one that goes wrong silently as the module grows: every
 * measure is scoped to the company that asked. A tenant seeing another tenant's competition data
 * would be a breach of the sealed-bid process itself.
 */
class AnalyticsServiceTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private Carbon $from;

    private Carbon $to;

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

        // A window in the past, so nothing this platform's other tests or seeders created lands
        // inside it. Every assertion below is then about rows this test put there itself.
        $this->from = Carbon::create(2015, 1, 1)->startOfDay();
        $this->to = Carbon::create(2015, 12, 31)->endOfDay();
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    private function analytics(): AnalyticsService
    {
        return app(AnalyticsService::class);
    }

    private function makeTender(?Carbon $createdAt = null, ?int $companyId = null): int
    {
        $at = $createdAt ?? $this->from->copy()->addMonth();

        return (int) DB::table('procgov_tender_reviews')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId ?? $this->companyId,
            'reference' => 'TR-'.Str::upper(Str::random(8)),
            'title' => 'Analytics fixture',
            'currency' => 'ZAR',
            'methodology' => 'PRICE_ONLY',
            'status' => 'OPEN',
            'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    private function makeBidder(int $reviewId, ?int $companyId = null): int
    {
        return (int) DB::table('procgov_bidders')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId ?? $this->companyId,
            'review_id' => $reviewId,
            'supplier_name' => 'Bidder '.Str::random(6),
            'bid_amount' => 100000,
            'currency' => 'ZAR',
            'is_late' => false,
            'is_disqualified' => false,
            'status' => 'REGISTERED',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** An approval request that took exactly $days to complete. */
    private function makeApproval(float $days): int
    {
        $requestedAt = $this->from->copy()->addMonth();

        return (int) DB::table('procgov_approval_requests')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'subject_type' => 'record',
            'subject_id' => 1,
            'status' => 'APPROVED',
            'requested_at' => $requestedAt,
            'completed_at' => $requestedAt->copy()->addMinutes((int) round($days * 1440)),
            'created_at' => $requestedAt, 'updated_at' => $requestedAt,
        ]);
    }

    public function test_a_rate_with_nothing_to_measure_is_null_rather_than_zero(): void
    {
        // Nothing whatsoever in the window.
        $competition = $this->analytics()->competition($this->companyId, $this->from, $this->to);
        $deviations = $this->analytics()->deviations($this->companyId, $this->from, $this->to);
        $pipeline = $this->analytics()->pipeline($this->companyId, $this->from, $this->to);
        $approvals = $this->analytics()->approvals($this->companyId, $this->from, $this->to);

        $this->assertNull($competition['single_bid_pct'], 'No tenders drew a bid, so there is no single-bid rate to state.');
        $this->assertNull($competition['median_bidders']);
        $this->assertNull($deviations['rate_pct'], 'No procurements were opened, so there is no deviation rate.');
        $this->assertNull($pipeline['plan_to_market_pct'], 'Nothing was planned, so nothing failed to reach the market.');
        $this->assertNull($approvals['median_days_to_decide']);

        // Counts are still counts. Only the derived rates go null.
        $this->assertSame(0, $competition['tenders']);
        $this->assertSame(0, $deviations['total']);
    }

    public function test_zero_is_reported_when_there_is_something_to_measure_and_it_is_zero(): void
    {
        /*
         * The other half of the contract, and the half that is easy to break while fixing the first.
         * A period with real activity and no deviations must say 0%, not "unknown" — otherwise the
         * null becomes meaningless and the screen stops distinguishing the two cases at all.
         */
        DB::table('procgov_records')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'reference' => 'PR-'.Str::upper(Str::random(8)),
            'title' => 'Opened, never deviated',
            'currency' => 'ZAR',
            'stage' => 'INITIATION',
            'status' => 'ACTIVE',
            'opened_at' => $this->from->copy()->addMonth(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $deviations = $this->analytics()->deviations($this->companyId, $this->from, $this->to);

        $this->assertSame(1, $deviations['records_opened']);
        $this->assertSame(0.0, $deviations['rate_pct'], 'One procurement, no deviations: that is a measured zero.');
    }

    public function test_the_single_bid_rate_is_published_with_the_count_it_came_from(): void
    {
        // Four tenders: two with one bid, one with three bids, one that drew nothing at all.
        $this->makeBidder($this->makeTender());
        $this->makeBidder($this->makeTender());

        $busy = $this->makeTender();
        $this->makeBidder($busy);
        $this->makeBidder($busy);
        $this->makeBidder($busy);

        $this->makeTender();

        $competition = $this->analytics()->competition($this->companyId, $this->from, $this->to);
        $headlines = $this->analytics()->headlines($this->companyId, $this->from, $this->to);

        $this->assertSame(4, $competition['tenders']);
        $this->assertSame(1, $competition['tenders_with_no_bids']);
        $this->assertSame(2, $competition['single_bid_tenders']);

        /*
         * Two of the three tenders that drew anything — not two of four.
         *
         * Counting the no-bid tender in the denominator would report 50% and understate how
         * concentrated the interest actually was among tenders the market responded to.
         */
        $this->assertSame(66.7, $competition['single_bid_pct']);

        // The headline must carry that same denominator, or the screen prints a bare percentage.
        $this->assertSame(3, $headlines['single_bid_of']);
        $this->assertSame(66.7, $headlines['single_bid_pct']);
    }

    public function test_approval_duration_is_the_median_so_one_abandoned_request_cannot_move_it(): void
    {
        /*
         * Four requests decided in about a day, and one left for most of a year. The mean says a
         * decision takes over two months; the median says it takes a day, which is what actually
         * happens four times out of five.
         */
        foreach ([1.0, 1.0, 1.0, 1.0, 300.0] as $days) {
            $this->makeApproval($days);
        }

        $approvals = $this->analytics()->approvals($this->companyId, $this->from, $this->to);

        $this->assertSame(5, $approvals['completed']);
        $this->assertEqualsWithDelta(1.0, $approvals['median_days_to_decide'], 0.01);
        $this->assertGreaterThan(50, $approvals['average_days_to_decide'], 'The mean is dragged by the outlier — which is why it is not the headline.');

        // Both are returned. A wide gap between them is itself the finding, and hiding the mean
        // would hide it.
        $this->assertNotNull($approvals['average_days_to_decide']);
    }

    public function test_the_median_of_an_even_set_averages_the_middle_pair(): void
    {
        foreach ([2.0, 4.0] as $days) {
            $this->makeApproval($days);
        }

        $approvals = $this->analytics()->approvals($this->companyId, $this->from, $this->to);

        $this->assertEqualsWithDelta(3.0, $approvals['median_days_to_decide'], 0.01);
    }

    public function test_every_measure_filters_on_the_company_it_was_asked_about(): void
    {
        /*
         * Row-level security is the outer belt and it is genuinely load-bearing — it is why this
         * test cannot build the obvious fixture, since the database will not let the session insert
         * or read a row belonging to another tenant at all.
         *
         * What is tested instead is the braces: asking for a company that is not the session's must
         * come back empty. A measure that dropped its `where company_id` clause would satisfy RLS
         * perfectly and answer with the *current* tenant's rows under someone else's name, which is
         * exactly the bug a policy-only defence cannot see.
         */
        $tender = $this->makeTender();
        $this->makeBidder($tender);

        $mine = $this->analytics()->competition($this->companyId, $this->from, $this->to);

        $this->assertSame(1, $mine['tenders']);
        $this->assertSame(1, $mine['bidders']);

        $stranger = $this->analytics()->competition($this->companyId + 100_000, $this->from, $this->to);

        $this->assertSame(0, $stranger['tenders'], 'A measure asked about another company answered with this one\'s tenders.');
        $this->assertSame(0, $stranger['bidders']);
        $this->assertSame(0, $this->analytics()->approvals($this->companyId + 100_000, $this->from, $this->to)['raised']);
        $this->assertSame(0, $this->analytics()->deviations($this->companyId + 100_000, $this->from, $this->to)['total']);
    }

    public function test_the_trend_returns_one_row_per_month_and_refuses_to_run_away(): void
    {
        $months = $this->analytics()->trend($this->companyId, $this->from, $this->to);

        $this->assertCount(12, $months, 'A calendar year is twelve points.');
        $this->assertSame('2015-01', $months[0]['month']);
        $this->assertSame('2015-12', $months[11]['month']);

        // A user can type any financial year into the filter. Ten years of monthly points is a
        // slow query and an unreadable chart, so the series is capped rather than trusted.
        $long = $this->analytics()->trend($this->companyId, Carbon::create(1990, 1, 1), Carbon::create(2030, 1, 1));

        $this->assertLessThanOrEqual(36, count($long));
    }

    public function test_every_measure_survives_a_company_with_no_procurement_at_all(): void
    {
        /*
         * The first day of a new tenant, which is when a dashboard is most likely to be opened and
         * least likely to have been tested. Nothing here may divide by zero or index a missing key.
         */
        $service = $this->analytics();

        foreach (['pipeline', 'competition', 'deviations', 'approvals', 'awards', 'headlines'] as $measure) {
            $result = $service->{$measure}($this->companyId, $this->from, $this->to);

            $this->assertIsArray($result, "{$measure}() returned nothing for an empty period.");
        }

        // intake() is the one measure allowed to return null outright: the electronic bid portal is
        // an optional part of the module, and its tables may not exist on an older install.
        $intake = $service->intake($this->companyId, $this->from, $this->to);

        $this->assertTrue($intake === null || is_array($intake));
    }
}
