<?php

namespace App\Modules\HR\Leave\AI;

use App\Core\AI\Registry\AgentRegistry;
use App\Modules\HR\Leave\Models\AiUsageLog;
use App\Shared\DTOs\AgentRequest;
use Illuminate\Support\Facades\Auth;

class LeaveAdvisorAI
{
    public function __construct(
        private readonly AgentRegistry $agentRegistry,
    ) {}

    public function advise(int $hrPersonId, string $question, array $context = []): string
    {
        $start = microtime(true);

        $prompt = AgentRequest::make()
            ->withModule('leave_management')
            ->withSystemPrompt($this->systemPrompt())
            ->withPrompt($question)
            ->withContext($this->sanitizeContext($context));

        try {
            $response = $this->agentRegistry->for('leave_management')->ask($prompt);
            $answer   = $response->failed() ? 'I was unable to process your request. Please try again.' : $response->content;
        } catch (\Throwable $e) {
            $answer = 'AI advisor is temporarily unavailable. Please contact HR directly.';
            $this->logUsage($hrPersonId, $question, $answer, microtime(true) - $start, false, $e->getMessage());
            return $answer;
        }

        $this->logUsage($hrPersonId, $question, $answer, microtime(true) - $start, true);
        return $answer;
    }

    public function explainDecision(int $hrPersonId, array $requestData, string $outcome): string
    {
        $prompt = "Explain in plain language why a leave request was {$outcome}. " .
            "Leave type: {$requestData['leave_type']}. " .
            "Dates: {$requestData['start_date']} to {$requestData['end_date']}. " .
            "Reason provided: " . ($requestData['reason'] ?? 'None') . ". " .
            "Provide a clear, empathetic explanation suitable for the employee.";

        return $this->advise($hrPersonId, $prompt, $requestData);
    }

    /**
     * Predict whether a leave request is likely to be approved, based on
     * current balance, policy compliance, and historical pattern.
     */
    public function predictApprovalLikelihood(
        int    $hrPersonId,
        array  $requestData,
        array  $balanceData = [],
        array  $policyResult = [],
    ): string {
        $errors   = implode('; ', $policyResult['errors']   ?? []) ?: 'None';
        $warnings = implode('; ', $policyResult['warnings'] ?? []) ?: 'None';
        $balance  = $balanceData['balance_hours'] ?? 'Unknown';
        $requested = $requestData['working_hours'] ?? ($requestData['working_days'] ?? 0) * 8;

        $prompt = "Predict the approval likelihood for the following leave request and explain your reasoning.\n\n" .
            "Leave type: " . ($requestData['leave_type'] ?? 'Unknown') . "\n" .
            "Period: " . ($requestData['start_date'] ?? '') . " to " . ($requestData['end_date'] ?? '') . "\n" .
            "Hours requested: {$requested}\n" .
            "Current balance (hours): {$balance}\n" .
            "Policy errors: {$errors}\n" .
            "Policy warnings: {$warnings}\n\n" .
            "Respond with:\n" .
            "1. Likelihood (High / Medium / Low / Very Low)\n" .
            "2. Key factors for and against approval\n" .
            "3. What the employee can do to improve the outcome (if applicable)\n" .
            "Keep response under 150 words, plain language.";

        return $this->advise($hrPersonId, $prompt, $requestData);
    }

    /**
     * Explain an employee's leave balance in plain language,
     * including what they have left, what was used, and when it resets.
     */
    public function explainBalance(
        int    $hrPersonId,
        string $leaveType,
        float  $balanceHours,
        float  $usedHours,
        float  $allocatedHours,
        string $cycleName,
    ): string {
        $balanceDays   = round($balanceHours / 8, 1);
        $usedDays      = round($usedHours / 8, 1);
        $allocatedDays = round($allocatedHours / 8, 1);

        $prompt = "Explain the following leave balance to an employee in plain, friendly language.\n\n" .
            "Leave type: {$leaveType}\n" .
            "Leave cycle: {$cycleName}\n" .
            "Total allocated: {$allocatedDays} days ({$allocatedHours} hours)\n" .
            "Used to date: {$usedDays} days ({$usedHours} hours)\n" .
            "Remaining balance: {$balanceDays} days ({$balanceHours} hours)\n\n" .
            "Explain what these numbers mean, whether the balance is healthy, " .
            "and any important considerations for the employee. Under 100 words.";

        return $this->advise($hrPersonId, $prompt, ['leave_type' => $leaveType, 'cycle' => $cycleName]);
    }

    /**
     * Generate an HR-friendly approval/rejection summary with next steps.
     */
    public function generateHrFriendlySummary(
        int    $hrPersonId,
        array  $requestData,
        string $decision,
        array  $bceaValidation = [],
        array  $policyNotes = [],
    ): string {
        $errors   = implode('; ', $bceaValidation['errors']   ?? []) ?: 'None';
        $certReq  = ($bceaValidation['certificate_required'] ?? false) ? 'Yes' : 'No';
        $policies = implode('; ', $policyNotes) ?: 'No policy flags';

        $prompt = "You are an HR manager writing a decision summary for the leave admin system.\n\n" .
            "Decision: {$decision}\n" .
            "Leave type: " . ($requestData['leave_type'] ?? 'Unknown') . "\n" .
            "Period: " . ($requestData['start_date'] ?? '') . " to " . ($requestData['end_date'] ?? '') . "\n" .
            "BCEA issues: {$errors}\n" .
            "Certificate required: {$certReq}\n" .
            "Policy notes: {$policies}\n\n" .
            "Write a concise HR summary (under 120 words) that:\n" .
            "1. States the decision and reason\n" .
            "2. Lists any conditions or documents required\n" .
            "3. Suggests a next action for HR\n" .
            "Use professional, factual language.";

        return $this->advise($hrPersonId, $prompt, $requestData);
    }

    /**
     * Generate a workforce-level AI insight from raw data arrays.
     * Used by WorkforceIntelligenceService to interpret DB-aggregated data.
     */
    public function interpretWorkforceData(int $hrPersonId, string $insightType, array $data, string $question = ''): string
    {
        $dataJson = json_encode($data, JSON_PRETTY_PRINT);

        $prompt = "You are an HR workforce planning analyst. Analyse the following {$insightType} data " .
            "and provide actionable insights.\n\n" .
            "Data:\n{$dataJson}\n\n" .
            ($question ? "Specific question: {$question}\n\n" : '') .
            "Provide a concise analysis (under 180 words) covering:\n" .
            "1. Key finding or risk identified\n" .
            "2. Which employees or teams are most affected\n" .
            "3. Recommended action for HR or management\n" .
            "Use clear, professional language suitable for an HR dashboard.";

        return $this->advise($hrPersonId, $prompt, ['insight_type' => $insightType]);
    }

    /**
     * Generate a BCEA-specific summary for a leave request, citing the relevant
     * statutory section and entitlement rules for the leave type.
     */
    public function generateBceaSummary(int $hrPersonId, string $leaveType, string $statutoryType, array $context = []): string
    {
        $sectionMap = [
            'annual'                => 'BCEA §20 (Annual Leave — minimum 15 working days per cycle, 1 day accrual per 26 days during probation)',
            'sick'                  => 'BCEA §23 (Sick Leave — 36 days in a 3-year cycle for a 5-day week; medical certificate required after 2 consecutive days)',
            'family_responsibility' => 'BCEA §27 (Family Responsibility Leave — 3 days per annual cycle; qualifying events: birth, child illness, or death of immediate family)',
            'maternity'             => 'BCEA §25 (Maternity Leave — 4 consecutive months; unpaid; parental pool required)',
            'paternity'             => 'BCEA §25A / Van Wyk 2025 (Parental Leave — pool-allocated hours; concurrent leave rules apply)',
            'parental'              => 'BCEA §25A / Van Wyk 2025 (Parental Leave — pool-allocated hours; concurrent leave rules apply)',
        ];

        $section = $sectionMap[$statutoryType] ?? 'No specific BCEA section applies to this leave type.';

        $prompt = "Provide a concise BCEA compliance summary for a {$leaveType} leave request. " .
            "Applicable statutory provision: {$section}. " .
            "Explain the key entitlements, any qualifying conditions, and what the employee should be aware of. " .
            "Keep it under 100 words, plain language, suitable for display in an HR system.";

        return $this->advise($hrPersonId, $prompt, $this->sanitizeContext($context));
    }

    /**
     * Generate an HR-facing compliance explanation for a leave request — whether
     * it is compliant, what rules apply, and any flags HR should note.
     */
    public function generateHrComplianceExplanation(
        int    $hrPersonId,
        string $leaveType,
        string $statutoryType,
        array  $bceaValidation,
        array  $context = [],
    ): string {
        $errors   = implode('; ', $bceaValidation['errors']   ?? []) ?: 'None';
        $warnings = implode('; ', $bceaValidation['warnings'] ?? []) ?: 'None';
        $certReq  = ($bceaValidation['certificate_required'] ?? false) ? 'Yes' : 'No';

        $prompt = "You are an HR compliance officer reviewing a {$leaveType} leave request (statutory type: {$statutoryType}). " .
            "BCEA validation errors: {$errors}. " .
            "BCEA warnings: {$warnings}. " .
            "Medical certificate required: {$certReq}. " .
            "Provide a brief, professional HR compliance note (under 120 words) explaining whether this request is BCEA-compliant, " .
            "what action HR should take, and any documentation required before approval.";

        return $this->advise($hrPersonId, $prompt, $this->sanitizeContext($context));
    }

    /**
     * Interpret a specific policy clause in the context of a leave request.
     * Returns a plain-language explanation of how the clause applies.
     */
    public function interpretPolicyClause(int $hrPersonId, string $clauseTitle, string $clauseContent, array $leaveContext = []): string
    {
        $prompt = "You are an HR compliance specialist. Interpret the following policy clause in plain language " .
            "as it applies to a leave request.\n\n" .
            "Clause: \"{$clauseTitle}\"\n\n" .
            "Clause text: {$clauseContent}\n\n" .
            "Leave context: " . json_encode($leaveContext) . "\n\n" .
            "Explain:\n" .
            "1. What this clause requires or prohibits\n" .
            "2. Whether this leave request is compliant with the clause\n" .
            "3. What action the employee should take if needed\n" .
            "Keep your response under 120 words and use plain, empathetic language.";

        return $this->advise($hrPersonId, $prompt, $leaveContext);
    }

    /**
     * Analyse the policy impact of a leave request — which rules apply,
     * what they require, and what the overall compliance status is.
     */
    public function analyzePolicyImpact(
        int    $hrPersonId,
        array  $leaveRequest,
        array  $violations = [],
        array  $warnings = [],
    ): string {
        $violationCount = count($violations);
        $warningCount   = count($warnings);

        $violationText = $violationCount > 0
            ? "Violations: " . implode('; ', array_column($violations, 'rule_name'))
            : 'No violations';

        $warningText = $warningCount > 0
            ? "Warnings: " . implode('; ', $warnings)
            : 'No warnings';

        $prompt = "Analyse the policy impact of the following leave request.\n\n" .
            "Leave type: " . ($leaveRequest['leave_type'] ?? 'Unknown') . "\n" .
            "Period: " . ($leaveRequest['start_date'] ?? '') . " to " . ($leaveRequest['end_date'] ?? '') . "\n" .
            "Working days: " . ($leaveRequest['working_days'] ?? 0) . "\n\n" .
            "Policy evaluation results:\n" .
            "- {$violationText}\n" .
            "- {$warningText}\n\n" .
            "Provide a concise compliance summary (under 150 words) covering:\n" .
            "1. Overall compliance status (compliant / conditionally compliant / non-compliant)\n" .
            "2. Key policy considerations\n" .
            "3. Recommended next steps for HR\n" .
            "Use professional, factual language.";

        return $this->advise($hrPersonId, $prompt, $leaveRequest);
    }

    /**
     * Generate an AI summary of detected policy conflicts involving leave policies.
     * Helps HR quickly understand what conflicts exist and how to resolve them.
     */
    public function summarizeConflicts(int $hrPersonId, array $conflicts): string
    {
        if (empty($conflicts)) {
            return 'No policy conflicts detected for leave-related policies.';
        }

        $conflictList = collect($conflicts)
            ->take(5)
            ->map(fn($c) => "- [{$c['type']}] {$c['policy_a']} vs {$c['policy_b']}: {$c['description']}")
            ->implode("\n");

        $total = count($conflicts);

        $prompt = "You are an HR policy governance advisor. Summarise the following {$total} policy conflict(s) " .
            "affecting leave management. For each conflict explain the risk and suggest a resolution.\n\n" .
            "Conflicts:\n{$conflictList}\n\n" .
            "Provide a structured summary (under 200 words) with:\n" .
            "1. Most critical conflict and why\n" .
            "2. Common root cause if patterns exist\n" .
            "3. Recommended resolution priority\n" .
            "Use clear, actionable language suitable for an HR governance meeting.";

        return $this->advise($hrPersonId, $prompt, ['conflict_count' => $total]);
    }

    private function systemPrompt(): string
    {
        return <<<PROMPT
You are an expert HR Leave Management Advisor for a South African company.
You have deep knowledge of:
- BCEA Act 75 of 1997 (Basic Conditions of Employment Act)
- Van Wyk Constitutional Court 2025 ruling on parental leave
- South African public holidays and leave entitlements
- Company leave policies and best practices

Always provide accurate, empathetic guidance. When citing BCEA, reference the specific section.
Never share other employees' private information. Keep responses concise and practical.
If you are unsure about a specific company policy, recommend the employee speaks with HR.
PROMPT;
    }

    private function sanitizeContext(array $context): array
    {
        // Remove PII before sending to AI
        $safe = $context;
        unset($safe['id_number'], $safe['personal_email'], $safe['bank_account'], $safe['medical_aid']);
        return $safe;
    }

    private function logUsage(int $hrPersonId, string $input, string $output, float $latency, bool $success, ?string $error = null): void
    {
        AiUsageLog::create([
            'user_id'       => Auth::id(),
            'employee_id'   => $hrPersonId ?: null,
            'feature'       => 'leave_advisor',
            'input_summary' => mb_substr($input, 0, 500),
            'output_summary' => mb_substr($output, 0, 500),
            'latency_ms'    => (int) ($latency * 1000),
            'success'       => $success,
            'error_message' => $error,
            'pii_tokenized' => true,
            'created_at'    => now(),
        ]);
    }
}
