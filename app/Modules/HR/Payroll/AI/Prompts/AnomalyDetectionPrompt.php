<?php

namespace App\Modules\HR\Payroll\AI\Prompts;

use App\Core\AI\Base\BaseModuleAI;
use App\Core\AI\Registry\AgentRegistry;

class AnomalyDetectionPrompt extends BaseModuleAI
{
    // Define the module name for the AI registry
    protected string $module = 'payroll_anomaly';

    public function __construct(
        AgentRegistry $registry,
        private readonly array $currentRun,
        private readonly array $sixMonthBaseline,
        private readonly array $recentEdits = [],
    ) {
        parent::__construct($registry);
    }

    /**
     * Render the prompt text for the AI.
     */
    public function render(): string
    {
        return <<<PROMPT
        You are auditing a payroll run before it is released to employees.

        {$this->contextBlock('Current Payroll Run', $this->currentRun)}

        {$this->contextBlock('6-Month Baseline (averages and ranges)', $this->sixMonthBaseline)}

        {$this->contextBlock('Edits Made in Last 48 Hours', $this->recentEdits)}

        Identify anomalies, inconsistencies, or fraud indicators.

        For each anomaly found return a JSON array where each item has:
        - employee_id     : string or null if company-wide
        - anomaly_type    : string — e.g. "salary_spike", "duplicate_entry", "late_edit"
        - severity        : "low" | "medium" | "high" | "critical"
        - current_value   : the value that triggered the flag
        - expected_range  : what was expected based on baseline
        - explanation     : plain English explanation a payroll manager can act on
        - recommended_action : string — what the reviewer should do

        If no anomalies are found return an empty array.
        {$this->jsonInstruction()}
        PROMPT;
    }

    /**
     * System prompt to define AI persona.
     */
    public function systemPrompt(): string
    {
        return 'You are a payroll fraud and compliance analyst. ' .
            'You have deep knowledge of payroll manipulation patterns. ' .
            'Flag anything that deviates from baseline by more than 15% without explanation. ' .
            'Be precise — every flag must reference specific numbers from the data provided.';
    }

    /**
     * Specify that we expect JSON output.
     */
    public function responseFormat(): string
    {
        return 'json';
    }

    /**
     * Run the anomaly detection and return decoded JSON.
     */
    public function detect(): array
    {
        return $this->askForJson($this->render());
    }

    /**
     * Helper to format a data block for the prompt.
     */
    private function contextBlock(string $title, array $data): string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return $title . ":\n" . $json . "\n";
    }

    /**
     * Instruction to the AI to return JSON output.
     */
    private function jsonInstruction(): string
    {
        return "Return only valid JSON. Do not include any explanations outside of the JSON structure.";
    }
}
