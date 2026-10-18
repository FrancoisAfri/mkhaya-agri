<?php

namespace App\Modules\Assets\Services;

/**
 * Pure depreciation calculations.
 *
 * All methods return annual depreciation amounts (per period = 1 year)
 * unless noted. Monthly conversion is the caller's responsibility.
 */
class AssetDepreciationService
{
    /**
     * Straight-line: (cost - salvage) / life
     */
    public function straightLine(float $cost, float $salvage, int $lifeYears): float
    {
        if ($lifeYears <= 0) {
            throw new \InvalidArgumentException('lifeYears must be > 0');
        }
        if ($salvage >= $cost) {
            return 0.0;
        }
        return round(($cost - $salvage) / $lifeYears, 2);
    }

    /**
     * Reducing balance: openingBookValue * rate
     * Returns the depreciation for the requested year (1-indexed).
     */
    public function reducingBalance(float $cost, float $rate, int $year, float $salvage = 0): float
    {
        if ($rate <= 0 || $rate >= 1) {
            throw new \InvalidArgumentException('rate must be between 0 and 1');
        }
        if ($year < 1) {
            throw new \InvalidArgumentException('year must be >= 1');
        }
        if ($salvage >= $cost) {
            return 0.0;
        }

        $book = $cost;
        $dep  = 0.0;
        for ($y = 1; $y <= $year; $y++) {
            $dep  = max(0, ($book - $salvage) * $rate);
            // Don't drop below salvage
            if ($book - $dep < $salvage) {
                $dep = $book - $salvage;
            }
            $book = $book - $dep;
        }
        return round($dep, 2);
    }

    /**
     * Double declining balance: 2/life as the rate, applied to opening book value.
     */
    public function doubleDecliningBalance(float $cost, float $salvage, int $lifeYears, int $year): float
    {
        if ($lifeYears <= 0) {
            throw new \InvalidArgumentException('lifeYears must be > 0');
        }
        if ($salvage >= $cost) {
            return 0.0;
        }
        $rate = 2 / $lifeYears;
        return $this->reducingBalance($cost, $rate, $year, $salvage);
    }

    /**
     * Sum-of-years-digits.
     * Year 1 gets (life / SYD), year 2 gets ((life-1) / SYD), ...
     */
    public function sumOfYearsDigits(float $cost, float $salvage, int $lifeYears, int $year): float
    {
        if ($lifeYears <= 0) {
            throw new \InvalidArgumentException('lifeYears must be > 0');
        }
        if ($year < 1 || $year > $lifeYears) {
            throw new \InvalidArgumentException('year must be in 1..lifeYears');
        }
        if ($salvage >= $cost) {
            return 0.0;
        }

        $syd       = ($lifeYears * ($lifeYears + 1)) / 2;
        $remaining = $lifeYears - $year + 1;
        $factor    = $remaining / $syd;
        return round(($cost - $salvage) * $factor, 2);
    }

    /**
     * Units of production.
     * Returns depreciation for `unitsThisPeriod` given `totalEstimatedUnits`.
     */
    public function unitsOfProduction(float $cost, float $salvage, int $totalEstimatedUnits, int $unitsThisPeriod): float
    {
        if ($totalEstimatedUnits <= 0) {
            throw new \InvalidArgumentException('totalEstimatedUnits must be > 0');
        }
        if ($unitsThisPeriod < 0) {
            throw new \InvalidArgumentException('unitsThisPeriod must be >= 0');
        }
        if ($salvage >= $cost) {
            return 0.0;
        }

        $perUnit = ($cost - $salvage) / $totalEstimatedUnits;
        return round($perUnit * $unitsThisPeriod, 2);
    }

    /**
     * Approval level resolution from configured threshold settings.
     *
     * Settings keys (floats):
     *   approval_threshold_l1 — amounts < this require 1 approval
     *   approval_threshold_l2 — amounts < this require 2 approvals
     *   anything >= l2 requires 3 approvals
     */
    public function resolveApprovalLevel(float $amount, float $l1Threshold, float $l2Threshold): int
    {
        if ($amount < $l1Threshold) {
            return 1;
        }
        if ($amount < $l2Threshold) {
            return 2;
        }
        return 3;
    }
}
