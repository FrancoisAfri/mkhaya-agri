<?php

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Enums\DepreciationMethod;
use App\Modules\Assets\Models\AssetDepreciationEntry;
use App\Modules\Assets\Models\AssetDepreciationSchedule;
use App\Modules\Assets\Models\Assets;
use Illuminate\Support\Facades\DB;

class GenerateDepreciationScheduleAction
{
    public function execute(Assets $asset, array $data, int $generatedBy): AssetDepreciationSchedule
    {
        return DB::transaction(function () use ($asset, $data, $generatedBy) {
            // Deactivate existing active schedule
            AssetDepreciationSchedule::where('asset_id', $asset->id)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            $method      = DepreciationMethod::from($data['depreciation_method']);
            $usefulYears = (int) $data['useful_life_years'];
            $totalPeriods = $usefulYears * 12;
            $cost        = (float) $data['purchase_cost'];
            $salvage     = (float) ($data['salvage_value'] ?? 0);
            $wearRate    = isset($data['sars_wear_rate']) ? (float) $data['sars_wear_rate'] : null;

            $schedule = AssetDepreciationSchedule::create([
                'asset_id'            => $asset->id,
                'depreciation_method' => $method->value,
                'useful_life_years'   => $usefulYears,
                'sars_wear_rate'      => $wearRate,
                'purchase_cost'       => $cost,
                'salvage_value'       => $salvage,
                'total_periods'       => $totalPeriods,
                'generated_by'        => $generatedBy,
                'generated_at'        => now(),
                'is_active'           => true,
            ]);

            $this->generateEntries($schedule, $method, $cost, $salvage, $wearRate, $totalPeriods);

            return $schedule->load('entries');
        });
    }

    private function generateEntries(AssetDepreciationSchedule $schedule, DepreciationMethod $method, float $cost, float $salvage, ?float $wearRate, int $totalPeriods): void
    {
        $entries = [];
        $openingValue = $cost;

        $startDate = now()->startOfMonth();

        // Straight-line constant
        $slMonthly = $totalPeriods > 0 ? ($cost - $salvage) / $totalPeriods : 0;

        // Reducing balance: derive the MONTHLY rate directly so monthly compounding
        // lands exactly on salvage at end of life. Deriving an ANNUAL rate and
        // dividing by 12 undershoots, which dumped the residual into a large
        // final-month balloon. Geometric decline can never reach a zero/absent
        // salvage, so fall back to straight-line for that degenerate case (a rate
        // of 1 would otherwise write the whole asset off in month one).
        $monthlyRate = 0.0;
        $reducingAsStraightLine = false;
        if ($method === DepreciationMethod::ReducingBalance) {
            if ($cost > 0 && $salvage > 0 && $salvage < $cost && $totalPeriods > 0) {
                $monthlyRate = 1 - pow($salvage / $cost, 1 / $totalPeriods);
            } else {
                $reducingAsStraightLine = true;
            }
        }

        // SARS monthly
        $sarsMonthly = $wearRate ? $cost * $wearRate / 12 : 0;

        for ($i = 1; $i <= $totalPeriods; $i++) {
            $date = $startDate->copy()->addMonths($i - 1);

            $depAmount = match ($method) {
                DepreciationMethod::StraightLine    => $slMonthly,
                DepreciationMethod::ReducingBalance => $reducingAsStraightLine ? $slMonthly : $openingValue * $monthlyRate,
                DepreciationMethod::SarsWearAndTear => $sarsMonthly,
                DepreciationMethod::UnitsOfProduction => 0, // requires usage data
            };

            $closing = max($salvage, round($openingValue - $depAmount, 2));

            // Force closing to salvage on the last period to absorb rounding — but
            // not for units-of-production, which has no time-based schedule and
            // would otherwise dump the entire base into the final month as a cliff.
            if ($i === $totalPeriods && $method !== DepreciationMethod::UnitsOfProduction) {
                $closing   = $salvage;
                $depAmount = $openingValue - $salvage;
            }

            $entries[] = [
                'schedule_id'         => $schedule->id,
                'asset_id'            => $schedule->asset_id,
                'period_year'         => (int) $date->format('Y'),
                'period_month'        => (int) $date->format('n'),
                'period_sequence'     => $i,
                'opening_book_value'  => round($openingValue, 2),
                'depreciation_amount' => round($depAmount, 2),
                'closing_book_value'  => $closing,
                'is_posted'           => false,
                'created_at'          => now(),
                'updated_at'          => now(),
            ];

            $openingValue = $closing;
        }

        foreach (array_chunk($entries, 200) as $chunk) {
            AssetDepreciationEntry::insert($chunk);
        }
    }
}
