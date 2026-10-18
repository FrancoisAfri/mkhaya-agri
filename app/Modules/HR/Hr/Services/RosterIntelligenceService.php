<?php

namespace App\Modules\HR\Hr\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Modules\HR\Hr\Models\Attendance\AttendanceRecord;
use App\Modules\HR\Hr\Models\Attendance\AttendanceShift;
use App\Modules\HR\Hr\Models\Attendance\RosterAssignment;
use App\Modules\HR\Hr\Models\Attendance\RosterOptimizationLog;
use App\Modules\HR\Hr\Models\Attendance\RosterSchedule;
use App\Shared\DTOs\AgentRequest;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

class RosterIntelligenceService
{
    private const MIN_REST_DAYS_PER_WEEK = 1;
    private const MAX_CONSECUTIVE_DAYS   = 6;

    public function __construct(
        private readonly AgentRegistry $agentRegistry
    ) {}

    // ── Schedule CRUD ─────────────────────────────────────────────────────────

    public function createSchedule(array $data, int $userId): RosterSchedule
    {
        return RosterSchedule::create([
            'name'         => $data['name'],
            'department'   => $data['department'] ?? null,
            'period_start' => $data['period_start'],
            'period_end'   => $data['period_end'],
            'status'       => 'draft',
            'generated_by' => 'manual',
            'created_by'   => $userId,
        ]);
    }

    public function publishSchedule(RosterSchedule $schedule): RosterSchedule
    {
        $schedule->update(['status' => 'published', 'published_at' => now()]);
        return $schedule;
    }

    // ── AI Roster Generation ──────────────────────────────────────────────────

    public function generateAiRoster(RosterSchedule $schedule): RosterSchedule
    {
        $employees = $this->getEligibleEmployees($schedule->department);
        $shifts    = AttendanceShift::where('active', true)->get();
        $period    = CarbonPeriod::create($schedule->period_start, $schedule->period_end);

        // Clear existing assignments
        $schedule->assignments()->delete();

        $assignments = [];
        $empWorkCount = [];

        foreach ($employees as $emp) {
            $empWorkCount[$emp->id] = 0;
        }

        foreach ($period as $date) {
            if ($date->isWeekend()) continue;

            foreach ($employees as $emp) {
                // Ensure rest day every 6 working days
                if (($empWorkCount[$emp->id] ?? 0) >= self::MAX_CONSECUTIVE_DAYS) {
                    RosterAssignment::create([
                        'roster_schedule_id' => $schedule->id,
                        'employee_id'        => $emp->id,
                        'assigned_date'      => $date->toDateString(),
                        'is_rest_day'        => true,
                        'status'             => 'scheduled',
                        'is_ai_suggested'    => true,
                    ]);
                    $empWorkCount[$emp->id] = 0;
                    continue;
                }

                // Rotate shifts fairly
                $shiftIndex   = $empWorkCount[$emp->id] % max(1, $shifts->count());
                $selectedShift = $shifts->get($shiftIndex);

                RosterAssignment::create([
                    'roster_schedule_id' => $schedule->id,
                    'employee_id'        => $emp->id,
                    'shift_id'           => $selectedShift?->id,
                    'assigned_date'      => $date->toDateString(),
                    'status'             => 'scheduled',
                    'is_ai_suggested'    => true,
                ]);
                $empWorkCount[$emp->id]++;
                $assignments[] = ['emp' => $emp->id, 'date' => $date->toDateString()];
            }
        }

        $scores = $this->scoreSchedule($schedule);
        $schedule->update([
            'generated_by'    => 'ai',
            'coverage_score'  => $scores['coverage'],
            'balance_score'   => $scores['balance'],
        ]);

        return $schedule->fresh();
    }

    // ── Optimisation ──────────────────────────────────────────────────────────

    public function optimizeStaffingLevels(RosterSchedule $schedule, int $userId): RosterOptimizationLog
    {
        $before = $this->scoreSchedule($schedule);

        $period    = CarbonPeriod::create($schedule->period_start, $schedule->period_end);
        $changes   = [];
        $employees = $this->getEligibleEmployees($schedule->department);

        foreach ($period as $date) {
            $dateStr = $date->toDateString();

            $assigned = $schedule->assignments()
                ->where('assigned_date', $dateStr)
                ->where('is_rest_day', false)
                ->count();

            $headcount = $employees->count();
            $required  = max(1, (int) ceil($headcount * 0.8)); // 80% coverage target

            if ($assigned < $required) {
                // Find unassigned employees for this date
                $assignedIds = $schedule->assignments()
                    ->where('assigned_date', $dateStr)
                    ->pluck('employee_id');

                $unassigned = $employees->filter(fn($e) => ! $assignedIds->contains($e->id));

                foreach ($unassigned->take($required - $assigned) as $emp) {
                    $existing = $schedule->assignments()
                        ->where('employee_id', $emp->id)
                        ->where('assigned_date', $dateStr)
                        ->first();

                    if ($existing && $existing->is_rest_day) {
                        $existing->update(['is_rest_day' => false, 'status' => 'scheduled']);
                    } else {
                        RosterAssignment::create([
                            'roster_schedule_id' => $schedule->id,
                            'employee_id'        => $emp->id,
                            'assigned_date'      => $dateStr,
                            'status'             => 'scheduled',
                            'is_ai_suggested'    => true,
                        ]);
                    }
                    $changes[] = "Added {$emp->first_name} {$emp->surname} on {$dateStr}";
                }
            }
        }

        $after = $this->scoreSchedule($schedule);
        $schedule->update(['coverage_score' => $after['coverage'], 'balance_score' => $after['balance']]);

        return RosterOptimizationLog::create([
            'roster_schedule_id'   => $schedule->id,
            'optimization_type'    => 'staffing_levels',
            'coverage_score_before'=> $before['coverage'],
            'coverage_score_after' => $after['coverage'],
            'balance_score_before' => $before['balance'],
            'balance_score_after'  => $after['balance'],
            'changes_made'         => $changes,
            'recommendations'      => $this->buildRecommendations($after),
            'triggered_by'         => $userId,
        ]);
    }

    public function optimizeShiftDistribution(RosterSchedule $schedule, int $userId): RosterOptimizationLog
    {
        $before    = $this->scoreSchedule($schedule);
        $changes   = [];
        $employees = $this->getEligibleEmployees($schedule->department);
        $shifts    = AttendanceShift::where('active', true)->get();

        if ($shifts->isEmpty()) {
            return $this->noOpLog($schedule, 'shift_distribution', $before, $userId);
        }

        // Count shift assignments per employee
        $empShiftCounts = [];
        foreach ($employees as $emp) {
            $empShiftCounts[$emp->id] = $schedule->assignments()
                ->where('employee_id', $emp->id)
                ->where('is_rest_day', false)
                ->groupBy('shift_id')
                ->select('shift_id', DB::raw('count(*) as cnt'))
                ->pluck('cnt', 'shift_id');
        }

        // Redistribute: employees with >60% of one shift get rotated
        foreach ($employees as $emp) {
            $counts   = $empShiftCounts[$emp->id] ?? collect();
            $total    = $counts->sum();
            if ($total < 5) continue;

            foreach ($counts as $shiftId => $cnt) {
                if (($cnt / $total) > 0.6 && $shifts->count() > 1) {
                    // Swap half of those assignments to the next shift
                    $altShift = $shifts->where('id', '!=', $shiftId)->first();
                    if (! $altShift) continue;

                    $toSwap = (int) floor($cnt / 3);
                    $swapped = $schedule->assignments()
                        ->where('employee_id', $emp->id)
                        ->where('shift_id', $shiftId)
                        ->take($toSwap)
                        ->get();

                    foreach ($swapped as $a) {
                        $a->update(['shift_id' => $altShift->id]);
                    }
                    $changes[] = "Redistributed {$toSwap} shifts for emp#{$emp->id}: shift#{$shiftId}→#{$altShift->id}";
                }
            }
        }

        $after = $this->scoreSchedule($schedule);
        $schedule->update(['balance_score' => $after['balance']]);

        return RosterOptimizationLog::create([
            'roster_schedule_id'   => $schedule->id,
            'optimization_type'    => 'shift_distribution',
            'coverage_score_before'=> $before['coverage'],
            'coverage_score_after' => $after['coverage'],
            'balance_score_before' => $before['balance'],
            'balance_score_after'  => $after['balance'],
            'changes_made'         => $changes,
            'recommendations'      => $this->buildRecommendations($after),
            'triggered_by'         => $userId,
        ]);
    }

    public function detectShiftImbalances(RosterSchedule $schedule): array
    {
        $employees   = $this->getEligibleEmployees($schedule->department);
        $imbalances  = [];

        foreach ($employees as $emp) {
            $workDays = $schedule->assignments()
                ->where('employee_id', $emp->id)
                ->where('is_rest_day', false)
                ->count();

            $totalDays = Carbon::parse($schedule->period_start)
                ->diffInDays(Carbon::parse($schedule->period_end)) + 1;

            $loadPct = $totalDays > 0 ? round($workDays / $totalDays * 100) : 0;

            if ($loadPct > 85) {
                $imbalances[] = [
                    'employee'  => "{$emp->first_name} {$emp->surname}",
                    'issue'     => 'overloaded',
                    'work_pct'  => $loadPct,
                ];
            } elseif ($loadPct < 50 && $loadPct > 0) {
                $imbalances[] = [
                    'employee'  => "{$emp->first_name} {$emp->surname}",
                    'issue'     => 'underutilised',
                    'work_pct'  => $loadPct,
                ];
            }
        }

        return $imbalances;
    }

    public function generateAiOptimizationReport(RosterSchedule $schedule, int $userId): RosterOptimizationLog
    {
        $before     = $this->scoreSchedule($schedule);
        $imbalances = $this->detectShiftImbalances($schedule);

        $imbalanceSummary = collect($imbalances)
            ->groupBy('issue')
            ->map(fn($g) => $g->count())
            ->toJson();

        $request = AgentRequest::make()
            ->system('You are an AI roster optimisation expert for workforce management. Provide concise, actionable roster recommendations. Be specific about which patterns need correction. Use bullet points. Max 6 bullets.')
            ->user("Analyse this roster schedule '{$schedule->name}' for {$schedule->department}. Period: {$schedule->period_start->toDateString()} to {$schedule->period_end->toDateString()}. Coverage score: {$before['coverage']}/100. Balance score: {$before['balance']}/100. Imbalance issues: {$imbalanceSummary}. Provide optimisation recommendations.");

        try {
            $response = $this->agentRegistry->for('roster_intelligence')->ask($request);
            $report   = $response->content ?? 'AI report unavailable.';
        } catch (\Throwable) {
            $report = "Coverage score: {$before['coverage']}/100. Balance score: {$before['balance']}/100. " .
                count($imbalances) . " imbalances detected.";
        }

        $schedule->update(['ai_optimization_notes' => $report]);

        return RosterOptimizationLog::create([
            'roster_schedule_id'   => $schedule->id,
            'optimization_type'    => 'full',
            'coverage_score_before'=> $before['coverage'],
            'coverage_score_after' => $before['coverage'],
            'balance_score_before' => $before['balance'],
            'balance_score_after'  => $before['balance'],
            'changes_made'         => [],
            'recommendations'      => $this->buildRecommendations($before),
            'ai_report'            => $report,
            'triggered_by'         => $userId,
        ]);
    }

    // ── Scoring ───────────────────────────────────────────────────────────────

    public function scoreSchedule(RosterSchedule $schedule): array
    {
        $employees = $this->getEligibleEmployees($schedule->department);
        $total     = $employees->count();

        if ($total === 0) return ['coverage' => 0, 'balance' => 0];

        $period    = CarbonPeriod::create($schedule->period_start, $schedule->period_end);
        $workDays  = collect($period)->filter(fn($d) => ! $d->isWeekend())->count();

        $totalSlots = $total * $workDays;
        $filledSlots = $schedule->assignments()
            ->where('is_rest_day', false)
            ->count();

        $coverage = $totalSlots > 0 ? min(100, (int) round($filledSlots / $totalSlots * 100)) : 0;

        // Balance: measure std dev of per-employee workload
        $workloads = [];
        foreach ($employees as $emp) {
            $workloads[] = $schedule->assignments()
                ->where('employee_id', $emp->id)
                ->where('is_rest_day', false)
                ->count();
        }

        if (count($workloads) < 2) {
            $balance = 100;
        } else {
            $mean    = array_sum($workloads) / count($workloads);
            $variance = array_sum(array_map(fn($w) => pow($w - $mean, 2), $workloads)) / count($workloads);
            $stdDev   = sqrt($variance);
            $balance  = max(0, (int) round(100 - ($stdDev / max(1, $mean) * 100)));
        }

        return ['coverage' => $coverage, 'balance' => $balance];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function getEligibleEmployees(?string $department)
    {
        $query = DB::table('hr_people')->where('status', 1);
        if ($department) {
            $query->where('division_level_1', $department);
        }
        return $query->select('id', 'first_name', 'surname', 'division_level_1')->get();
    }

    private function buildRecommendations(array $scores): array
    {
        $recs = [];
        if ($scores['coverage'] < 70) $recs[] = 'Coverage below 70% — add more employees to uncovered days.';
        if ($scores['balance'] < 60)  $recs[] = 'Shift distribution is uneven — redistribute workload across team.';
        if ($scores['coverage'] >= 90 && $scores['balance'] >= 80) $recs[] = 'Schedule is well optimised.';
        return $recs;
    }

    private function noOpLog(RosterSchedule $schedule, string $type, array $scores, int $userId): RosterOptimizationLog
    {
        return RosterOptimizationLog::create([
            'roster_schedule_id'   => $schedule->id,
            'optimization_type'    => $type,
            'coverage_score_before'=> $scores['coverage'],
            'coverage_score_after' => $scores['coverage'],
            'balance_score_before' => $scores['balance'],
            'balance_score_after'  => $scores['balance'],
            'changes_made'         => [],
            'recommendations'      => ['No changes applied — no shifts defined.'],
            'triggered_by'         => $userId,
        ]);
    }
}
