<?php

namespace App\Modules\HR\Hr\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Modules\HR\Hr\Models\Attendance\AttendanceAnomaly;
use App\Modules\HR\Hr\Models\Attendance\AttendanceBehaviorPattern;
use App\Modules\HR\Hr\Models\Attendance\AttendanceRecord;
use App\Modules\HR\Hr\Models\Attendance\AttendanceRiskScore;
use App\Modules\HR\Hr\Models\HRPerson;
use App\Shared\DTOs\AgentRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Phase 4: Context-Aware Attendance Intelligence
 *
 * Wraps raw anomaly/risk data in AI-generated explanations.
 * Instead of just flagging issues, the system explains them
 * by considering context: leave records, public holidays,
 * team patterns, policy changes, employee history.
 */
class AttendanceIntelligenceService
{
    public function __construct(
        private readonly AgentRegistry $agentRegistry,
    ) {}

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Generate a contextual AI explanation for a specific anomaly.
     */
    public function explainAnomaly(AttendanceAnomaly $anomaly): string
    {
        $employee = $anomaly->employee;
        $context  = $this->buildAnomalyContext($anomaly, $employee);

        $prompt = $this->buildAnomalyPrompt($anomaly, $context);

        $explanation = $this->ask($prompt, $context);

        $anomaly->update(['ai_explanation' => $explanation]);

        return $explanation;
    }

    /**
     * Generate a holistic AI summary of an employee's attendance risk profile.
     */
    public function explainRiskScore(AttendanceRiskScore $riskScore): string
    {
        $employee = $riskScore->employee;
        $context  = $this->buildRiskContext($riskScore, $employee);

        $prompt = $this->buildRiskPrompt($riskScore, $context);

        $summary = $this->ask($prompt, $context);

        $riskScore->update(['ai_summary' => $summary]);

        return $summary;
    }

    /**
     * Generate pattern insights for an employee across multiple detected patterns.
     */
    public function generatePatternInsights(int $employeeId, string $periodStart, string $periodEnd): array
    {
        $patterns  = AttendanceBehaviorPattern::forEmployee($employeeId)
            ->whereBetween('period_start', [$periodStart, $periodEnd])
            ->get();

        $employee = HRPerson::find($employeeId);
        $insights = [];

        foreach ($patterns as $pattern) {
            $insight = $this->explainPattern($pattern, $employee);
            $pattern->update(['insight' => $insight]);
            $insights[$pattern->pattern_type] = $insight;
        }

        return $insights;
    }

    /**
     * Chat-style Q&A about a specific employee's attendance.
     */
    public function askAboutEmployee(int $employeeId, string $question, string $periodStart, string $periodEnd): string
    {
        $employee = HRPerson::find($employeeId);
        $context  = $this->buildEmployeeContext($employeeId, $periodStart, $periodEnd, $employee);

        $prompt = "The user is asking about employee {$employee?->full_name}'s attendance. Question: {$question}";

        return $this->ask($prompt, $context);
    }

    /**
     * Generate a manager-ready narrative report for a team or department.
     */

    public function generateTeamAttendanceSummary(array $employeeIds, string $periodStart, string $periodEnd): string
    {
        $teamData = [];

        foreach ($employeeIds as $id) {
            $riskScore  = AttendanceRiskScore::forEmployee($id)->where('period_start', $periodStart)->first();
            $anomalies  = AttendanceAnomaly::forEmployee($id)->open()->where('period_start', $periodStart)->count();
            $employee   = HRPerson::find($id);

            $teamData[] = [
                'name'        => $employee?->full_name ?? "Employee #{$id}",
                'risk_level'  => $riskScore?->risk_level ?? 'unknown',
                'risk_score'  => $riskScore?->score ?? 0,
                'anomalies'   => $anomalies,
                'attendance_rate' => $riskScore?->attendance_rate ?? 100,
            ];
        }

        usort($teamData, fn($a, $b) => $b['risk_score'] <=> $a['risk_score']);

        $context = [
            'period'        => "{$periodStart} to {$periodEnd}",
            'team_size'     => count($employeeIds),
            'team_summary'  => $teamData,
            'high_risk'     => array_filter($teamData, fn($e) => in_array($e['risk_level'], ['high', 'critical'])),
        ];

        $prompt = "Generate a concise, professional attendance intelligence report for a team of " . count($employeeIds) .
            " employees covering {$periodStart} to {$periodEnd}. " .
            "Highlight key concerns, patterns, and recommended management actions. " .
            "Keep it factual, constructive, and under 300 words.";

        return $this->ask($prompt, $context);
    }

    // ── Context Builders ──────────────────────────────────────────────────────

    private function buildAnomalyContext(AttendanceAnomaly $anomaly, ?HRPerson $employee): array
    {
        $start = $anomaly->period_start->toDateString();
        $end   = $anomaly->period_end->toDateString();

        return [
            'employee'             => $employee?->full_name ?? 'Unknown',
            'department'           => $employee?->department ?? 'Unknown',
            'anomaly_type'         => $anomaly->anomaly_type,
            'severity'             => $anomaly->severity,
            'period'               => "{$start} to {$end}",
            'evidence'             => $anomaly->evidence ?? [],
            'recent_leave_days'    => $this->countLeaveDays($anomaly->employee_id, $start, $end),
            'public_holidays'      => $this->countPublicHolidays($start, $end),
            'has_approved_leave'   => $this->hasApprovedLeave($anomaly->employee_id, $start, $end),
            'open_anomaly_count'   => AttendanceAnomaly::forEmployee($anomaly->employee_id)->open()->count(),
        ];
    }

    private function buildRiskContext(AttendanceRiskScore $score, ?HRPerson $employee): array
    {
        return [
            'employee'         => $employee?->full_name ?? 'Unknown',
            'department'       => $employee?->department ?? 'Unknown',
            'risk_score'       => $score->score,
            'risk_level'       => $score->risk_level,
            'attendance_rate'  => $score->attendance_rate,
            'late_count'       => $score->late_count,
            'absent_count'     => $score->absent_count,
            'anomaly_count'    => $score->anomaly_count,
            'period'           => $score->period_start->toDateString() . ' to ' . $score->period_end->toDateString(),
            'score_breakdown'  => $score->score_breakdown ?? [],
            'open_anomalies'   => AttendanceAnomaly::forEmployee($score->employee_id)->open()
                ->get(['anomaly_type', 'severity'])
                ->map(fn($a) => ['type' => $a->anomaly_type, 'severity' => $a->severity])
                ->toArray(),
        ];
    }

    private function buildEmployeeContext(int $employeeId, string $start, string $end, ?HRPerson $employee): array
    {
        $latestScore = AttendanceRiskScore::forEmployee($employeeId)->latest('calculated_at')->first();
        $anomalies   = AttendanceAnomaly::forEmployee($employeeId)->open()->get();
        $records     = AttendanceRecord::forEmployee($employeeId)->whereBetween('date', [$start, $end])->get();

        return [
            'employee'        => $employee?->full_name ?? 'Unknown',
            'period'          => "{$start} to {$end}",
            'risk_level'      => $latestScore?->risk_level ?? 'unknown',
            'risk_score'      => $latestScore?->score ?? 0,
            'present_days'    => $records->filter(fn($r) => $r->clock_in)->count(),
            'late_days'       => $records->where('is_late', true)->count(),
            'open_anomalies'  => $anomalies->map(fn($a) => $a->anomaly_type)->toArray(),
            'leave_days'      => $this->countLeaveDays($employeeId, $start, $end),
        ];
    }

    private function explainPattern(AttendanceBehaviorPattern $pattern, ?HRPerson $employee): string
    {
        $context = [
            'employee'     => $employee?->full_name ?? 'Unknown',
            'pattern_type' => $pattern->pattern_type,
            'period'       => $pattern->period_start->toDateString() . ' to ' . $pattern->period_end->toDateString(),
            'data'         => $pattern->data ?? [],
        ];

        $prompt = "Explain this attendance behavior pattern in plain, professional language for an HR manager. " .
            "Pattern type: {$pattern->pattern_type}. " .
            "Data: " . json_encode($pattern->data ?? []) . ". " .
            "Consider any mitigating factors. Keep the explanation under 100 words. Be factual and constructive.";

        return $this->ask($prompt, $context);
    }

    // ── AI Call ───────────────────────────────────────────────────────────────

    private function ask(string $prompt, array $context = []): string
    {
        try {
            $request = AgentRequest::make()
                ->withModule('attendance_intelligence')
                ->withSystemPrompt($this->systemPrompt())
                ->withPrompt($prompt)
                ->withContext($context);

            $response = $this->agentRegistry->for('attendance_intelligence')->ask($request);

            return $response->failed()
                ? 'AI analysis temporarily unavailable. Please review the raw data above.'
                : $response->content;
        } catch (\Throwable $e) {
            Log::warning('AttendanceIntelligenceService AI call failed', ['error' => $e->getMessage()]);
            return 'AI analysis temporarily unavailable. Please review the raw data above.';
        }
    }

    private function systemPrompt(): string
    {
        return <<<SYSTEM
You are an expert HR Attendance Intelligence Analyst embedded in an ERP system.
Your role is to provide context-aware, empathetic, and professional explanations of attendance data.

Guidelines:
- Never accuse employees of wrongdoing based on patterns alone — always acknowledge possible legitimate reasons.
- Consider context: approved leave, public holidays, department-wide patterns, policy changes.
- Be concise and factual. Avoid jargon.
- Provide actionable recommendations when appropriate.
- Use plain English suitable for both HR managers and employees.
- Do not invent data. Only interpret what is provided in the context.
- Maximum response: 200 words unless asked otherwise.
SYSTEM;
    }

    // ── Supporting data lookups ───────────────────────────────────────────────

    private function countLeaveDays(int $employeeId, string $start, string $end): int
    {
        // Use the leave_requests table if it exists
        try {
            return \DB::table('leave_requests')
                ->where('employee_id', $employeeId)
                ->where('status', 'approved')
                ->where(function ($q) use ($start, $end) {
                    $q->whereBetween('start_date', [$start, $end])
                      ->orWhereBetween('end_date', [$start, $end]);
                })
                ->sum('working_days');
        } catch (\Throwable) {
            return 0;
        }
    }

    private function countPublicHolidays(string $start, string $end): int
    {
        try {
            return \DB::table('public_holidays')
                ->whereBetween('date', [$start, $end])
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function hasApprovedLeave(int $employeeId, string $start, string $end): bool
    {
        return $this->countLeaveDays($employeeId, $start, $end) > 0;
    }
}
