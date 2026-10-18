<?php

namespace App\Modules\Disciplinary\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Modules\Disciplinary\Models\DisciplinaryBehaviorProfile;
use App\Modules\Disciplinary\Models\DisciplinaryCase;
use App\Shared\DTOs\AgentRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WorkforceIntelligenceService
{
    private const MODULE = 'disciplinary';

    public function __construct(protected AgentRegistry $agents) {}

    // ── Public API ─────────────────────────────────────────────────────────────

    public function generateBehaviorProfile(int $employeeId, string $periodStart, string $periodEnd): DisciplinaryBehaviorProfile
    {
        $attendance   = $this->analyzeAttendanceMisconduct($employeeId, $periodStart, $periodEnd);
        $performance  = $this->analyzePerformanceMisconduct($employeeId);
        $disciplinary = $this->analyzeDisciplinarySignals($employeeId, $periodStart, $periodEnd);
        $compliance   = $this->calculateComplianceScore($employeeId);

        $overall = $this->calculateOverallRisk($attendance, $performance, $disciplinary, $compliance);

        $riskFactors = array_filter([
            $attendance['late_arrivals'] >= 3  ? ['factor' => 'Frequent late arrivals',   'count' => $attendance['late_arrivals']]  : null,
            $attendance['unexcused_absences'] >= 2 ? ['factor' => 'Unexcused absences',   'count' => $attendance['unexcused_absences']] : null,
            $disciplinary['active_warnings'] > 0   ? ['factor' => 'Active disciplinary warnings', 'count' => $disciplinary['active_warnings']] : null,
            $disciplinary['repeated_offences'] > 0 ? ['factor' => 'Repeated offences',    'count' => $disciplinary['repeated_offences']] : null,
            $compliance['overdue_items'] >= 2       ? ['factor' => 'Overdue training',     'count' => $compliance['overdue_items']]  : null,
        ]);

        $aiNarrative = null;
        try {
            $aiNarrative = $this->generateAiNarrative($employeeId, $attendance, $performance, $disciplinary, $compliance, $overall);
        } catch (\Throwable $e) {
            Log::warning("WorkforceIntelligence: AI narrative failed for employee {$employeeId}", ['error' => $e->getMessage()]);
        }

        return DisciplinaryBehaviorProfile::updateOrCreate(
            ['employee_id' => $employeeId, 'period_start' => $periodStart, 'period_end' => $periodEnd],
            [
                'late_arrivals'          => $attendance['late_arrivals'],
                'unexcused_absences'     => $attendance['unexcused_absences'],
                'early_departures'       => $attendance['early_departures'],
                'attendance_score'       => $attendance['score'],
                'performance_rating'     => $performance['rating'],
                'missed_kpis'            => $performance['missed_kpis'],
                'performance_score'      => $performance['score'],
                'active_warnings'        => $disciplinary['active_warnings'],
                'total_cases_ytd'        => $disciplinary['total_cases_ytd'],
                'repeated_offences'      => $disciplinary['repeated_offences'],
                'disciplinary_score'     => $disciplinary['score'],
                'overdue_training_items' => $compliance['overdue_items'],
                'compliance_score'       => $compliance['score'],
                'overall_risk_score'     => $overall['score'],
                'risk_level'             => $overall['level'],
                'risk_factors'           => array_values($riskFactors),
                'ai_narrative'           => $aiNarrative,
                'is_ai_generated'        => $aiNarrative !== null,
                'ai_provider'            => config('ai.provider', 'claude'),
                'ai_model'               => config('ai.claude.model', 'claude-3-5-sonnet-20241022'),
                'generated_by'           => auth()->id(),
            ]
        );
    }

    public function analyzeAttendanceMisconduct(int $employeeId, string $from, string $to): array
    {
        // attendance_records.employee_id references hr_people, not users
        $hrPersonId = DB::table('hr_people')->where('user_id', $employeeId)->value('id');

        if (!$hrPersonId) {
            return ['late_arrivals' => 0, 'unexcused_absences' => 0, 'early_departures' => 0, 'score' => 100];
        }

        $base  = DB::table('attendance_records')->where('employee_id', $hrPersonId)->whereBetween('date', [$from, $to]);
        $late  = (clone $base)->where('is_late', true)->count();
        $early = (clone $base)->where('is_early_departure', true)->count();
        $absent = (clone $base)
            ->join('attendance_statuses', 'attendance_statuses.id', '=', 'attendance_records.status_id')
            ->where('attendance_statuses.is_absent', true)
            ->count();

        $penalty = ($late * 3) + ($absent * 8) + ($early * 2);
        $score   = max(0, 100 - $penalty);

        return [
            'late_arrivals'      => $late,
            'unexcused_absences' => $absent,
            'early_departures'   => $early,
            'score'              => $score,
        ];
    }

    public function analyzePerformanceMisconduct(int $employeeId): array
    {
        $snapshot = DB::table('pa_performance_snapshots')
            ->where('employee_id', $employeeId)
            ->orderByDesc('snapshot_date')
            ->first();

        $rating         = $snapshot?->overall_score ?? null;
        $completionRate = (float) ($snapshot?->kpi_completion_rate ?? 100);
        $missed         = $completionRate < 100 ? (int) round((100 - $completionRate) / 10) : 0;
        $score          = $rating !== null ? min(100, max(0, (float) $rating)) : 100;

        return [
            'rating'      => $rating,
            'missed_kpis' => $missed,
            'score'       => $score,
        ];
    }

    public function analyzePayrollRiskBehavior(int $employeeId): array
    {
        $irregularities = DB::table('payroll_adjustments')
            ->where('employee_id', $employeeId)
            ->where('type', 'deduction')
            ->where('status', 'disputed')
            ->count();

        $advancesOverLimit = DB::table('payroll_advances')
            ->where('employee_id', $employeeId)
            ->where('status', 'pending')
            ->count();

        return [
            'disputed_deductions' => $irregularities,
            'pending_advances'    => $advancesOverLimit,
            'risk_flags'          => $irregularities + $advancesOverLimit,
        ];
    }

    public function analyzeTrainingComplianceViolation(int $employeeId): array
    {
        try {
            $overdue = DB::table('training_enrollments')
                ->where('employee_id', $employeeId)
                ->where('status', 'pending')
                ->where('due_date', '<', now()->toDateString())
                ->count();

            $total = DB::table('training_enrollments')
                ->where('employee_id', $employeeId)
                ->count();

            return ['overdue_items' => $overdue, 'total_items' => $total];
        } catch (\Throwable) {
            return ['overdue_items' => 0, 'total_items' => 0];
        }
    }

    public function calculateComplianceScore(int $employeeId): array
    {
        $training = $this->analyzeTrainingComplianceViolation($employeeId);
        $overdue  = $training['overdue_items'];
        $score    = max(0, 100 - ($overdue * 15));

        return array_merge($training, ['score' => $score]);
    }

    // ── High-risk listing ──────────────────────────────────────────────────────

    public function getHighRiskEmployees(string $periodEnd): \Illuminate\Support\Collection
    {
        return DisciplinaryBehaviorProfile::highRisk()
            ->where('period_end', $periodEnd)
            ->with('employee.person')
            ->orderByDesc('overall_risk_score')
            ->get();
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private function analyzeDisciplinarySignals(int $employeeId, string $from, string $to): array
    {
        $warnings = \App\Modules\Disciplinary\Models\DisciplinaryWarning::forEmployee($employeeId)
            ->active()->count();

        $ytdCases = DisciplinaryCase::where('employee_id', $employeeId)
            ->whereYear('incident_date', now()->year)->count();

        $repeated = DisciplinaryCase::where('employee_id', $employeeId)->count() > 1
            ? DisciplinaryCase::where('employee_id', $employeeId)->count() - 1
            : 0;

        $penalty = ($warnings * 10) + ($repeated * 15) + ($ytdCases * 5);
        $score   = max(0, 100 - $penalty);

        return [
            'active_warnings'  => $warnings,
            'total_cases_ytd'  => $ytdCases,
            'repeated_offences'=> $repeated,
            'score'            => $score,
        ];
    }

    private function calculateOverallRisk(array $attendance, array $performance, array $disciplinary, array $compliance): array
    {
        $score = (
            ($attendance['score']   * 0.25) +
            ($performance['score']  * 0.25) +
            ($disciplinary['score'] * 0.35) +
            ($compliance['score']   * 0.15)
        );

        $riskScore = 100 - $score;

        $level = match (true) {
            $riskScore >= 75 => 'critical',
            $riskScore >= 50 => 'high',
            $riskScore >= 25 => 'medium',
            default          => 'low',
        };

        return ['score' => round($riskScore, 2), 'level' => $level];
    }

    private function generateAiNarrative(
        int $employeeId,
        array $attendance, array $performance,
        array $disciplinary, array $compliance,
        array $overall
    ): string {
        $prompt = "You are an HR workforce intelligence specialist. Generate a brief (max 120 words) " .
                  "professional narrative describing this employee's behavioral risk profile. " .
                  "Focus on key risk indicators and recommended HR interventions. " .
                  "Profile: " . json_encode(compact('attendance', 'performance', 'disciplinary', 'compliance', 'overall'));

        $request = new AgentRequest(
            prompt       : $prompt,
            module       : self::MODULE,
            systemPrompt : 'You are an expert HR workforce analyst.',
            temperature  : 0.3,
            maxTokens    : 300,
        );

        $response = $this->agents->for(self::MODULE)->ask($request);
        return is_string($response) ? $response : ($response->content ?? '');
    }
}
