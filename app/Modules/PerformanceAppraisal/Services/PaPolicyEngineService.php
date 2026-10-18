<?php

namespace App\Modules\PerformanceAppraisal\Services;

use App\Modules\PerformanceAppraisal\Models\PaPolicy;
use App\Modules\PerformanceAppraisal\Models\PaPolicyViolation;
use App\Modules\PerformanceAppraisal\Models\PaPolicyException;
use App\Modules\PerformanceAppraisal\Models\PaComplianceScore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaPolicyEngineService
{
    /**
     * Detect and log all active-policy violations for a given cycle.
     * Returns array of newly created PaPolicyViolation records.
     */
    public function detectViolationsForCycle(int $cycleId): array
    {
        $violations = [];

        foreach (PaPolicy::where('is_active', true)->get() as $policy) {
            $new = match ($policy->condition_type) {
                'threshold_check'    => $this->detectThresholdViolations($policy, $cycleId),
                'days_limit'         => $this->detectDaysLimitViolations($policy, $cycleId),
                'evidence_required'  => $this->detectEvidenceViolations($policy, $cycleId),
                default              => [],
            };
            $violations = array_merge($violations, $new);
        }

        return $violations;
    }

    /**
     * Runtime policy check — returns structured results for a given action context.
     * Called programmatically before executing a sensitive action.
     *
     * @param  string $conditionType  e.g. 'threshold_check'
     * @param  array  $context        e.g. ['score' => 35]
     * @return array<int, array{policy_id:int, policy_name:string, passed:bool, enforcement_level:string, message:string}>
     */
    public function validatePerformancePolicy(string $conditionType, array $context): array
    {
        return PaPolicy::where('is_active', true)
            ->where('condition_type', $conditionType)
            ->get()
            ->map(fn(PaPolicy $p) => [
                'policy_id'         => $p->id,
                'policy_name'       => $p->name,
                'enforcement_level' => $p->enforcement_level,
                'passed'            => $this->evalPolicy($p, $context),
                'message'           => $p->description ?? $p->name,
            ])
            ->toArray();
    }

    public function computeComplianceScore(int $employeeId, ?int $cycleId = null): PaComplianceScore
    {
        $totalPolicies = PaPolicy::where('is_active', true)->count();

        $openViolations = PaPolicyViolation::where('employee_id', $employeeId)
            ->whereNotIn('status', ['resolved', 'exempted'])
            ->count();

        $exceptions = PaPolicyException::where('employee_id', $employeeId)
            ->where(fn($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now()->toDateString()))
            ->count();

        $totalChecks  = max($totalPolicies, 1);
        $passedChecks = max(0, $totalChecks - $openViolations);
        $score        = round(($passedChecks / $totalChecks) * 100, 2);

        return PaComplianceScore::updateOrCreate(
            ['employee_id' => $employeeId, 'cycle_id' => $cycleId],
            [
                'compliance_score' => $score,
                'total_checks'     => $totalChecks,
                'passed_checks'    => $passedChecks,
                'violation_count'  => $openViolations,
                'exception_count'  => $exceptions,
                'computed_at'      => now(),
            ]
        );
    }

    // ── Detection helpers ─────────────────────────────────────────────────────

    private function detectThresholdViolations(PaPolicy $policy, int $cycleId): array
    {
        $threshold = (float) ($policy->condition_config['threshold'] ?? 40);

        // Employees whose avg KPI score is below threshold this cycle
        $lowPerformers = DB::table('pa_kpi_scores')
            ->where('cycle_id', $cycleId)
            ->groupBy('employee_id')
            ->havingRaw('AVG(score_pct) < ?', [$threshold])
            ->selectRaw('employee_id, ROUND(AVG(score_pct), 1) as avg_score')
            ->get();

        $created = [];
        foreach ($lowPerformers as $row) {
            $employeeId = (int) $row->employee_id;

            if ($this->hasActiveException($policy->id, $employeeId)) {
                continue;
            }

            $alreadyOpen = PaPolicyViolation::where('policy_id', $policy->id)
                ->where('employee_id', $employeeId)
                ->whereNotIn('status', ['resolved', 'exempted'])
                ->exists();

            if ($alreadyOpen) {
                continue;
            }

            // Violation only if no active development plan (PIP)
            $hasPip = DB::table('pa_development_plans')
                ->where('employee_id', $employeeId)
                ->whereIn('status', ['active', 'draft'])
                ->exists();

            if (!$hasPip) {
                $created[] = PaPolicyViolation::create([
                    'policy_id'      => $policy->id,
                    'employee_id'    => $employeeId,
                    'action_type'    => 'low_performance_no_pip',
                    'resource_type'  => 'PaKpiScore',
                    'violation_data' => [
                        'avg_score' => (float) $row->avg_score,
                        'threshold' => $threshold,
                        'cycle_id'  => $cycleId,
                    ],
                    'status' => 'detected',
                ]);
            }
        }

        return $created;
    }

    private function detectDaysLimitViolations(PaPolicy $policy, int $cycleId): array
    {
        $maxDays  = (int) ($policy->condition_config['max_days'] ?? 90);
        $cutoff   = now()->subDays($maxDays)->toDateString();

        $overdue = DB::table('pa_probation_reviews')
            ->where('cycle_id', $cycleId)
            ->whereNull('outcome')
            ->where('start_date', '<=', $cutoff)
            ->select('id', 'employee_id', 'start_date')
            ->get();

        $created = [];
        foreach ($overdue as $review) {
            $employeeId = (int) $review->employee_id;

            if ($this->hasActiveException($policy->id, $employeeId)) {
                continue;
            }

            $alreadyOpen = PaPolicyViolation::where('policy_id', $policy->id)
                ->where('employee_id', $employeeId)
                ->where('resource_id', $review->id)
                ->whereNotIn('status', ['resolved', 'exempted'])
                ->exists();

            if (!$alreadyOpen) {
                $daysOpen = (int) now()->diffInDays($review->start_date);
                $created[] = PaPolicyViolation::create([
                    'policy_id'      => $policy->id,
                    'employee_id'    => $employeeId,
                    'action_type'    => 'probation_overdue',
                    'resource_type'  => 'PaProbationReview',
                    'resource_id'    => $review->id,
                    'violation_data' => [
                        'start_date' => $review->start_date,
                        'days_open'  => $daysOpen,
                        'max_days'   => $maxDays,
                    ],
                    'status' => 'detected',
                ]);
            }
        }

        return $created;
    }

    private function detectEvidenceViolations(PaPolicy $policy, int $cycleId): array
    {
        $minEvidence = (int) ($policy->condition_config['min_evidence'] ?? 1);

        $kpisNoEvidence = DB::table('pa_employee_kpis as k')
            ->leftJoin('pa_kpi_evidences as e', 'e.kpi_id', '=', 'k.id')
            ->where('k.cycle_id', $cycleId)
            ->groupBy('k.id', 'k.employee_id', 'k.title')
            ->havingRaw('COUNT(e.id) < ?', [$minEvidence])
            ->select('k.id', 'k.employee_id', 'k.title')
            ->get();

        $created = [];
        foreach ($kpisNoEvidence as $kpi) {
            $employeeId = (int) $kpi->employee_id;

            if ($this->hasActiveException($policy->id, $employeeId)) {
                continue;
            }

            $alreadyOpen = PaPolicyViolation::where('policy_id', $policy->id)
                ->where('employee_id', $employeeId)
                ->where('resource_id', $kpi->id)
                ->whereNotIn('status', ['resolved', 'exempted'])
                ->exists();

            if (!$alreadyOpen) {
                $created[] = PaPolicyViolation::create([
                    'policy_id'      => $policy->id,
                    'employee_id'    => $employeeId,
                    'action_type'    => 'kpi_no_evidence',
                    'resource_type'  => 'PaEmployeeKpi',
                    'resource_id'    => $kpi->id,
                    'violation_data' => [
                        'kpi_title'    => $kpi->title,
                        'min_required' => $minEvidence,
                    ],
                    'status' => 'detected',
                ]);
            }
        }

        return $created;
    }

    // ── Runtime evaluator ─────────────────────────────────────────────────────

    private function evalPolicy(PaPolicy $policy, array $context): bool
    {
        $config = $policy->condition_config ?? [];

        return match ($policy->condition_type) {
            'threshold_check'    => ($context['score'] ?? 100) >= ($config['threshold'] ?? 40),
            'days_limit'         => ($context['days']  ?? 0)   <= ($config['max_days']  ?? 90),
            'evidence_required'  => ($context['evidence_count'] ?? 0) >= ($config['min_evidence'] ?? 1),
            'sign_off_lock'      => !in_array($context['status'] ?? '', $config['locked_statuses'] ?? ['signed_off']),
            default              => true,
        };
    }

    // ── Exception guard ───────────────────────────────────────────────────────

    private function hasActiveException(int $policyId, int $employeeId): bool
    {
        return PaPolicyException::where('policy_id', $policyId)
            ->where('employee_id', $employeeId)
            ->where(fn($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now()->toDateString()))
            ->exists();
    }
}
