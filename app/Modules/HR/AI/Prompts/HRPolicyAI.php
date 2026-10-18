<?php

namespace App\Modules\HR\AI\Prompts;

use App\Core\AI\Base\BaseModuleAI;

class HRPolicyAI extends BaseModuleAI
{
    protected string $module = 'hr';

    protected function systemPrompt(): string
    {
        return 'You are an HR policy advisor for a South African company. ' .
            'Apply BCEA, LRA, and company policy rules precisely. ' .
            'Always cite which rule or clause your answer is based on. ' .
            'If a policy is ambiguous, flag it and recommend HR review.';
    }

    public function answerPolicyQuestion(string $question, array $policyContext = []): string
    {
        $response = $this->ask($question, $policyContext);

        return $response->failed()
            ? 'Unable to retrieve policy answer at this time. Please consult HR directly.'
            : $response->content;
    }

    public function scoreLeaveRisk(array $leaveRequest, array $teamCalendar): array
    {
        $prompt = <<<PROMPT
        Analyse this leave request and assess the risk to team capacity.

        Return JSON with:
        - risk_level: low | medium | high | critical
        - risk_score: integer 0-100
        - reasons: array of strings explaining the risk
        - recommendation: string (approve / approve_with_conditions / escalate)
        - conditions: array of strings (empty if recommendation is approve)
        PROMPT;

        return $this->askForJson($prompt, [
            'leave_request' => $leaveRequest,
            'team_calendar' => $teamCalendar,
        ]);
    }
}
