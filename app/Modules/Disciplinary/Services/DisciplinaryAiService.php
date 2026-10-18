<?php

namespace App\Modules\Disciplinary\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Modules\Disciplinary\Models\DisciplinaryAiSummary;
use App\Modules\Disciplinary\Models\DisciplinaryBehaviorPattern;
use App\Modules\Disciplinary\Models\DisciplinaryCase;
use App\Modules\Disciplinary\Models\DisciplinaryIncident;
use App\Modules\Disciplinary\Models\DisciplinaryRiskScore;
use App\Shared\DTOs\AgentRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DisciplinaryAiService
{
    private const MODULE = 'disciplinary';

    public function __construct(protected AgentRegistry $agents) {}

    // ── Public API ─────────────────────────────────────────────────────────────

    public function analyzeEmployee(int $employeeId): array
    {
        $context = $this->buildEmployeeContext($employeeId);

        // Always recalculate rule-based risk score first
        $score = $this->calculateRuleBasedRiskScore($employeeId, $context);

        // Detect behavioral patterns
        $patterns = $this->detectBehavioralPatterns($employeeId, $context);

        // AI-generated history analysis
        try {
            $this->generateAiHistoryAnalysis($employeeId, $context);
        } catch (\Throwable $e) {
            Log::warning("Disciplinary AI: history analysis failed for employee {$employeeId}", ['error' => $e->getMessage()]);
        }

        return [
            'risk_score'    => $score,
            'patterns_found'=> count($patterns),
        ];
    }

    public function generateCaseSummary(DisciplinaryCase $case): DisciplinaryAiSummary
    {
        $context = $this->buildCaseContext($case);

        $prompt = "You are an HR compliance officer. Analyse this disciplinary case and provide a concise professional summary " .
                  "including: (1) key facts, (2) severity assessment, (3) procedural compliance notes. " .
                  "Case data: " . json_encode($context);

        $response = $this->ask($prompt, 'json');
        $data = is_array($response) ? $response : ['summary' => $response, 'recommendation' => null];

        return DisciplinaryAiSummary::updateOrCreate(
            ['subject_type' => 'case', 'subject_id' => $case->id, 'summary_type' => 'case_summary'],
            [
                'summary'        => $data['summary'] ?? json_encode($data),
                'recommendation' => $data['recommendation'] ?? null,
                'ai_provider'    => config('ai.provider', 'claude'),
                'ai_model'       => config('ai.claude.model', 'claude-3-5-sonnet-20241022'),
                'acknowledged'   => false,
                'generated_by'   => auth()->id(),
            ]
        );
    }

    public function generateSanctionRecommendation(DisciplinaryCase $case): DisciplinaryAiSummary
    {
        $context = $this->buildCaseContext($case);
        $history = $this->buildEmployeeContext($case->employee_id);

        $prompt = "You are an HR disciplinary expert. Based on the case details and employee history, " .
                  "recommend an appropriate sanction. Consider: severity level, mitigating factors, " .
                  "prior offenses, and South African labour law proportionality. " .
                  "Return JSON with: {\"recommendation\": \"...\", \"reasoning\": \"...\", \"severity_rating\": \"minor|serious|gross\"}. " .
                  "Case: " . json_encode($context) . " History: " . json_encode($history);

        $response = $this->ask($prompt, 'json');
        $data = is_array($response) ? $response : ['recommendation' => $response, 'reasoning' => null];

        return DisciplinaryAiSummary::updateOrCreate(
            ['subject_type' => 'case', 'subject_id' => $case->id, 'summary_type' => 'sanction_recommendation'],
            [
                'summary'        => $data['reasoning'] ?? 'See recommendation.',
                'recommendation' => $data['recommendation'] ?? null,
                'ai_provider'    => config('ai.provider', 'claude'),
                'ai_model'       => config('ai.claude.model', 'claude-3-5-sonnet-20241022'),
                'acknowledged'   => false,
                'generated_by'   => auth()->id(),
            ]
        );
    }

    public function analyzeDisciplinaryHistory(int $employeeId): DisciplinaryAiSummary
    {
        $context = $this->buildEmployeeContext($employeeId);
        return $this->generateAiHistoryAnalysis($employeeId, $context);
    }

    // ── Risk score calculation ─────────────────────────────────────────────────

    public function calculateRuleBasedRiskScore(int $employeeId, ?array $context = null): DisciplinaryRiskScore
    {
        $ctx   = $context ?? $this->buildEmployeeContext($employeeId);
        $cases = $ctx['cases'];

        $total   = count($cases);
        $open    = collect($cases)->where('is_closed', false)->count();
        $closed  = $total - $open;
        $gross   = collect($cases)->where('severity', 'gross')->count();
        $serious = collect($cases)->where('severity', 'serious')->count();
        $minor   = collect($cases)->where('severity', 'minor')->count();

        // Repeat offense = more than 1 closed case
        $repeatCount = max(0, $closed - 1);
        $isRepeat    = $closed >= 2;

        // Score formula: gross×25 + serious×12 + minor×4 + open×8 + repeat_penalty×15, capped at 100
        $score = min(100, ($gross * 25) + ($serious * 12) + ($minor * 4) + ($open * 8) + ($repeatCount * 15));

        $level = match (true) {
            $score >= 75 => 'critical',
            $score >= 50 => 'high',
            $score >= 25 => 'medium',
            default      => 'low',
        };

        $factors = [];
        if ($gross   > 0) $factors[] = ['factor' => 'Gross misconduct offenses',   'count' => $gross,   'weight' => 25];
        if ($serious > 0) $factors[] = ['factor' => 'Serious misconduct offenses',  'count' => $serious, 'weight' => 12];
        if ($minor   > 0) $factors[] = ['factor' => 'Minor misconduct offenses',    'count' => $minor,   'weight' => 4];
        if ($open    > 0) $factors[] = ['factor' => 'Open / unresolved cases',      'count' => $open,    'weight' => 8];
        if ($isRepeat)    $factors[] = ['factor' => 'Repeat offender pattern',      'count' => $repeatCount, 'weight' => 15];

        $lastCaseDate = collect($cases)->sortByDesc('incident_date')->first()['incident_date'] ?? null;

        return DisciplinaryRiskScore::updateOrCreate(
            ['employee_id' => $employeeId],
            [
                'risk_score'              => $score,
                'risk_level'              => $level,
                'risk_factors'            => $factors,
                'total_cases'             => $total,
                'open_cases'              => $open,
                'closed_cases'            => $closed,
                'repeat_offense_count'    => $repeatCount,
                'gross_misconduct_count'  => $gross,
                'serious_misconduct_count'=> $serious,
                'minor_misconduct_count'  => $minor,
                'is_repeat_offender'      => $isRepeat,
                'last_case_date'          => $lastCaseDate,
                'analyzed_at'             => now(),
                'analyzed_by'             => auth()->id(),
            ]
        );
    }

    // ── Behavioral pattern detection ───────────────────────────────────────────

    public function detectBehavioralPatterns(int $employeeId, ?array $context = null): array
    {
        $ctx      = $context ?? $this->buildEmployeeContext($employeeId);
        $cases    = collect($ctx['cases']);
        $detected = [];

        // Repeat offender
        if ($cases->where('is_closed', false)->count() === 0 && $cases->count() >= 2) {
            $detected[] = $this->upsertPattern($employeeId, 'repeat_offender',
                'Repeat Offender',
                "Employee has {$cases->count()} disciplinary cases on record — repeat offense pattern detected.",
                $cases->count() >= 3 ? 'high' : 'medium',
                ['case_count' => $cases->count()]
            );
        }

        // Gross misconduct
        $grossCount = $cases->where('severity', 'gross')->count();
        if ($grossCount > 0) {
            $detected[] = $this->upsertPattern($employeeId, 'policy_violation',
                'Gross Misconduct History',
                "Employee has {$grossCount} gross misconduct offense(s) on record.",
                'high',
                ['gross_count' => $grossCount]
            );
        }

        // Attendance-related incidents (check incident descriptions/categories for attendance keywords)
        $attendanceIncidents = collect($ctx['incidents'])->filter(fn($i) =>
            str_contains(strtolower($i['category'] ?? ''), 'attend') ||
            str_contains(strtolower($i['title'] ?? ''), 'attend') ||
            str_contains(strtolower($i['title'] ?? ''), 'absent') ||
            str_contains(strtolower($i['title'] ?? ''), 'late')
        );
        if ($attendanceIncidents->count() >= 2) {
            $detected[] = $this->upsertPattern($employeeId, 'attendance_misconduct',
                'Recurring Attendance Issues',
                "Employee has {$attendanceIncidents->count()} attendance-related incidents in their record.",
                $attendanceIncidents->count() >= 3 ? 'high' : 'medium',
                ['incident_count' => $attendanceIncidents->count()]
            );
        }

        // Payroll/financial fraud risk (keywords in incident titles)
        $fraudIncidents = collect($ctx['incidents'])->filter(fn($i) =>
            str_contains(strtolower($i['title'] ?? ''), 'fraud') ||
            str_contains(strtolower($i['title'] ?? ''), 'theft') ||
            str_contains(strtolower($i['title'] ?? ''), 'payroll') ||
            str_contains(strtolower($i['title'] ?? ''), 'financial')
        );
        if ($fraudIncidents->count() > 0) {
            $detected[] = $this->upsertPattern($employeeId, 'payroll_fraud_risk',
                'Financial Misconduct Risk',
                "Employee has {$fraudIncidents->count()} financial-related incident(s) flagged.",
                'high',
                ['incident_count' => $fraudIncidents->count()]
            );
        }

        return array_filter($detected);
    }

    public function detectRepeatOffenders(): Collection
    {
        return DisciplinaryRiskScore::where('is_repeat_offender', true)
            ->with('employee.person')
            ->orderByDesc('risk_score')
            ->get();
    }

    public function detectDepartmentTrends(): array
    {
        return DB::table('disciplinary_cases as c')
            ->join('hr_people as p', 'p.user_id', '=', 'c.employee_id')
            ->leftJoin('company_unit_user_pivots as cup', 'cup.user_id', '=', 'c.employee_id')
            ->leftJoin('company_units as cu', function ($j) {
                $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', 'department');
            })
            ->selectRaw("COALESCE(cu.name, 'Unknown') as department, COUNT(*) as case_count")
            ->groupBy('department')
            ->orderByDesc('case_count')
            ->limit(10)
            ->get()
            ->toArray();
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private function buildEmployeeContext(int $employeeId): array
    {
        $cases = DisciplinaryCase::with(['category', 'status'])
            ->where('employee_id', $employeeId)
            ->orderByDesc('incident_date')
            ->get()
            ->map(fn($c) => [
                'id'            => $c->id,
                'case_number'   => $c->case_number,
                'title'         => $c->title,
                'severity'      => $c->category?->severity_level,
                'category'      => $c->category?->name,
                'status'        => $c->status?->name,
                'is_closed'     => $c->isClosed(),
                'incident_date' => $c->incident_date?->toDateString(),
                'outcome'       => $c->outcome?->name,
            ])->toArray();

        $incidents = DisciplinaryIncident::with(['category'])
            ->where('reporter_id', $employeeId)
            ->orWhereHas('accused', fn($q) => $q->where('employee_id', $employeeId))
            ->orderByDesc('incident_date')
            ->get()
            ->map(fn($i) => [
                'id'             => $i->id,
                'title'          => $i->title,
                'category'       => $i->category?->name,
                'severity_level' => $i->severity_level,
                'status'         => $i->status,
            ])->toArray();

        return compact('employeeId', 'cases', 'incidents');
    }

    private function buildCaseContext(DisciplinaryCase $case): array
    {
        $case->loadMissing(['employee', 'investigator', 'chairperson', 'category', 'status', 'outcome', 'sanction']);
        return [
            'case_number'    => $case->case_number,
            'title'          => $case->title,
            'description'    => $case->description,
            'employee'       => $case->employeeName(),
            'category'       => $case->category?->name,
            'severity'       => $case->category?->severity_level,
            'status'         => $case->status?->name,
            'incident_date'  => $case->incident_date?->toDateString(),
            'hearing_date'   => $case->hearing_date?->toDateString(),
            'policy_ref'     => $case->policy_reference,
            'outcome'        => $case->outcome?->name,
            'sanction'       => $case->sanction?->name,
            'internal_notes' => $case->internal_notes,
        ];
    }

    private function generateAiHistoryAnalysis(int $employeeId, array $context): DisciplinaryAiSummary
    {
        $prompt = "You are an HR compliance specialist. Analyse this employee's full disciplinary history " .
                  "and provide: (1) a behaviour trend assessment, (2) risk evaluation, (3) recommended HR actions. " .
                  "Return JSON with keys: {\"summary\": \"...\", \"recommendation\": \"...\"}. " .
                  "Data: " . json_encode($context);

        $response = $this->ask($prompt, 'json');
        $data = is_array($response) ? $response : ['summary' => $response, 'recommendation' => null];

        return DisciplinaryAiSummary::updateOrCreate(
            ['subject_type' => 'employee', 'subject_id' => $employeeId, 'summary_type' => 'history_analysis'],
            [
                'summary'        => $data['summary'] ?? 'Analysis not available.',
                'recommendation' => $data['recommendation'] ?? null,
                'ai_provider'    => config('ai.provider', 'claude'),
                'ai_model'       => config('ai.claude.model', 'claude-3-5-sonnet-20241022'),
                'acknowledged'   => false,
                'generated_by'   => auth()->id(),
            ]
        );
    }

    private function upsertPattern(
        int $employeeId, string $type, string $label,
        string $description, string $severity, array $data
    ): ?DisciplinaryBehaviorPattern {
        return DisciplinaryBehaviorPattern::updateOrCreate(
            ['employee_id' => $employeeId, 'pattern_type' => $type],
            [
                'pattern_label'       => $label,
                'pattern_description' => $description,
                'severity'            => $severity,
                'supporting_data'     => $data,
                'detected_at'         => now()->toDateString(),
                'acknowledged'        => false,
            ]
        );
    }

    private function ask(string $prompt, string $format = 'text'): mixed
    {
        $request = new AgentRequest(
            prompt        : $prompt,
            module        : self::MODULE,
            systemPrompt  : 'You are an expert HR and labour relations specialist with deep knowledge of South African labour law.',
            temperature   : 0.3,
            maxTokens     : 1024,
            responseFormat: $format,
        );

        $response = $this->agents->for(self::MODULE)->ask($request);

        if ($format === 'json') {
            $text = is_string($response) ? $response : ($response->content ?? '');
            // Strip markdown code fences if present
            $text = preg_replace('/^```(?:json)?\s*/i', '', trim($text));
            $text = preg_replace('/\s*```$/', '', $text);
            $decoded = json_decode($text, true);
            return $decoded ?? ['summary' => $text];
        }

        return $response;
    }
}
