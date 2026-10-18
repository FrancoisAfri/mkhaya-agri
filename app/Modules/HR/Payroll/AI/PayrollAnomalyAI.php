<?php

namespace App\Modules\HR\Payroll\AI;

use App\Core\AI\Base\BaseModuleAI;
use App\Shared\DTOs\AgentResponse;

class PayrollAnomalyAI extends BaseModuleAI
{
    protected function module(): string
    {
        return 'payroll';
    }

    /**
     * Run the anomaly firewall before a payroll is released.
     * Returns a structured risk report for human review.
     *
     * @param  array  $payrollRun  Array of employee payslip data for this run
     * @param  array  $previousRun Array of the prior month's payslip data
     */
    public function detectAnomalies(array $payrollRun, array $previousRun = []): AgentResponse
    {
        $systemPrompt = <<<'SYS'
        You are a payroll auditor AI embedded in an ERP system. Your job is to detect anomalies,
        fraud indicators, and errors in payroll data before it is released.

        Always respond in valid JSON matching this exact structure:
        {
          "risk_level": "low|medium|high|critical",
          "anomalies": [
            {
              "employee_id": "...",
              "employee_name": "...",
              "type": "salary_spike|duplicate|ghost_employee|overtime_fraud|tax_anomaly|leave_conflict|other",
              "description": "...",
              "severity": "low|medium|high",
              "recommended_action": "..."
            }
          ],
          "summary": "...",
          "safe_to_release": true|false
        }
        SYS;

        $prompt = <<<'PROMPT'
        Analyse this payroll run for anomalies. Compare against the previous run where provided.
        Flag: salary spikes > 15%, employees on unpaid leave receiving pay, duplicate employee IDs,
        unusual overtime patterns, tax deduction anomalies, and any other suspicious patterns.
        PROMPT;

        $request = $this->buildRequest(
            prompt:       $prompt,
            action:       'anomaly_detection',
            context:      ['current_run' => $payrollRun, 'previous_run' => $previousRun],
            systemPrompt: $systemPrompt,
            maxTokens:    2048,
        );

        return $this->ask($request);
    }

    /**
     * Generate a plain-language explanation of a payslip for an employee.
     */
    public function explainPayslip(array $payslip): AgentResponse
    {
        $systemPrompt = 'You are an HR assistant explaining a payslip to an employee in plain language. Be clear, friendly, and precise. Respond in 3-5 short paragraphs.';

        $prompt = 'Explain this payslip to the employee. Cover gross pay, all deductions (PAYE, UIF, pension), and the net take-home amount. Flag anything that looks unusual.';

        return $this->ask($this->buildRequest(
            prompt:       $prompt,
            action:       'payslip_explanation',
            context:      $payslip,
            systemPrompt: $systemPrompt,
        ));
    }

    /**
     * Simulate payroll cost impact of a proposed change (e.g. a salary increase).
     */
    public function simulateCostImpact(array $scenario): AgentResponse
    {
        $systemPrompt = <<<'SYS'
        You are a payroll cost analyst. Respond in valid JSON:
        {
          "current_total_cost": 0,
          "projected_total_cost": 0,
          "delta": 0,
          "delta_percentage": 0,
          "breakdown": [],
          "narrative": "..."
        }
        SYS;

        return $this->ask($this->buildRequest(
            prompt:       'Calculate the total payroll cost impact of the proposed change.',
            action:       'cost_simulation',
            context:      $scenario,
            systemPrompt: $systemPrompt,
        ));
    }
}
