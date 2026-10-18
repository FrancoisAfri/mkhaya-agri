<?php

namespace App\Modules\Disciplinary\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Core\CompanyUnits\Models\CompanyUnit;
use App\Modules\Disciplinary\Models\DepartmentBehaviorRisk;
use App\Modules\Disciplinary\Models\DisciplinaryBehaviorProfile;
use App\Modules\Disciplinary\Models\DisciplinaryCase;
use App\Modules\Disciplinary\Models\DisciplinaryHeatmap;
use App\Modules\Disciplinary\Models\DisciplinaryRiskPrediction;
use App\Shared\DTOs\AgentRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RiskIntelligenceService
{
    private const MODULE = 'disciplinary';

    public function __construct(protected AgentRegistry $agents) {}

    // ── Individual employee risk ──────────────────────────────────────────────

    public function detectHighRiskEmployee(int $employeeId, int $generatedBy): DisciplinaryRiskPrediction
    {
        $profile = DisciplinaryBehaviorProfile::where('employee_id', $employeeId)
            ->latest()->first();

        $cases = DisciplinaryCase::where('employee_id', $employeeId)
            ->with('category')->orderByDesc('incident_date')->limit(10)->get();

        $factors    = $this->buildRiskFactors($profile, $cases);
        $probability= $this->calculateProbability($factors);
        $riskLevel  = $this->scoreToLevel($probability);

        $reasoning    = null;
        $recommended  = null;
        try {
            [$reasoning, $recommended] = $this->generateEmployeeRiskInsight($employeeId, $profile, $cases, $factors);
        } catch (\Throwable $e) {
            Log::warning("RiskIntelligence: AI insight failed for employee {$employeeId}", ['error' => $e->getMessage()]);
        }

        return DisciplinaryRiskPrediction::create([
            'employee_id'           => $employeeId,
            'prediction_type'       => 'behavioral_decline',
            'probability'           => $probability,
            'risk_level'            => $riskLevel,
            'prediction_window_end' => now()->addMonths(3)->toDateString(),
            'reasoning'             => $reasoning ?? "Rule-based score: {$probability}%",
            'contributing_factors'  => $factors,
            'recommended_actions'   => $recommended ?? [],
            'is_ai_generated'       => $reasoning !== null,
            'ai_provider'           => $reasoning ? config('ai.provider', 'claude') : null,
            'ai_model'              => $reasoning ? config('ai.claude.model', 'claude-3-5-sonnet-20241022') : null,
            'generated_by'          => $generatedBy,
        ]);
    }

    public function predictDismissalRisk(int $employeeId, int $generatedBy): DisciplinaryRiskPrediction
    {
        $history    = $this->buildEmployeeCaseHistory($employeeId);
        $probability= $this->calculateDismissalProbability($history);
        $riskLevel  = $this->scoreToLevel($probability);

        return DisciplinaryRiskPrediction::create([
            'employee_id'           => $employeeId,
            'prediction_type'       => 'dismissal_risk',
            'probability'           => $probability,
            'risk_level'            => $riskLevel,
            'prediction_window_end' => now()->addMonths(6)->toDateString(),
            'reasoning'             => "Based on progressive discipline trajectory: {$history['current_level']}.",
            'contributing_factors'  => $history,
            'recommended_actions'   => $probability >= 60
                ? ['Immediate HR intervention', 'Final warning review', 'Performance improvement plan']
                : [],
            'is_ai_generated'       => false,
            'generated_by'          => $generatedBy,
        ]);
    }

    // ── Department risk ───────────────────────────────────────────────────────

    public function detectDepartmentRiskTrend(int $departmentId, string $periodStart, string $periodEnd, int $generatedBy): DepartmentBehaviorRisk
    {
        $stats = $this->aggregateDepartmentStats($departmentId, $periodStart, $periodEnd);

        $riskScore = $this->calculateDepartmentRiskScore($stats);
        $riskLevel = $this->scoreToLevel($riskScore);

        $prev = DepartmentBehaviorRisk::where('department_id', $departmentId)
            ->where('period_end', '<', $periodEnd)
            ->orderByDesc('period_end')
            ->first();

        $trend = 'stable';
        if ($prev) {
            $trend = $riskScore > $prev->risk_score + 5  ? 'worsening'
                : ($riskScore < $prev->risk_score - 5     ? 'improving' : 'stable');
        }

        $aiNarrative = null;
        try {
            $aiNarrative = $this->generateDepartmentNarrative($departmentId, $stats, $trend, $riskLevel);
        } catch (\Throwable $e) {
            Log::warning("RiskIntelligence: dept narrative failed for dept {$departmentId}", ['error' => $e->getMessage()]);
        }

        return DepartmentBehaviorRisk::updateOrCreate(
            ['department_id' => $departmentId, 'period_start' => $periodStart, 'period_end' => $periodEnd],
            array_merge($stats, [
                'risk_score'   => $riskScore,
                'risk_level'   => $riskLevel,
                'trend'        => $trend,
                'ai_narrative' => $aiNarrative,
                'is_ai_generated' => $aiNarrative !== null,
                'ai_provider'  => $aiNarrative ? config('ai.provider', 'claude') : null,
                'ai_model'     => $aiNarrative ? config('ai.claude.model', 'claude-3-5-sonnet-20241022') : null,
            ])
        );
    }

    // ── Heatmap generation ────────────────────────────────────────────────────

    public function predictDisciplinaryHotspot(string $periodStart, string $periodEnd, int $generatedBy): DisciplinaryHeatmap
    {
        $departments = CompanyUnit::where('type', 'department')->get();

        $dataPoints = $departments->map(function ($dept) use ($periodStart, $periodEnd) {
            $count = DB::table('disciplinary_cases as dc')
                ->join('company_unit_user_pivots as cup', 'cup.user_id', '=', 'dc.employee_id')
                ->where('cup.company_id', $dept->id)
                ->whereBetween('dc.incident_date', [$periodStart, $periodEnd])
                ->count();

            return [
                'label'      => $dept->name,
                'value'      => $count,
                'risk_level' => $count >= 5 ? 'critical' : ($count >= 3 ? 'high' : ($count >= 1 ? 'medium' : 'low')),
                'meta'       => ['department_id' => $dept->id],
            ];
        })->sortByDesc('value')->values()->toArray();

        return DisciplinaryHeatmap::create([
            'heatmap_type'   => 'department_risk',
            'period_start'   => $periodStart,
            'period_end'     => $periodEnd,
            'data_points'    => $dataPoints,
            'legend'         => [
                ['label' => 'Low',      'color' => '#48bb78', 'min' => 0,  'max' => 0],
                ['label' => 'Medium',   'color' => '#ecc94b', 'min' => 1,  'max' => 2],
                ['label' => 'High',     'color' => '#f6ad55', 'min' => 3,  'max' => 4],
                ['label' => 'Critical', 'color' => '#fc8181', 'min' => 5,  'max' => null],
            ],
            'title'          => "Department Disciplinary Hotspot Map",
            'description'    => "Case distribution by department for {$periodStart} to {$periodEnd}",
            'is_ai_generated'=> false,
            'generated_by'   => $generatedBy,
        ]);
    }

    public function generateOffenceCategoryHeatmap(string $periodStart, string $periodEnd, int $generatedBy): DisciplinaryHeatmap
    {
        $categories = DB::table('disciplinary_cases as dc')
            ->join('disciplinary_categories as cat', 'cat.id', '=', 'dc.category_id')
            ->whereBetween('dc.incident_date', [$periodStart, $periodEnd])
            ->selectRaw('cat.name, cat.severity_level, COUNT(*) as case_count')
            ->groupBy('cat.id', 'cat.name', 'cat.severity_level')
            ->orderByDesc('case_count')
            ->get();

        $dataPoints = $categories->map(fn($row) => [
            'label'      => $row->name,
            'value'      => $row->case_count,
            'risk_level' => $row->severity_level ?? 'medium',
            'meta'       => ['severity' => $row->severity_level],
        ])->toArray();

        return DisciplinaryHeatmap::create([
            'heatmap_type'   => 'offence_category',
            'period_start'   => $periodStart,
            'period_end'     => $periodEnd,
            'data_points'    => $dataPoints,
            'legend'         => null,
            'title'          => "Offence Category Distribution",
            'description'    => "Frequency of disciplinary cases by offence category",
            'is_ai_generated'=> false,
            'generated_by'   => $generatedBy,
        ]);
    }

    public function generateMonthlyTrendHeatmap(int $year, int $generatedBy): DisciplinaryHeatmap
    {
        $months = collect(range(1, 12))->map(function ($m) use ($year) {
            $count = DisciplinaryCase::whereYear('incident_date', $year)
                ->whereMonth('incident_date', $m)
                ->count();

            return [
                'label'      => Carbon::create($year, $m)->format('M'),
                'value'      => $count,
                'risk_level' => $count >= 5 ? 'critical' : ($count >= 3 ? 'high' : ($count >= 1 ? 'medium' : 'low')),
                'meta'       => ['month' => $m, 'year' => $year],
            ];
        })->toArray();

        return DisciplinaryHeatmap::create([
            'heatmap_type'   => 'monthly_trend',
            'period_start'   => "{$year}-01-01",
            'period_end'     => "{$year}-12-31",
            'data_points'    => $months,
            'legend'         => null,
            'title'          => "Monthly Disciplinary Trend {$year}",
            'description'    => "Case volume by month for {$year}",
            'is_ai_generated'=> false,
            'generated_by'   => $generatedBy,
        ]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function buildRiskFactors(?DisciplinaryBehaviorProfile $profile, Collection $cases): array
    {
        $factors = [];

        if ($profile) {
            if ($profile->active_warnings > 0)     $factors[] = ['factor' => 'Active warnings',     'value' => $profile->active_warnings,   'weight' => 20];
            if ($profile->repeated_offences > 0)   $factors[] = ['factor' => 'Repeated offences',   'value' => $profile->repeated_offences,  'weight' => 25];
            if ($profile->unexcused_absences >= 3) $factors[] = ['factor' => 'Attendance issues',   'value' => $profile->unexcused_absences, 'weight' => 10];
        }

        $gross = $cases->filter(fn($c) => $c->category?->severity_level === 'gross')->count();
        if ($gross > 0) $factors[] = ['factor' => 'Gross misconduct history', 'value' => $gross, 'weight' => 30];

        return $factors;
    }

    private function calculateProbability(array $factors): float
    {
        $score = array_sum(array_column($factors, 'weight'));
        return min(100, round($score, 2));
    }

    private function calculateDismissalProbability(array $history): float
    {
        $score = 0;
        if ($history['current_level'] === 'final_written') $score += 40;
        if ($history['current_level'] === 'suspension')    $score += 60;
        if (($history['prior_cases'] ?? 0) > 1)           $score += 20;
        if (!$history['is_first_offence'])                 $score += 15;
        return min(100, $score);
    }

    private function calculateDepartmentRiskScore(array $stats): float
    {
        $score = ($stats['total_cases'] * 5) + ($stats['dismissals'] * 20) +
                 ($stats['suspensions'] * 10) + ($stats['repeat_offenders'] * 15);
        return min(100, round($score, 2));
    }

    private function scoreToLevel(float $score): string
    {
        return match (true) {
            $score >= 75 => 'critical',
            $score >= 50 => 'high',
            $score >= 25 => 'medium',
            default      => 'low',
        };
    }

    private function buildEmployeeCaseHistory(int $employeeId): array
    {
        $service = app(ProgressiveDisciplineService::class);
        return $service->buildProgressiveHistory($employeeId);
    }

    private function aggregateDepartmentStats(int $departmentId, string $from, string $to): array
    {
        $employeeIds = DB::table('company_unit_user_pivots')
            ->where('company_id', $departmentId)
            ->pluck('user_id');

        $base = DisciplinaryCase::whereIn('employee_id', $employeeIds)
            ->whereBetween('incident_date', [$from, $to]);

        $total      = (clone $base)->count();
        $dismissals = (clone $base)->whereHas('outcome', fn($q) => $q->where('name', 'like', '%dismiss%'))->count();

        $suspensions = DB::table('disciplinary_suspensions')
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'active')->count();

        $categories = DisciplinaryCase::whereIn('employee_id', $employeeIds)
            ->whereBetween('incident_date', [$from, $to])
            ->join('disciplinary_categories as cat', 'cat.id', '=', 'disciplinary_cases.category_id')
            ->selectRaw('cat.name, COUNT(*) as cnt')
            ->groupBy('cat.id', 'cat.name')
            ->orderByDesc('cnt')->limit(5)->pluck('cnt', 'name')->toArray();

        return [
            'total_cases'            => $total,
            'active_cases'           => 0,
            'dismissals'             => $dismissals,
            'suspensions'            => $suspensions,
            'warnings_issued'        => 0,
            'repeat_offenders'       => 0,
            'avg_case_duration_days' => 0,
            'top_offence_categories' => $categories,
        ];
    }

    private function generateEmployeeRiskInsight(int $employeeId, ?DisciplinaryBehaviorProfile $profile, Collection $cases, array $factors): array
    {
        $prompt = "You are an HR risk analyst. In 2 short paragraphs, assess the disciplinary risk for this employee " .
                  "and recommend 2-3 actions. Return JSON: {\"reasoning\": \"...\", \"recommended_actions\": [\"...\"]}. " .
                  "Data: " . json_encode(['profile' => $profile?->only(['risk_level', 'overall_risk_score', 'active_warnings']), 'factors' => $factors, 'case_count' => $cases->count()]);

        $request = new AgentRequest(
            prompt        : $prompt,
            module        : self::MODULE,
            systemPrompt  : 'You are an expert HR risk intelligence analyst.',
            temperature   : 0.3,
            maxTokens     : 600,
            responseFormat: 'json',
        );

        $response = $this->agents->for(self::MODULE)->ask($request);
        $text     = is_string($response) ? $response : ($response->content ?? '');
        $text     = preg_replace('/^```(?:json)?\s*/i', '', trim($text));
        $text     = preg_replace('/\s*```$/', '', $text);
        $decoded  = json_decode($text, true);

        return [
            $decoded['reasoning']           ?? null,
            $decoded['recommended_actions'] ?? [],
        ];
    }

    private function generateDepartmentNarrative(int $departmentId, array $stats, string $trend, string $riskLevel): string
    {
        $prompt = "You are an HR department risk analyst. Write a brief (max 80 words) professional assessment " .
                  "of this department's disciplinary risk. Trend: {$trend}. Risk level: {$riskLevel}. " .
                  "Stats: " . json_encode($stats);

        $request = new AgentRequest(
            prompt       : $prompt,
            module       : self::MODULE,
            systemPrompt : 'You are an expert HR workforce risk analyst.',
            temperature  : 0.2,
            maxTokens    : 200,
        );

        $response = $this->agents->for(self::MODULE)->ask($request);
        return is_string($response) ? $response : ($response->content ?? '');
    }
}
