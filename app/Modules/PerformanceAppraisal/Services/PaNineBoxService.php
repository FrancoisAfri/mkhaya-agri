<?php

namespace App\Modules\PerformanceAppraisal\Services;

use App\Modules\PerformanceAppraisal\Models\PaCycle;
use App\Modules\PerformanceAppraisal\Models\PaNineBoxPlacement;
use App\Modules\PerformanceAppraisal\Models\PaPerformanceSnapshot;
use App\Modules\PerformanceAppraisal\Models\PaTalentProfile;
use Illuminate\Support\Facades\Auth;

/**
 * Builds & persists 9-box talent grid placements for a cycle.
 *
 * Performance (x-axis) is derived from the appraisal snapshot final/overall score.
 * Potential  (y-axis) is derived from the talent profile (leadership + promotion
 * readiness). Manual drag-drop overrides are preserved (is_manual = true).
 */
class PaNineBoxService
{
    /** The 9 box definitions, keyed by box number 1..9. */
    public const BOXES = [
        7 => ['label' => 'Rough Diamond',      'perf' => 'low',    'pot' => 'high',   'color' => '#f59e0b'],
        8 => ['label' => 'Future Star',        'perf' => 'medium', 'pot' => 'high',   'color' => '#3b82f6'],
        9 => ['label' => 'Consistent Star',    'perf' => 'high',   'pot' => 'high',   'color' => '#10b981'],
        4 => ['label' => 'Inconsistent Player','perf' => 'low',    'pot' => 'medium', 'color' => '#ef4444'],
        5 => ['label' => 'Core Player',        'perf' => 'medium', 'pot' => 'medium', 'color' => '#6366f1'],
        6 => ['label' => 'High Performer',     'perf' => 'high',   'pot' => 'medium', 'color' => '#14b8a6'],
        1 => ['label' => 'Risk',               'perf' => 'low',    'pot' => 'low',    'color' => '#b91c1c'],
        2 => ['label' => 'Average Performer',  'perf' => 'medium', 'pot' => 'low',    'color' => '#9ca3af'],
        3 => ['label' => 'Solid Performer',    'perf' => 'high',   'pot' => 'low',    'color' => '#22c55e'],
    ];

    /** Regenerate auto placements for every employee with a snapshot in the cycle. */
    public function rebuild(int $cycleId): int
    {
        $cycle = PaCycle::find($cycleId);
        $max = (float) ($cycle?->ratingScale?->max_score ?? 5);

        $snapshots = PaPerformanceSnapshot::where('cycle_id', $cycleId)->get();
        $profiles  = PaTalentProfile::where('cycle_id', $cycleId)->get()->keyBy('employee_id');

        $count = 0;
        foreach ($snapshots as $snap) {
            $existing = PaNineBoxPlacement::where('cycle_id', $cycleId)
                ->where('employee_id', $snap->employee_id)->first();

            // Never clobber a manual placement.
            if ($existing && $existing->is_manual) {
                continue;
            }

            $perfScore = (float) ($snap->final_score ?? $snap->overall_score ?? 0);
            $profile   = $profiles->get($snap->employee_id);
            $potScore  = (float) ($profile->promotion_readiness_score ?? $profile->leadership_score ?? 0);
            // talent scores are 0..100; normalise potential band on 100 scale.
            $perfBand  = PaNineBoxPlacement::bandFor($perfScore, $max);
            $potBand   = PaNineBoxPlacement::bandFor($potScore, 100);
            $box       = PaNineBoxPlacement::boxFor($perfBand, $potBand);

            PaNineBoxPlacement::updateOrCreate(
                ['cycle_id' => $cycleId, 'employee_id' => $snap->employee_id],
                [
                    'performance_band'  => $perfBand,
                    'potential_band'    => $potBand,
                    'box'               => $box,
                    'performance_score' => round($perfScore, 2),
                    'potential_score'   => round($potScore, 2),
                    'is_manual'         => false,
                ]
            );
            $count++;
        }

        return $count;
    }

    /** Manually move an employee to a specific box (drag-drop in calibration). */
    public function move(int $cycleId, int $employeeId, int $box, ?string $notes = null): PaNineBoxPlacement
    {
        $def = self::BOXES[$box] ?? self::BOXES[5];

        return PaNineBoxPlacement::updateOrCreate(
            ['cycle_id' => $cycleId, 'employee_id' => $employeeId],
            [
                'performance_band' => $def['perf'],
                'potential_band'   => $def['pot'],
                'box'              => $box,
                'is_manual'        => true,
                'notes'            => $notes,
                'placed_by'        => Auth::id(),
            ]
        );
    }
}
