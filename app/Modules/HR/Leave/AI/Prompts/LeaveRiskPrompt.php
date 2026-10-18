<?php

namespace App\Modules\HR\Leave\AI\Prompts;

use App\Core\AI\Base\BaseModuleAI;
use InvalidArgumentException;
use RuntimeException;

class LeaveRiskPrompt extends BaseModuleAI
{
    protected function module(): string
    {
        return 'leave_risk';
    }

    protected function systemPrompt(): string
    {
        return 'You are an HR operations analyst. Apply South African BCEA leave rules: ' .
            'employees are entitled to 21 consecutive days of annual leave per year, ' .
            'leave must be requested at least 30 days in advance where possible. ' .
            'Be conservative — err toward flagging risk rather than missing it. ' .
            'Base all assessments strictly on the data provided.';
    }

    /**
     * Assess the risk of a leave request.
     *
     * @param array $leaveRequest    Must contain employee_id, start_date, end_date, type
     * @param array $teamCalendar    Array of upcoming team schedules (next 30 days)
     * @param array $employeeHistory Employee leave history for last 90 days
     * @param int   $maxTokens       Maximum tokens for the AI response
     *
     * @return array {
     *     risk_level: string,
     *     risk_score: int,
     *     reasons: string[],
     *     recommendation: string,
     *     conditions: string[],
     *     coverage_needed: bool
     * }
     */
    public function assess(array $leaveRequest, array $teamCalendar, array $employeeHistory = [], int $maxTokens = 2048): array
    {
        $this->validateLeaveRequest($leaveRequest);
        $this->validateTeamCalendar($teamCalendar);
        $employeeHistory = $this->truncateHistory($employeeHistory, 10); // keep only recent entries

        $prompt = $this->buildPrompt($leaveRequest, $teamCalendar, $employeeHistory);

        try {
            return $this->askForJson(
                prompt: $prompt,
                context: [], // all data is already embedded in the prompt
                options: ['maxTokens' => $maxTokens]
            );
        } catch (RuntimeException $e) {
            // Log error here
            return [
                'risk_level'       => 'critical',
                'risk_score'       => 100,
                'reasons'          => ['AI service error: ' . $e->getMessage()],
                'recommendation'   => 'escalate',
                'conditions'       => [],
                'coverage_needed'  => true,
            ];
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function buildPrompt(array $leaveRequest, array $teamCalendar, array $employeeHistory): string
    {
        return <<<PROMPT
        You are assessing a leave request for operational risk.

        Leave Request:
        {$this->formatContext($leaveRequest)}

        Team Calendar (next 30 days):
        {$this->formatContext($teamCalendar)}

        Employee Leave History (90 days):
        {$this->formatContext($employeeHistory)}

        Assess the risk this leave creates for team capacity and operations.

        Return a JSON object with exactly these fields:
        - risk_level       : "low" | "medium" | "high" | "critical"
        - risk_score       : integer 0–100
        - reasons          : array of strings — specific reasons for the risk level
        - recommendation   : "approve" | "approve_with_conditions" | "escalate"
        - conditions       : array of strings — empty if recommendation is "approve"
        - coverage_needed  : boolean — true if a replacement must be arranged

        Return only valid JSON.
        PROMPT;
    }

    private function formatContext(array $data): string
    {
        // Compact JSON to save tokens
        return json_encode($data, JSON_UNESCAPED_SLASHES);
    }

    private function validateLeaveRequest(array $data): void
    {
        $required = ['employee_id', 'start_date', 'end_date', 'type'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new InvalidArgumentException("Leave request missing required field: $field");
            }
        }
    }

    private function validateTeamCalendar(array $data): void
    {
        // Basic check: if it's an array, assume it's fine; you can add more validation as needed
        if (!is_array($data)) {
            throw new InvalidArgumentException('Team calendar must be an array.');
        }
    }

    private function truncateHistory(array $history, int $limit): array
    {
        // Keep only the most recent $limit entries (assuming they are ordered by date descending)
        return array_slice($history, -$limit);
    }
}
