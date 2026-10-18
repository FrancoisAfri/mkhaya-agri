<?php

namespace App\Modules\HR\Leave\AI;

use App\Core\AI\Base\BaseModuleAI;

/**
 * Leave-administration AI. Explains a detected leave anomaly and proposes a safe,
 * reversible remediation for HR to review before it is applied. It never invents
 * employee data and never proposes destructive ledger edits.
 */
class LeaveAI extends BaseModuleAI
{
    protected string $module = 'leave_management';

    protected function systemPrompt(): string
    {
        return <<<'SYS'
        You are a leave-administration assistant embedded in an HR/payroll ERP for South African
        companies (BCEA). You help HR understand and safely remediate leave data anomalies —
        account-setup problems (no leave profile, missing date of birth or join date, no sick-leave
        allocation) and ledger-integrity issues (missing accrual, negative balance, uncredited
        overtime). Be precise and conservative. Never invent employee data you were not given.
        SYS;
    }

    /**
     * Propose a fix for a detected anomaly. Returns an HR-reviewable, structured plan.
     *
     * @param array $anomaly {type,label,severity,description,evidence}
     * @param array $employee non-identifying context about the employee/company
     * @return array{explanation:string,what_changes:string,how:string,affected:array<int,array>,fix_kind:string,safe_to_auto_apply:bool}
     */
    public function proposeFix(array $anomaly, array $employee = []): array
    {
        $system = $this->systemPrompt() . <<<'SYS'

        Respond ONLY in strict JSON:
        {
          "explanation": "plain-language why this happened and its impact on the employee",
          "what_changes": "exactly what will change",
          "how": "how the correction is applied",
          "affected": [{"field": "...", "current": "...", "proposed": "..."}],
          "fix_kind": "ledger_correction | capture_data | run_accrual",
          "safe_to_auto_apply": true
        }
        Rules:
        - Missing employee data (no date of birth, no join date, no leave profile, no sick
          allocation) => fix_kind MUST be "capture_data"; you cannot invent the value, so set
          "proposed" to null and explain what HR must capture and where.
        - "missing_accrual" (allocations exist but no ledger movement) => fix_kind "run_accrual".
        - "negative_balance" => fix_kind "ledger_correction" (a compensating credit to bring the
          balance to zero).
        - "overtime_not_credited" => fix_kind "ledger_correction" (credit the earned annual leave).
        - Never propose deleting or editing existing ledger rows; corrections are new entries only.
        SYS;

        $data = $this->askForJson(
            prompt: 'Analyse this leave anomaly and propose a safe, reversible fix for HR to review.',
            context: ['anomaly' => $anomaly, 'employee' => $employee],
            options: ['maxTokens' => 900, 'temperature' => 0.2],
            systemPrompt: $system,
        );

        $kind = $data['fix_kind'] ?? '';

        return [
            'explanation'        => trim((string) ($data['explanation'] ?? '')),
            'what_changes'       => trim((string) ($data['what_changes'] ?? '')),
            'how'                => trim((string) ($data['how'] ?? '')),
            'affected'           => is_array($data['affected'] ?? null) ? array_values($data['affected']) : [],
            'fix_kind'           => in_array($kind, ['ledger_correction', 'capture_data', 'run_accrual'], true) ? $kind : 'capture_data',
            'safe_to_auto_apply' => (bool) ($data['safe_to_auto_apply'] ?? false),
        ];
    }
}
