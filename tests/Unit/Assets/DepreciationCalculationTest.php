<?php

namespace Tests\Unit\Assets;

use App\Modules\Assets\Services\AssetDepreciationService;
use PHPUnit\Framework\TestCase;

class DepreciationCalculationTest extends TestCase
{
    private AssetDepreciationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AssetDepreciationService();
    }

    public function test_straight_line_with_zero_salvage(): void
    {
        // cost 100000, salvage 10000, life 5y → (100000-10000)/5 = 18000/yr
        $this->assertSame(18000.0, $this->service->straightLine(100000, 10000, 5));
    }

    public function test_straight_line_full_cost_when_no_salvage(): void
    {
        // cost 60000, salvage 0, life 3y → 20000/yr
        $this->assertSame(20000.0, $this->service->straightLine(60000, 0, 3));
    }

    public function test_straight_line_returns_zero_when_salvage_meets_or_exceeds_cost(): void
    {
        $this->assertSame(0.0, $this->service->straightLine(50000, 50000, 5));
        $this->assertSame(0.0, $this->service->straightLine(50000, 60000, 5));
    }

    public function test_straight_line_throws_for_invalid_life(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->straightLine(10000, 0, 0);
    }

    public function test_reducing_balance_year_one_at_forty_percent(): void
    {
        // cost 100000, rate 0.40, year 1 → 40000
        $this->assertSame(40000.0, $this->service->reducingBalance(100000, 0.40, 1));
    }

    public function test_reducing_balance_year_two_compounds(): void
    {
        // year 1: 40000, opening Y2 = 60000, dep Y2 = 24000
        $this->assertSame(24000.0, $this->service->reducingBalance(100000, 0.40, 2));
    }

    public function test_reducing_balance_does_not_drop_below_salvage(): void
    {
        // cost 1000, rate 0.5, salvage 800 → Y1 should depreciate only 200 (down to 800)
        $this->assertSame(100.0, $this->service->reducingBalance(1000, 0.5, 1, 800));
        // Y2: opening 900, but salvage floor 800 → can only deplete 100
        $this->assertSame(50.0, $this->service->reducingBalance(1000, 0.5, 2, 800));
    }

    public function test_reducing_balance_invalid_rate_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->reducingBalance(1000, 1.5, 1);
    }

    public function test_double_declining_balance_uses_2_over_life(): void
    {
        // cost 100000, life 5 → rate 0.40
        // Year 1: 100000 * 0.40 = 40000
        $this->assertSame(40000.0, $this->service->doubleDecliningBalance(100000, 0, 5, 1));
        // Year 2: 60000 * 0.40 = 24000
        $this->assertSame(24000.0, $this->service->doubleDecliningBalance(100000, 0, 5, 2));
    }

    public function test_double_declining_balance_returns_zero_when_salvage_exceeds_cost(): void
    {
        $this->assertSame(0.0, $this->service->doubleDecliningBalance(1000, 1000, 5, 1));
    }

    public function test_sum_of_years_digits_for_5_year_life(): void
    {
        // SYD = 15, cost 100000, salvage 10000, depreciable = 90000
        // Year 1: 5/15 * 90000 = 30000
        // Year 2: 4/15 * 90000 = 24000
        // Year 5: 1/15 * 90000 = 6000
        $this->assertSame(30000.0, $this->service->sumOfYearsDigits(100000, 10000, 5, 1));
        $this->assertSame(24000.0, $this->service->sumOfYearsDigits(100000, 10000, 5, 2));
        $this->assertSame(6000.0,  $this->service->sumOfYearsDigits(100000, 10000, 5, 5));
    }

    public function test_sum_of_years_digits_sums_to_total_depreciable(): void
    {
        $total = 0;
        for ($y = 1; $y <= 5; $y++) {
            $total += $this->service->sumOfYearsDigits(100000, 10000, 5, $y);
        }
        $this->assertEqualsWithDelta(90000, $total, 0.01);
    }

    public function test_sum_of_years_digits_invalid_year_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->sumOfYearsDigits(100000, 0, 5, 6);
    }

    public function test_units_of_production_proportional_to_units_used(): void
    {
        // cost 100000, salvage 10000, total units 50000, used 5000 → 9000
        $this->assertSame(9000.0, $this->service->unitsOfProduction(100000, 10000, 50000, 5000));
    }

    public function test_units_of_production_zero_units_zero_depreciation(): void
    {
        $this->assertSame(0.0, $this->service->unitsOfProduction(100000, 0, 50000, 0));
    }

    public function test_units_of_production_total_across_life_matches_depreciable(): void
    {
        // 10 batches of 5000 units = 50000 total → total depreciation = 90000
        $total = 0;
        for ($i = 0; $i < 10; $i++) {
            $total += $this->service->unitsOfProduction(100000, 10000, 50000, 5000);
        }
        $this->assertEqualsWithDelta(90000, $total, 0.01);
    }

    public function test_units_of_production_invalid_total_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->unitsOfProduction(100000, 0, 0, 100);
    }

    public function test_salvage_equal_to_cost_returns_zero_for_all_methods(): void
    {
        $this->assertSame(0.0, $this->service->straightLine(50000, 50000, 5));
        $this->assertSame(0.0, $this->service->reducingBalance(50000, 0.2, 1, 50000));
        $this->assertSame(0.0, $this->service->doubleDecliningBalance(50000, 50000, 5, 1));
        $this->assertSame(0.0, $this->service->sumOfYearsDigits(50000, 50000, 5, 1));
        $this->assertSame(0.0, $this->service->unitsOfProduction(50000, 50000, 1000, 100));
    }
}
