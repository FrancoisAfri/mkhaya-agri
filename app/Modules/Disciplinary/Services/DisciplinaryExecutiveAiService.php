<?php

namespace App\Modules\Disciplinary\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Modules\Disciplinary\Models\DisciplinaryAiSummary;
use App\Modules\Disciplinary\Models\DisciplinaryCase;
use App\Modules\Disciplinary\Models\DisciplinaryCorrectiveAction;
use App\Modules\Disciplinary\Models\DisciplinaryRiskScore;
use App\Shared\DTOs\AgentRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DisciplinaryExecutiveAiService
{
    private const MODULE = 'disciplinary';

    public function __construct(protected AgentRegistry $agents) {}

    // ── Executive Summary ──────────────────────────────────────────────────────

    /**
     * Generate a high-level disciplinary summary for the executive audience.
     * Covers case volume, key trends, risk levels, and recommended focus areas.
     */
    public function generateDisciplinarySummary(int $months = 3): DisciplinaryAiSummary
    {
        $context = $this->buildOrganisationContext($months);

        $prompt = "You are a senior HR executive advisor specializing in South African labour law and workplace discipline. "
            . "Generate a concise, professional EXECUTIVE SUMMARY of the organisation's disciplinary state for the past {$months} months. "
            . "Include: (1) overall case volume and trend, (2) top offence categories, (3) key risk indicators, "
            . "(4) department hotspots, (5) repeat offender patterns, (6) recommended executive actions. "
            . "Use a professional, board-ready tone. Return JSON: {\"summary\": \"...\", \"key_risks\": [], \"recommendations\": [], \"risk_rating\": \"low|moderate|high|critical\"}. "
            . "Data: " . json_encode($context);

        return $this->storeAiResult('executive_summary', 'organisation', 0, $prompt);
    }

    // ── Misconduct Trend Analysis ──────────────────────────────────────────────

    /**
     * Analyse misconduct patterns and trends over time.
     */
    public function generateMisconductTrendAnalysis(int $months = 6): DisciplinaryAiSummary
    {
        $context = $this->buildTrendContext($months);

        $prompt = "You are an HR analytics specialist. Analyse the following disciplinary case data spanning {$months} months "
            . "and identify significant misconduct trends. "
            . "Include: (1) increasing/decreasing offence categories, (2) seasonal patterns, (3) department-specific trends, "
            . "(4) employee profile patterns, (5) correlation with external factors if identifiable. "
            . "Return JSON: {\"analysis\": \"...\", \"top_trends\": [], \"emerging_risks\": [], \"declining_issues\": [], \"forecast\": \"...\"}. "
            . "Data: " . json_encode($context);

        return $this->storeAiResult('misconduct_trend', 'organisation', 0, $prompt);
    }

    // ── Compliance Insights ────────────────────────────────────────────────────

    /**
     * Assess the organisation's procedural compliance with disciplinary processes.
     */
    public function generateComplianceInsights(): DisciplinaryAiSummary
    {
        $context = $this->buildComplianceContext();

        $prompt = "You are an HR compliance specialist familiar with South African LRA and BCEA requirements. "
            . "Assess the organisation's compliance with disciplinary procedures based on the data provided. "
            . "Evaluate: (1) timeliness of notices, (2) hearing completion rates, (3) overdue investigations, "
            . "(4) policy violation resolution, (5) appeal outcomes and procedural fairness. "
            . "Return JSON: {\"overall_compliance_score\": 0-100, \"summary\": \"...\", \"gaps\": [], \"strengths\": [], \"action_items\": []}. "
            . "Data: " . json_encode($context);

        return $this->storeAiResult('compliance_insights', 'organisation', 0, $prompt);
    }

    // ── Behavioral Risk Summary ────────────────────────────────────────────────

    /**
     * Summarise behavioral risk patterns across the workforce.
     */
    public function generateBehavioralRiskSummary(): DisciplinaryAiSummary
    {
        $context = $this->buildBehavioralRiskContext();

        $prompt = "You are an organizational psychologist and HR risk analyst. "
            . "Based on the employee behavior and disciplinary risk data provided, generate a behavioral risk summary. "
            . "Include: (1) high-risk employee segments, (2) common behavioral patterns, (3) environmental or cultural risk factors, "
            . "(4) early warning indicators, (5) recommended preventive interventions. "
            . "Return JSON: {\"summary\": \"...\", \"risk_segments\": [], \"patterns\": [], \"interventions\": [], \"overall_risk\": \"low|moderate|high|critical\"}. "
            . "Data: " . json_encode($context);

        return $this->storeAiResult('behavioral_risk', 'organisation', 0, $prompt);
    }

    // ── Corrective Action Effectiveness ───────────────────────────────────────

    /**
     * Assess how effective the organisation's corrective actions have been.
     */
    public function generateCorrectiveActionEffectivenessReport(): DisciplinaryAiSummary
    {
        $context = $this->buildCorrectiveActionContext();

        $prompt = "You are an HR transformation specialist. "
            . "Evaluate the effectiveness of the organisation's corrective actions based on the data provided. "
            . "Assess: (1) completion rates by type, (2) recidivism rates post-action, (3) which action types show best outcomes, "
            . "(4) coaching session impact, (5) correlation between corrective actions and repeat offences. "
            . "Return JSON: {\"overall_effectiveness\": \"low|moderate|high\", \"summary\": \"...\", "
            . "\"effective_interventions\": [], \"ineffective_interventions\": [], \"recommendations\": [], \"recidivism_rate\": \"...\"}. "
            . "Data: " . json_encode($context);

        return $this->storeAiResult('corrective_effectiveness', 'organisation', 0, $prompt);
    }

    // ── Context builders ──────────────────────────────────────────────────────

    private function buildOrganisationContext(int $months): array
    {
        $from = now()->subMonths($months)->startOfMonth();

        return [
            'period_months'        => $months,
            'period_from'          => $from->format('d M Y'),
            'total_cases'          => DisciplinaryCase::whereNull('deleted_at')->where('created_at', '>=', $from)->count(),
            'open_cases'           => DisciplinaryCase::where(fn($q) =>
                $q->whereDoesntHave('status')->orWhereHas('status', fn($q2) => $q2->where('is_closed', false))
            )->count(),
            'cases_by_category'    => DB::table('disciplinary_cases as dc')
                ->leftJoin('disciplinary_categories as cat', 'cat.id', '=', 'dc.category_id')
                ->whereNull('dc.deleted_at')->where('dc.created_at', '>=', $from)
                ->selectRaw("COALESCE(cat.name,'Uncategorised') as category, count(*) as total")
                ->groupByRaw("COALESCE(cat.name,'Uncategorised')")->orderByDesc('total')
                ->pluck('total', 'category')->toArray(),
            'active_suspensions'   => \App\Modules\Disciplinary\Models\DisciplinarySuspension::where('status', 'active')->count(),
            'repeat_offenders'     => DisciplinaryRiskScore::where('is_repeat_offender', true)->count(),
            'critical_risk'        => DisciplinaryRiskScore::where('risk_level', 'critical')->count(),
            'high_risk'            => DisciplinaryRiskScore::where('risk_level', 'high')->count(),
            'pending_appeals'      => \App\Modules\Disciplinary\Models\DisciplinaryAppeal::whereNotIn('status', ['decided','withdrawn','lapsed'])->count(),
            'overdue_investigations'=> \App\Modules\Disciplinary\Models\DisciplinaryInvestigation::whereNotIn('status', ['completed','closed'])
                ->where('created_at', '<=', now()->subDays(14))->count(),
        ];
    }

    private function buildTrendContext(int $months): array
    {
        $from = now()->subMonths($months);

        $monthly = DB::table('disciplinary_cases')
            ->whereNull('deleted_at')->where('created_at', '>=', $from)
            ->selectRaw("TO_CHAR(created_at,'Mon YYYY') as month, TO_CHAR(created_at,'YYYY-MM') as month_key, count(*) as total")
            ->groupByRaw("TO_CHAR(created_at,'Mon YYYY'), TO_CHAR(created_at,'YYYY-MM')")
            ->orderBy('month_key')
            ->get()->map(fn($r) => ['month' => $r->month, 'total' => $r->total]);

        $byCategory = DB::table('disciplinary_cases as dc')
            ->leftJoin('disciplinary_categories as cat', 'cat.id', '=', 'dc.category_id')
            ->whereNull('dc.deleted_at')->where('dc.created_at', '>=', $from)
            ->selectRaw("COALESCE(cat.name,'Uncategorised') as category, count(*) as total")
            ->groupByRaw("COALESCE(cat.name,'Uncategorised')")->orderByDesc('total')
            ->get()->map(fn($r) => ['category' => $r->category, 'total' => $r->total]);

        return [
            'period_months' => $months,
            'monthly_cases' => $monthly,
            'by_category'   => $byCategory,
            'total_period'  => DB::table('disciplinary_cases')->whereNull('deleted_at')->where('created_at', '>=', $from)->count(),
        ];
    }

    private function buildComplianceContext(): array
    {
        $total = DisciplinaryCase::whereNull('deleted_at')->count();

        return [
            'total_cases'            => $total,
            'notice_compliance_pct'  => $total > 0
                ? round(DisciplinaryCase::whereNotNull('notice_issued_date')->count() / max($total, 1) * 100, 1)
                : 0,
            'overdue_investigations' => \App\Modules\Disciplinary\Models\DisciplinaryInvestigation::whereNotIn('status', ['completed','closed'])
                ->where('created_at', '<=', now()->subDays(14))->count(),
            'overdue_hearings'       => \App\Modules\Disciplinary\Models\DisciplinaryHearing::whereNotIn('status', ['completed','cancelled'])
                ->where('scheduled_at', '<', now())->count(),
            'unresolved_violations'  => \App\Modules\Disciplinary\Models\DisciplinaryPolicyViolation::where('is_resolved', false)->count(),
            'pending_appeals'        => \App\Modules\Disciplinary\Models\DisciplinaryAppeal::whereNotIn('status', ['decided','withdrawn','lapsed'])->count(),
            'cases_without_outcome'  => DisciplinaryCase::whereNull('outcome_id')
                ->whereHas('status', fn($q) => $q->where('is_closed', true))->count(),
        ];
    }

    private function buildBehavioralRiskContext(): array
    {
        return [
            'critical_risk_employees' => DisciplinaryRiskScore::where('risk_level', 'critical')->count(),
            'high_risk_employees'     => DisciplinaryRiskScore::where('risk_level', 'high')->count(),
            'repeat_offenders'        => DisciplinaryRiskScore::where('is_repeat_offender', true)->count(),
            'active_suspensions'      => \App\Modules\Disciplinary\Models\DisciplinarySuspension::where('status', 'active')->count(),
            'top_risk_profiles'       => DisciplinaryRiskScore::with('employee.person')
                ->whereIn('risk_level', ['critical', 'high'])
                ->orderByDesc('risk_score')
                ->limit(10)
                ->get()
                ->map(fn($r) => [
                    'risk_score'  => round($r->risk_score, 1),
                    'risk_level'  => $r->risk_level,
                    'total_cases' => $r->total_cases,
                    'is_repeat'   => $r->is_repeat_offender,
                ]),
            'behavior_patterns' => \App\Modules\Disciplinary\Models\DisciplinaryBehaviorPattern::where('acknowledged', false)
                ->selectRaw("pattern_type, severity, count(*) as total")
                ->groupBy('pattern_type', 'severity')
                ->orderByDesc('total')
                ->get(),
        ];
    }

    private function buildCorrectiveActionContext(): array
    {
        return [
            'total_actions'    => DisciplinaryCorrectiveAction::count(),
            'completed'        => DisciplinaryCorrectiveAction::where('status', 'completed')->count(),
            'in_progress'      => DisciplinaryCorrectiveAction::where('status', 'in_progress')->count(),
            'pending'          => DisciplinaryCorrectiveAction::where('status', 'pending')->count(),
            'by_type'          => DisciplinaryCorrectiveAction::selectRaw("action_type, count(*) as total, sum(case when status='completed' then 1 else 0 end) as completed")
                ->groupBy('action_type')->get(),
            'coaching_sessions'=> DB::table('disciplinary_coaching_sessions')
                ->selectRaw("status, count(*) as total")->groupBy('status')->pluck('total', 'status'),
            'recidivism_cases' => DisciplinaryRiskScore::where('is_repeat_offender', true)
                ->whereIn('employee_id', DisciplinaryCorrectiveAction::select('employee_id'))->count(),
        ];
    }

    // ── AI call + persistence ──────────────────────────────────────────────────

    private function storeAiResult(string $type, string $subjectType, int $subjectId, string $prompt): DisciplinaryAiSummary
    {
        try {
            $data = $this->ask($prompt);
        } catch (\Throwable $e) {
            Log::warning("Disciplinary Executive AI failed [{$type}]: " . $e->getMessage());
            $data = ['summary' => 'AI analysis unavailable at this time.'];
        }

        return DisciplinaryAiSummary::updateOrCreate(
            ['subject_type' => $subjectType, 'subject_id' => $subjectId, 'summary_type' => $type],
            [
                'summary'        => $data['summary'] ?? json_encode($data),
                'recommendation' => $this->encodeRecommendation(
                                        $data['recommendations'] ?? $data['recommendation'] ?? $data['action_items'] ?? null
                                    ),
                'ai_provider'    => config('ai.provider', 'claude'),
                'ai_model'       => config('ai.claude.model', 'claude-3-5-sonnet-20241022'),
                'acknowledged'   => false,
                'generated_by'   => auth()->id(),
                'ai_metadata'    => $data,
            ]
        );
    }

    private function ask(string $prompt): array
    {
        $request = new AgentRequest(
            prompt:         $prompt,
            module:         self::MODULE,
            systemPrompt:   'You are an expert HR executive advisor specializing in South African labour law. Return only valid JSON.',
            responseFormat: 'json',
            temperature:    0.3,
            maxTokens:      2048,
        );

        $response = $this->agents->for(self::MODULE)->ask($request);

        $text = is_string($response) ? $response : ($response->content ?? json_encode($response));
        $text = preg_replace('/^```(?:json)?\s*/i', '', trim($text));
        $text = preg_replace('/\s*```$/', '', $text);

        return json_decode($text, true) ?? ['summary' => $text];
    }

    private function encodeRecommendation(mixed $value): ?string
    {
        if ($value === null) return null;
        if (is_array($value)) return json_encode($value);
        return (string) $value;
    }
}
