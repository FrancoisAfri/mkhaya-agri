<?php

namespace App\Modules\Policies\Services;

use App\Core\CompanyUnits\Models\CompanyUnit;
use App\Modules\Policies\Models\Policy;
use App\Modules\Policies\Models\PolicySimulation;
use App\Modules\Policies\Models\PolicyViolation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PolicySimulationService
{
    /**
     * Run the simulation and persist results to the given PolicySimulation record.
     */
    public function run(PolicySimulation $simulation): void
    {
        $simulation->update(['status' => 'running']);

        try {
            $config      = $simulation->simulation_config;
            $policyId    = $simulation->policy_id;
            $dateFrom    = Carbon::parse($config['date_from'])->startOfDay();
            $dateTo      = Carbon::parse($config['date_to'])->endOfDay();
            $deptFilter  = $config['department_ids'] ?? [];

            // ----------------------------------------------------------------
            // Base query against historical violations for this policy/date range
            // ----------------------------------------------------------------
            $query = PolicyViolation::with(['rule:id,name', 'user:id,email', 'user.person:id,user_id,first_name,surname'])
                ->where('policy_id', $policyId)
                ->whereBetween('created_at', [$dateFrom, $dateTo]);

            if (! empty($deptFilter)) {
                $query->whereIn('department_id', $deptFilter);
            }

            $violations = $query->get();
            $total      = $violations->count();

            // ----------------------------------------------------------------
            // Severity breakdown
            // ----------------------------------------------------------------
            $bySeverity = $violations->groupBy('severity')
                ->map(fn($group) => $group->count())
                ->sortByDesc(fn($v) => $v)
                ->toArray();

            // ----------------------------------------------------------------
            // Outcome breakdown
            // ----------------------------------------------------------------
            $byOutcome = $violations->groupBy('outcome')
                ->map(fn($group) => $group->count())
                ->toArray();

            // ----------------------------------------------------------------
            // Impacted departments  (resolve names from company_units)
            // ----------------------------------------------------------------
            $deptIds   = $violations->pluck('department_id')->filter()->unique()->values();
            $deptNames = CompanyUnit::whereIn('id', $deptIds)
                ->pluck('name', 'id');

            $byDepartment = $violations
                ->filter(fn($v) => $v->department_id !== null)
                ->groupBy('department_id')
                ->map(function ($group, $deptId) use ($deptNames) {
                    $severities = $group->groupBy('severity')
                        ->map(fn($g) => $g->count())
                        ->toArray();

                    return [
                        'id'         => $deptId,
                        'name'       => $deptNames[$deptId] ?? 'Unknown',
                        'count'      => $group->count(),
                        'severities' => $severities,
                    ];
                })
                ->sortByDesc('count')
                ->values()
                ->toArray();

            // Violations with no department_id
            $unclassified = $violations->whereNull('department_id')->count();
            if ($unclassified > 0) {
                $byDepartment[] = [
                    'id'         => null,
                    'name'       => 'Unclassified',
                    'count'      => $unclassified,
                    'severities' => [],
                ];
            }

            // ----------------------------------------------------------------
            // Top rules triggered
            // ----------------------------------------------------------------
            $topRules = $violations->groupBy('rule_id')
                ->map(function ($group) {
                    $first = $group->first();
                    return [
                        'rule_id'  => $first->rule_id,
                        'name'     => optional($first->rule)->name ?? "Rule #{$first->rule_id}",
                        'count'    => $group->count(),
                        'severity' => $group->groupBy('severity')
                            ->map(fn($g) => $g->count())
                            ->toArray(),
                    ];
                })
                ->sortByDesc('count')
                ->values()
                ->take(10)
                ->toArray();

            // ----------------------------------------------------------------
            // Monthly trend (YYYY-MM bucketing)
            // ----------------------------------------------------------------
            $monthlyTrend = $violations
                ->groupBy(fn($v) => Carbon::parse($v->created_at)->format('Y-m'))
                ->map(fn($group, $month) => [
                    'month' => $month,
                    'count' => $group->count(),
                ])
                ->sortBy('month')
                ->values()
                ->toArray();

            // ----------------------------------------------------------------
            // Unique users affected
            // ----------------------------------------------------------------
            $uniqueUsers = $violations->pluck('user_id')->filter()->unique()->count();

            // ----------------------------------------------------------------
            // Status breakdown
            // ----------------------------------------------------------------
            $byStatus = $violations->groupBy('status')
                ->map(fn($group) => $group->count())
                ->toArray();

            // ----------------------------------------------------------------
            // Assemble results
            // ----------------------------------------------------------------
            $results = [
                'total_violations'  => $total,
                'unique_users'      => $uniqueUsers,
                'date_from'         => $dateFrom->toDateString(),
                'date_to'           => $dateTo->toDateString(),
                'by_severity'       => $bySeverity,
                'by_outcome'        => $byOutcome,
                'by_status'         => $byStatus,
                'by_department'     => $byDepartment,
                'top_rules'         => $topRules,
                'monthly_trend'     => $monthlyTrend,
            ];

            $simulation->update([
                'status'               => 'completed',
                'results'              => $results,
                'projected_violations' => $total,
                'impacted_departments' => $byDepartment,
                'completed_at'         => now(),
            ]);
        } catch (\Throwable $e) {
            $simulation->update([
                'status'  => 'failed',
                'results' => ['error' => $e->getMessage()],
            ]);

            throw $e;
        }
    }
}
