<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Finance\Accounting\Models\FinExchangeRate;
use App\Modules\Operations\ProjectManagement\Services\EarnedValueService;
use App\Modules\Tenancy\Models\Company;

/**
 * A portfolio total spanning two currencies.
 *
 * `portfolio()` summed every active project's budget, planned value, earned value and actual cost
 * straight into one figure. A project budgeted in dollars and one in rand were added as though the
 * units were the same, so the total was wrong by whatever the exchange rate happened to be — and
 * wrong silently, because nothing on the number said which currency it was in.
 *
 * The rates come from `FinExchangeRate`, which already handles the parts that are easy to get
 * wrong: the most recent rate on or before a date, and the inverse pair where only one direction is
 * captured. What is decided here is what to do when there is no rate at all, and the answer is to
 * leave the project out and name its currency — a total that omits something and says so can be
 * acted on; one quietly wrong by a factor of eighteen cannot.
 */
class PortfolioCurrencyTest extends ProjectManagementTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Aseda Portfolio']);
        $this->entitleCompany($this->company);
    }

    private function projectWorth(string $currency, float $budget): void
    {
        $this->asCompany($this->company, function () use ($currency, $budget) {
            $project = $this->makeProject([
                'code' => 'P' . fake()->unique()->numberBetween(10000, 99999),
                'status' => 'active', 'currency' => $currency, 'budget_amount' => $budget,
            ]);

            // Budget alone is the BAC; no tasks are needed to make the roll-up meaningful.
            return $project;
        });
    }

    private function rate(string $from, string $to, float $rate): void
    {
        FinExchangeRate::create([
            'base_currency' => $from, 'quote_currency' => $to, 'rate' => $rate,
            'rate_type' => 'spot', 'effective_date' => now()->subDay()->toDateString(),
        ]);
    }

    private function portfolio(?string $currency = null): array
    {
        return $this->asCompany($this->company, fn () => app(EarnedValueService::class)->portfolio($currency));
    }

    // ── The reported behaviour ───────────────────────────────────────────────

    public function test_a_foreign_project_is_converted_rather_than_added_as_is(): void
    {
        $this->projectWorth('ZAR', 100000);
        $this->projectWorth('USD', 10000);
        $this->rate('USD', 'ZAR', 18.0);

        $portfolio = $this->portfolio('ZAR');

        // 100 000 + (10 000 × 18)
        $this->assertEqualsWithDelta(280000.0, $portfolio['bac'], 0.01,
            'Dollars were added to rand as though the units were the same.');
        $this->assertSame('ZAR', $portfolio['currency']);
    }

    /** The rate used and the date it was read, so a report reproduces its own figures later. */
    public function test_it_states_the_rate_and_the_date_it_used(): void
    {
        $this->projectWorth('USD', 10000);
        $this->rate('USD', 'ZAR', 18.0);

        $portfolio = $this->portfolio('ZAR');

        $this->assertEqualsWithDelta(18.0, $portfolio['rates']['USD'], 0.0001);
        $this->assertSame(now()->toDateString(), $portfolio['rate_date']);
    }

    /** Only one direction is ever captured in practice; the inverse has to work. */
    public function test_it_uses_the_inverse_pair_when_only_one_direction_is_recorded(): void
    {
        $this->projectWorth('USD', 10000);
        $this->rate('ZAR', 'USD', 0.05);

        $this->assertEqualsWithDelta(200000.0, $this->portfolio('ZAR')['bac'], 1.0);
    }

    // ── When there is no rate ────────────────────────────────────────────────

    /**
     * Left out and named, never guessed at parity. Treating an unknown rate as 1.0 is how a dollar
     * project enters a rand total at a sixth of its size.
     */
    public function test_a_currency_with_no_rate_is_excluded_and_reported(): void
    {
        $this->projectWorth('ZAR', 100000);
        $this->projectWorth('GBP', 50000);

        $portfolio = $this->portfolio('ZAR');

        $this->assertEqualsWithDelta(100000.0, $portfolio['bac'], 0.01,
            'A project with no exchange rate was added at parity.');
        $this->assertContains('GBP', $portfolio['excluded_currencies']);
    }

    // ── What must not change ─────────────────────────────────────────────────

    /** A single-currency estate is the ordinary case and must be untouched by any of this. */
    public function test_a_single_currency_portfolio_is_unaffected(): void
    {
        $this->projectWorth('ZAR', 100000);
        $this->projectWorth('ZAR', 250000);

        $portfolio = $this->portfolio('ZAR');

        $this->assertEqualsWithDelta(350000.0, $portfolio['bac'], 0.01);
        $this->assertSame([], $portfolio['excluded_currencies']);
        $this->assertSame([], $portfolio['rates']);
    }
}
