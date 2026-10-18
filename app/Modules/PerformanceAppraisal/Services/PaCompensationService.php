<?php

namespace App\Modules\PerformanceAppraisal\Services;

use App\Modules\PerformanceAppraisal\Models\PaCompRecommendation;
use App\Modules\PerformanceAppraisal\Models\PaCompReview;
use App\Modules\PerformanceAppraisal\Models\PaCycle;
use App\Modules\PerformanceAppraisal\Models\PaPerformanceSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * Turns calibrated performance scores into merit-increase and bonus
 * recommendations, pulling current salary from hr_people.monthly_package.
 * This is the bridge between Performance and Payroll.
 */
class PaCompensationService
{
    /**
     * Default merit matrix: normalised score (0..1) → [merit %, bonus %].
     * Acts as a guideline the comp manager can override per employee.
     */
    public const MATRIX = [
        ['min' => 0.90, 'band' => 'Outstanding',     'merit' => 8.0, 'bonus' => 15.0],
        ['min' => 0.75, 'band' => 'Exceeds',         'merit' => 6.0, 'bonus' => 10.0],
        ['min' => 0.55, 'band' => 'Meets',           'merit' => 4.0, 'bonus' => 5.0],
        ['min' => 0.40, 'band' => 'Partially Meets', 'merit' => 2.0, 'bonus' => 0.0],
        ['min' => 0.0,  'band' => 'Below',           'merit' => 0.0, 'bonus' => 0.0],
    ];

    public function bandFor(float $normalised): array
    {
        foreach (self::MATRIX as $row) {
            if ($normalised >= $row['min']) {
                return $row;
            }
        }
        return end(self::MATRIX);
    }

    /** Generate per-employee recommendations for a comp review from snapshots. */
    public function generate(PaCompReview $review): int
    {
        $cycle = PaCycle::find($review->cycle_id);
        $max   = (float) ($cycle?->ratingScale?->max_score ?? 5);

        $snapshots = PaPerformanceSnapshot::where('cycle_id', $review->cycle_id)->get();

        // Current salary lookup by user → hr_people.monthly_package.
        $salaries = DB::table('hr_people')
            ->whereIn('user_id', $snapshots->pluck('employee_id'))
            ->pluck('monthly_package', 'user_id');

        $count = 0;
        foreach ($snapshots as $snap) {
            $score = (float) ($snap->final_score ?? $snap->overall_score ?? 0);
            $norm  = $max > 0 ? min(1, $score / $max) : 0;
            $band  = $this->bandFor($norm);
            $salary = (float) ($salaries[$snap->employee_id] ?? 0);

            $merit = $band['merit'];
            $bonus = $band['bonus'];

            PaCompRecommendation::updateOrCreate(
                ['comp_review_id' => $review->id, 'employee_id' => $snap->employee_id],
                [
                    'performance_score'   => round($score, 2),
                    'rating_band'         => $band['band'],
                    'current_salary'      => $salary ?: null,
                    'suggested_merit_pct' => $merit,
                    'final_merit_pct'     => $merit,
                    'suggested_bonus_pct' => $bonus,
                    'final_bonus_pct'     => $bonus,
                    'new_salary'          => $salary ? round($salary * (1 + $merit / 100), 2) : null,
                    'bonus_amount'        => $salary ? round($salary * ($bonus / 100), 2) : null,
                    'status'              => 'pending',
                ]
            );
            $count++;
        }

        return $count;
    }

    /** Recompute derived amounts after a manual edit of final %. */
    public function recalc(PaCompRecommendation $rec): void
    {
        $salary = (float) $rec->current_salary;
        $rec->new_salary   = $salary ? round($salary * (1 + (float) $rec->final_merit_pct / 100), 2) : null;
        $rec->bonus_amount = $salary ? round($salary * ((float) $rec->final_bonus_pct / 100), 2) : null;
        $rec->save();
    }

    /** Budget rollup for the review header. */
    public function totals(PaCompReview $review): array
    {
        $recs = $review->recommendations()->get();
        $payroll = $recs->sum('current_salary');

        return [
            'employees'      => $recs->count(),
            'total_payroll'  => round($payroll, 2),
            'total_merit'    => round($recs->sum(fn ($r) => ($r->current_salary ?? 0) * ($r->final_merit_pct ?? 0) / 100), 2),
            'total_bonus'    => round($recs->sum('bonus_amount'), 2),
            'merit_pct'      => $payroll > 0 ? round($recs->sum(fn ($r) => ($r->current_salary ?? 0) * ($r->final_merit_pct ?? 0) / 100) / $payroll * 100, 2) : 0,
        ];
    }
}
