<?php

namespace Tests\Feature\Budget;

use App\Modules\Budget\Models\Budget;
use App\Modules\Budget\Models\BudgetPeriod;
use App\Modules\Budget\Models\FinancialYear;
use App\Modules\Budget\Services\BudgetPlanningService;
use App\Modules\Budget\Services\BudgetService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Reforecasting and variance explanations (tender requirement 4.4: forecasting, variance analysis).
 *
 * The rules defended here: a closed period that missed plan by the year's threshold must be
 * explained and is listed until it is; only closed periods can be explained and only open ones
 * reforecast; and the owner's year-end reforecast counts closed months at actual, open months at
 * their forecast (or plan), and never forecasts away money already spent.
 *
 * Fixture: four monthly periods of 10,000 each — two closed, the current one, and next month.
 */
class BudgetReforecastAndVarianceTest extends TestCase
{
    use DatabaseTransactions;

    private FinancialYear $year;
    private Budget $budget;
    /** @var array<int, BudgetPeriod> */
    private array $periods = [];

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }
        app(CurrentCompany::class)->set((int) $companyId);

        $start = now()->subMonthsNoOverflow(2)->startOfMonth();
        $this->year = FinancialYear::create([
            'name' => 'Reforecast FY ' . Str::random(4),
            'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addYear()->subDay()->toDateString(),
        ]);

        foreach (range(0, 3) as $i) {
            $month = $start->copy()->addMonthsNoOverflow($i);
            $this->periods[$i] = BudgetPeriod::create([
                'financial_year_id' => $this->year->id, 'name' => $month->format('M Y'),
                'start_date' => $month->toDateString(), 'end_date' => $month->copy()->endOfMonth()->toDateString(),
            ]);
        }

        $this->budget = Budget::create([
            'code' => 'RF' . Str::random(6), 'name' => 'Marketing', 'financial_year_id' => $this->year->id,
            'expenditure_type' => 'opex', 'scope_type' => 'general',
            'currency' => 'ZAR', 'allocated_amount' => 40_000, 'status' => 'active',
        ]);

        $this->planning()->setPhasing($this->budget, array_map(
            fn (BudgetPeriod $p) => ['period_id' => $p->id, 'amount' => 10_000],
            $this->periods,
        ));

        $this->spend(0, 15_000); // 50% over plan — material
        $this->spend(1, 10_500); // 5% over — within the default 10% threshold
        $this->spend(2, 2_000);  // current month, still open
    }

    public function test_a_closed_period_over_the_threshold_must_be_explained(): void
    {
        $rows = collect($this->planning()->phasing($this->budget->fresh()))->keyBy('period_id');

        $this->assertTrue($rows[$this->periods[0]->id]['needs_explanation']);
        $this->assertSame(50.0, $rows[$this->periods[0]->id]['variance_pct']);
        $this->assertFalse($rows[$this->periods[1]->id]['material'], 'A 5% miss is within the 10% threshold.');
        $this->assertFalse($rows[$this->periods[2]->id]['material'], 'An open period has no variance to explain yet.');

        $waiting = $this->planning()->materialVariances($this->year->id, null, true);
        $this->assertSame([$this->periods[0]->id], $waiting->pluck('period_id')->all());
    }

    public function test_an_explanation_clears_the_flag_and_is_kept_with_the_period(): void
    {
        $ok = $this->planning()->explainVariance($this->budget, $this->periods[0]->id, 'Trade show deposit paid early.');

        $this->assertTrue($ok);
        $row = collect($this->planning()->phasing($this->budget->fresh()))->firstWhere('period_id', $this->periods[0]->id);
        $this->assertFalse($row['needs_explanation']);
        $this->assertTrue($row['material'], 'Still material — it is now explained, not hidden.');
        $this->assertSame('Trade show deposit paid early.', $row['explanation']);
        $this->assertSame(10_000.0, $row['planned'], 'Explaining must not touch the plan.');
        $this->assertCount(0, $this->planning()->materialVariances($this->year->id, null, true));
    }

    public function test_only_a_closed_period_can_be_explained(): void
    {
        $this->assertFalse($this->planning()->explainVariance($this->budget, $this->periods[2]->id, 'Too early to say.'));
    }

    public function test_the_threshold_is_the_financial_years(): void
    {
        $this->year->update(['variance_threshold_pct' => 60]);

        $row = collect($this->planning()->phasing($this->budget->fresh()))->firstWhere('period_id', $this->periods[0]->id);
        $this->assertFalse($row['material'], 'A 50% miss is within a 60% threshold.');
    }

    public function test_no_reforecast_is_shown_until_an_owner_enters_one(): void
    {
        $this->assertNull($this->planning()->reforecast($this->budget->fresh())['reforecast']);
    }

    public function test_the_reforecast_counts_closed_months_at_actual_and_open_months_at_forecast_or_plan(): void
    {
        $changed = $this->planning()->setForecast($this->budget, [
            ['period_id' => $this->periods[0]->id, 'amount' => 1],      // closed: ignored
            ['period_id' => $this->periods[2]->id, 'amount' => 20_000], // this month: event moved in
        ]);

        $this->assertSame(1, $changed, 'A closed period cannot be reforecast.');

        $result = $this->planning()->reforecast($this->budget->fresh());

        // 15,000 + 10,500 actual, 20,000 forecast, 10,000 plan for the month not reforecast.
        $this->assertSame(55_500.0, $result['reforecast']);
        $this->assertSame(-15_500.0, $result['reforecast_variance']);
    }

    public function test_a_forecast_below_what_has_already_gone_counts_the_actual(): void
    {
        $this->planning()->setForecast($this->budget, [['period_id' => $this->periods[2]->id, 'amount' => 500]]);

        $row = collect($this->planning()->phasing($this->budget->fresh()))->firstWhere('period_id', $this->periods[2]->id);
        $this->assertSame(2_000.0, $row['outlook'], 'Money already spent cannot be forecast away.');
    }

    private function spend(int $period, float $amount): void
    {
        $consumption = app(BudgetService::class)->consumeBudget($this->budget, $amount);
        $at = Carbon::parse($this->periods[$period]->start_date)->addDays(2)->setTime(10, 0);
        DB::table('budget_consumptions')->where('id', $consumption->id)->update(['created_at' => $at]);
    }

    private function planning(): BudgetPlanningService
    {
        return app(BudgetPlanningService::class);
    }
}
