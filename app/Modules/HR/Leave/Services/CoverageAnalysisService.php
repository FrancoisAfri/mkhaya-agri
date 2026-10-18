<?php

namespace App\Modules\HR\Leave\Services;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

class CoverageAnalysisService
{
    /**
     * Returns per-day, per-division coverage % for a date range.
     * Coverage % = (total_in_division - on_leave) / total_in_division * 100
     */
    public function getDailyCoverage(string $startDate, string $endDate, ?int $divisionId = null, ?array $employeeIds = null, bool $departmentsOnly = false): array
    {
        $workdays = $this->workdays($startDate, $endDate);

        if (empty($workdays)) {
            return ['dates' => [], 'divisions' => [], 'overall_by_date' => []];
        }

        $divisions = $this->getDivisions($divisionId, $employeeIds, $departmentsOnly);

        if (empty($divisions)) {
            return ['dates' => $workdays, 'divisions' => [], 'overall_by_date' => []];
        }

        // Gather all leave overlapping the range once.
        // Use COALESCE to fall back to the pivot-table unit when division_level_1 is null.
        $leaveQuery = DB::table('leave_requests as lr')
            ->join('hr_people as hp', 'hp.id', '=', 'lr.employee_id')
            ->leftJoin(
                DB::raw('(SELECT user_id, MIN(company_id) as company_id FROM company_unit_user_pivots GROUP BY user_id) as cuup'),
                'cuup.user_id', '=', 'hp.user_id'
            )
            ->whereIn('lr.status', ['approved'])
            ->where('lr.start_date', '<=', $endDate)
            ->where('lr.end_date', '>=', $startDate)
            ->selectRaw('COALESCE(hp.division_level_1, cuup.company_id) as div_id, lr.start_date, lr.end_date');

        if ($divisionId) {
            $leaveQuery->where(DB::raw('COALESCE(hp.division_level_1, cuup.company_id)'), $divisionId);
        }

        if ($employeeIds !== null) {
            $leaveQuery->whereIn('hp.id', $employeeIds);
        }

        $leaveRows = $leaveQuery->get();

        // Build a lookup: div_id → [date => on_leave_count]
        $onLeaveByDivDate = [];
        foreach ($leaveRows as $row) {
            $period = CarbonPeriod::create($row->start_date, $row->end_date);
            foreach ($period as $d) {
                if ($d->isWeekend()) {
                    continue;
                }
                $ds = $d->format('Y-m-d');
                $onLeaveByDivDate[$row->div_id][$ds] = ($onLeaveByDivDate[$row->div_id][$ds] ?? 0) + 1;
            }
        }

        // Build per-division coverage
        $result = [];
        $overallByDate = [];

        foreach ($divisions as $div) {
            $coverageByDate = [];
            foreach ($workdays as $date) {
                $onLeave  = $onLeaveByDivDate[$div['id']][$date] ?? 0;
                $total    = $div['headcount'];
                $coverage = $total > 0 ? max(0, round(($total - $onLeave) / $total * 100)) : 100;

                $coverageByDate[$date] = [
                    'coverage_pct' => $coverage,
                    'on_leave'     => $onLeave,
                    'total'        => $total,
                    'risk'         => $this->riskLevel($coverage),
                ];

                $overallByDate[$date]['on_leave']   = ($overallByDate[$date]['on_leave'] ?? 0) + $onLeave;
                $overallByDate[$date]['total']       = ($overallByDate[$date]['total']    ?? 0) + $total;
            }
            $result[] = array_merge($div, ['by_date' => $coverageByDate]);
        }

        // Compute overall coverage per day
        $overall = [];
        foreach ($workdays as $date) {
            $tot = $overallByDate[$date]['total']    ?? 0;
            $off = $overallByDate[$date]['on_leave'] ?? 0;
            $pct = $tot > 0 ? max(0, round(($tot - $off) / $tot * 100)) : 100;
            $overall[$date] = ['coverage_pct' => $pct, 'on_leave' => $off, 'total' => $tot, 'risk' => $this->riskLevel($pct)];
        }

        return [
            'dates'           => $workdays,
            'divisions'       => $result,
            'overall_by_date' => $overall,
        ];
    }

    /**
     * Returns dates where ≥ 2 employees in the same division are simultaneously absent.
     */
    public function getClashAlerts(string $startDate, string $endDate, ?array $employeeIds = null): array
    {
        $rows = DB::table('leave_requests as lr')
            ->join('hr_people as hp', 'hp.id', '=', 'lr.employee_id')
            ->join('leave_types as lt', 'lt.id', '=', 'lr.leave_type_id')
            ->leftJoin(
                DB::raw('(SELECT user_id, MIN(company_id) as company_id FROM company_unit_user_pivots GROUP BY user_id) as cuup'),
                'cuup.user_id', '=', 'hp.user_id'
            )
            ->whereIn('lr.status', ['approved', 'pending_manager', 'pending_hr'])
            ->where('lr.start_date', '<=', $endDate)
            ->where('lr.end_date', '>=', $startDate)
            ->when($employeeIds !== null, fn($q) => $q->whereIn('hp.id', $employeeIds))
            ->select(
                DB::raw('COALESCE(hp.division_level_1, cuup.company_id) as division_id'),
                'lr.start_date',
                'lr.end_date',
                DB::raw("CONCAT(hp.first_name, ' ', hp.surname) as employee_name"),
                'lt.name as leave_type',
                'lr.status',
            )
            ->get();

        // Group by effective unit (division_level_1 or pivot unit)
        $byDivision = [];
        foreach ($rows as $row) {
            $byDivision[$row->division_id ?? 0][] = $row;
        }

        $alerts = [];
        foreach ($byDivision as $divId => $requests) {
            if (count($requests) < 2) {
                continue;
            }
            $divName    = $this->divisionName($divId);
            $headcount  = $this->divisionHeadcount($divId);
            $concurrent = count($requests);
            $coveragePct = $headcount > 0 ? max(0, round(($headcount - $concurrent) / $headcount * 100)) : 0;

            $alerts[] = [
                'division_id'   => $divId,
                'division_name' => $divName,
                'concurrent'    => $concurrent,
                'headcount'     => $headcount,
                'coverage_pct'  => $coveragePct,
                'risk'          => $this->riskLevel($coveragePct),
                'employees'     => collect($requests)->map(fn($r) => [
                    'name'       => $r->employee_name,
                    'leave_type' => $r->leave_type,
                    'start_date' => $r->start_date,
                    'end_date'   => $r->end_date,
                    'status'     => $r->status,
                ])->values()->toArray(),
                'period' => [
                    'start' => collect($requests)->min('start_date'),
                    'end'   => collect($requests)->max('end_date'),
                ],
            ];
        }

        usort($alerts, fn($a, $b) => $a['coverage_pct'] <=> $b['coverage_pct']);

        return $alerts;
    }

    /**
     * Critical role warnings: managers, senior staff on leave in the given range.
     * hp.position is an integer FK; the name comes from hr_positions via position_id.
     */
    public function getCriticalRoleWarnings(string $startDate, string $endDate, ?array $employeeIds = null): array
    {
        return DB::table('leave_requests as lr')
            ->join('hr_people as hp', 'hp.id', '=', 'lr.employee_id')
            ->join('leave_types as lt', 'lt.id', '=', 'lr.leave_type_id')
            ->leftJoin('hr_positions as hrpos', 'hrpos.id', '=', 'hp.position_id')
            ->leftJoin(
                DB::raw('(SELECT user_id, MIN(company_id) as company_id FROM company_unit_user_pivots GROUP BY user_id) as cuup'),
                'cuup.user_id', '=', 'hp.user_id'
            )
            ->when($employeeIds !== null, fn($q) => $q->whereIn('hp.id', $employeeIds))
            ->where(function ($q) {
                $q->whereRaw("LOWER(hrpos.name) LIKE '%manager%'")
                  ->orWhereRaw("LOWER(hrpos.name) LIKE '%director%'")
                  ->orWhereRaw("LOWER(hrpos.name) LIKE '%head%'")
                  ->orWhereRaw("LOWER(hrpos.name) LIKE '%lead%'")
                  ->orWhereRaw("LOWER(hrpos.name) LIKE '%chief%'");
            })
            ->whereIn('lr.status', ['approved', 'pending_manager', 'pending_hr'])
            ->where('lr.start_date', '<=', $endDate)
            ->where('lr.end_date', '>=', $startDate)
            ->select(
                'lr.id',
                DB::raw("CONCAT(hp.first_name, ' ', hp.surname) as employee_name"),
                DB::raw('hrpos.name as position'),
                'hp.division_level_1 as division_id',
                DB::raw('cuup.company_id as pivot_unit_id'),
                'lt.name as leave_type',
                'lr.start_date',
                'lr.end_date',
                'lr.working_days',
                'lr.status',
            )
            ->orderBy('lr.start_date')
            ->get()
            ->map(fn($r) => [
                'id'            => $r->id,
                'employee_name' => $r->employee_name,
                'position'      => $r->position ?? 'Unknown',
                'division_name' => $this->divisionName($r->division_id ?? $r->pivot_unit_id),
                'leave_type'    => $r->leave_type,
                'start_date'    => $r->start_date,
                'end_date'      => $r->end_date,
                'working_days'  => $r->working_days,
                'status'        => $r->status,
                'severity'      => $this->criticalSeverity($r->position),
            ])
            ->toArray();
    }

    /**
     * Suggest low-impact dates near a requested period (for AI alternative date recommendations).
     * Returns up to 3 alternative windows ranked by highest team coverage.
     */
    public function suggestAlternativeDates(int $employeeId, string $preferredStart, string $preferredEnd, int $workingDays): array
    {
        $divId       = (int) DB::table('hr_people')->where('id', $employeeId)->value('division_level_1');
        $duration    = max(1, $workingDays);
        $searchStart = Carbon::parse($preferredStart)->subDays(30)->format('Y-m-d');
        $searchEnd   = Carbon::parse($preferredEnd)->addDays(60)->format('Y-m-d');
        $coverage    = $this->getDailyCoverage($searchStart, $searchEnd, $divId ?: null);

        $overallByDate = $coverage['overall_by_date'] ?? [];
        $dates         = array_keys($overallByDate);

        // No coverage data at all — fall back to nearest workday windows after the preferred period
        if (empty($dates)) {
            return $this->fallbackSuggestions($preferredEnd, $duration);
        }

        $candidates = [];

        for ($i = 0; $i <= count($dates) - $duration; $i++) {
            $window      = array_slice($dates, $i, $duration);
            $windowStart = $window[0];
            $windowEnd   = end($window);

            // Skip any window that overlaps with the preferred period
            if ($windowStart <= $preferredEnd && $windowEnd >= $preferredStart) {
                continue;
            }

            $avgCoverage = collect($window)
                ->avg(fn($d) => $overallByDate[$d]['coverage_pct'] ?? 100);

            $candidates[] = [
                'start_date'   => $windowStart,
                'end_date'     => $windowEnd,
                'avg_coverage' => round($avgCoverage),
                'working_days' => $duration,
            ];
        }

        // Return the 3 windows with highest team coverage
        usort($candidates, fn($a, $b) => $b['avg_coverage'] <=> $a['avg_coverage']);

        return array_slice($candidates, 0, 3);
    }

    /**
     * Fallback when there is no coverage data: return the 3 nearest working-day windows
     * immediately following the preferred period, marked at 100% (unknown) coverage.
     */
    private function fallbackSuggestions(string $preferredEnd, int $duration): array
    {
        $start = Carbon::parse($preferredEnd)->addDay()->format('Y-m-d');
        $end   = Carbon::parse($preferredEnd)->addDays(90)->format('Y-m-d');
        $days  = $this->workdays($start, $end);

        $suggestions = [];
        $i = 0;
        while (count($suggestions) < 3 && $i <= count($days) - $duration) {
            $window      = array_slice($days, $i, $duration);
            $suggestions[] = [
                'start_date'   => $window[0],
                'end_date'     => end($window),
                'avg_coverage' => 100,
                'working_days' => $duration,
            ];
            $i += $duration;
        }

        return $suggestions;
    }

    public function getDivisions(?int $filterDivId = null, ?array $employeeIds = null, bool $departmentsOnly = false): array
    {
        // Join employees whose division_level_1 points to this unit OR (when division_level_1
        // is null) who are assigned to this unit via company_unit_user_pivots.
        $query = DB::table('company_units as cu')
            ->where('cu.active', true)
            ->when($departmentsOnly, fn($q) => $q->where('cu.type', 'department'))
            ->join('hr_people as hp', function ($join) {
                $join->whereRaw(
                    'hp.division_level_1 = cu.id OR (hp.division_level_1 IS NULL AND EXISTS (SELECT 1 FROM company_unit_user_pivots cuup WHERE cuup.user_id = hp.user_id AND cuup.company_id = cu.id))'
                );
            })
            ->where('hp.status', 1)
            ->selectRaw('cu.id, cu.name, COUNT(DISTINCT hp.id) as headcount')
            ->groupBy('cu.id', 'cu.name')
            ->orderBy('cu.name');

        if ($filterDivId) {
            $query->where('cu.id', $filterDivId);
        }

        if ($employeeIds !== null) {
            $query->whereIn('hp.id', $employeeIds);
        }

        $rows = $query->get();

        // Fallback: if no employees are mapped to company_units, group by raw division_level_1 value
        if ($rows->isEmpty()) {
            $fallback = DB::table('hr_people')
                ->whereNotNull('division_level_1')
                ->where('status', 1);

            if ($employeeIds !== null) {
                $fallback->whereIn('id', $employeeIds);
            }

            $rows = $fallback
                ->selectRaw('division_level_1 as id, CAST(division_level_1 AS TEXT) as name, count(*) as headcount')
                ->groupBy('division_level_1')
                ->orderBy('division_level_1')
                ->get();
        }

        return $rows->map(fn($r) => [
            'id'        => $r->id,
            'name'      => $r->name,
            'headcount' => (int) $r->headcount,
        ])->toArray();
    }

    private function divisionName(int|string|null $divId): string
    {
        if (! $divId) {
            return 'Unassigned';
        }
        return DB::table('company_units')->where('id', (int) $divId)->value('name') ?? "Division {$divId}";
    }

    private function divisionHeadcount(int|string|null $divId): int
    {
        if (! $divId) {
            return 0;
        }
        return (int) DB::table('hr_people')->where('division_level_1', (int) $divId)->count();
    }

    private function workdays(string $startDate, string $endDate): array
    {
        $days    = [];
        $period  = CarbonPeriod::create($startDate, $endDate);
        foreach ($period as $date) {
            if (! $date->isWeekend()) {
                $days[] = $date->format('Y-m-d');
            }
        }
        return $days;
    }

    private function riskLevel(int $coveragePct): string
    {
        return match(true) {
            $coveragePct >= 80 => 'low',
            $coveragePct >= 65 => 'medium',
            $coveragePct >= 50 => 'high',
            default            => 'critical',
        };
    }

    private function criticalSeverity(?string $position): string
    {
        $p = strtolower($position ?? '');
        if (str_contains($p, 'chief') || str_contains($p, 'director') || str_contains($p, 'head')) {
            return 'critical';
        }
        if (str_contains($p, 'manager') || str_contains($p, 'lead')) {
            return 'high';
        }
        return 'medium';
    }
}
