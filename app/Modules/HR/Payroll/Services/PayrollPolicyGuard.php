<?php

namespace App\Modules\HR\Payroll\Services;

use App\Modules\HR\Payroll\Models\PayrollAnomaly;
use App\Modules\HR\Payroll\Models\PayrollRun;
use App\Modules\Policies\DTOs\EvaluationResultDTO;
use App\Modules\Policies\DTOs\PolicyEventDTO;
use App\Modules\Policies\Services\Enforcement\EnforcementActionHandler;
use App\Modules\Policies\Services\RuleEngine\RuleEvaluationEngine;
use Illuminate\Validation\ValidationException;

/**
 * Bridge between the Payroll module and the global Policy rule engine.
 *
 * EventRuleMap module name: 'Payroll'
 * Action patterns:
 *   payroll_run.submitted
 *   payroll_run.high_risk
 *   payroll_run.released
 */
class PayrollPolicyGuard
{
    public function __construct(
        private readonly RuleEvaluationEngine     $ruleEngine,
        private readonly EnforcementActionHandler $enforcementHandler,
    ) {}

    /**
     * Core evaluation — throws ValidationException if any rule outcome is 'block'.
     */
    public function check(PolicyEventDTO $event): array
    {
        $results = $this->ruleEngine->evaluate($event);

        $warnings           = [];
        $violationIds       = [];
        $requiresEscalation = false;

        foreach ($results as $result) {
            /** @var EvaluationResultDTO $result */
            if ($result->passed) {
                continue;
            }

            $enforced = $this->enforcementHandler->handle($result);

            if ($enforced['is_blocked'] ?? false) {
                throw ValidationException::withMessages([
                    'policy' => [$enforced['blocked_message']
                        ?? "This action is blocked by policy: {$result->rule->name}"],
                ]);
            }

            if ($enforced['requires_approval'] ?? false) {
                $requiresEscalation = true;
            }

            if (isset($enforced['violation_id'])) {
                $violationIds[] = $enforced['violation_id'];
            }

            $explanation = $enforced['explanation'] ?? null;
            if ($explanation) {
                $warnings[] = is_array($explanation)
                    ? ($explanation['summary'] ?? $result->rule->name)
                    : (string) $explanation;
            }
        }

        return [
            'warnings'            => $warnings,
            'violation_ids'       => $violationIds,
            'requires_escalation' => $requiresEscalation,
        ];
    }

    // ── Payroll run submitted ─────────────────────────────────────────────────

    public function forPayrollRunSubmitted(PayrollRun $run, int $userId): array
    {
        $anomalyCount = PayrollAnomaly::where('run_id', $run->id)
            ->where('status', 'open')
            ->count();

        return $this->check(new PolicyEventDTO(
            module:      'Payroll',
            action:      'payroll_run.submitted',
            payload:     [
                'run_id'                  => $run->id,
                'total_net'               => (float) $run->total_net,
                'employee_count'          => (int) ($run->employee_count ?? 0),
                'risk_level'              => $run->ai_risk_level ?? 'low',
                'anomaly_count'           => $anomalyCount,
                'has_terminated_employees' => PayrollAnomaly::where('run_id', $run->id)
                    ->where('anomaly_type', 'terminated_employee_paid')
                    ->where('status', 'open')
                    ->exists() ? 'true' : 'false',
            ],
            actorId:     $userId,
            subjectId:   $run->id,
            subjectType: PayrollRun::class,
        ));
    }

    // ── High-risk payroll ─────────────────────────────────────────────────────

    public function forHighRiskPayroll(PayrollRun $run, int $userId): array
    {
        $anomalyCount = PayrollAnomaly::where('run_id', $run->id)
            ->where('status', 'open')
            ->count();

        return $this->check(new PolicyEventDTO(
            module:      'Payroll',
            action:      'payroll_run.high_risk',
            payload:     [
                'run_id'         => $run->id,
                'total_net'      => (float) $run->total_net,
                'employee_count' => (int) ($run->employee_count ?? 0),
                'risk_level'     => $run->ai_risk_level ?? 'low',
                'anomaly_count'  => $anomalyCount,
            ],
            actorId:     $userId,
            subjectId:   $run->id,
            subjectType: PayrollRun::class,
        ));
    }

    // ── Payroll released ──────────────────────────────────────────────────────

    public function forPayrollReleased(PayrollRun $run, int $userId): array
    {
        $anomalyCount = PayrollAnomaly::where('run_id', $run->id)
            ->where('status', 'open')
            ->count();

        return $this->check(new PolicyEventDTO(
            module:      'Payroll',
            action:      'payroll_run.released',
            payload:     [
                'run_id'         => $run->id,
                'total_net'      => (float) $run->total_net,
                'employee_count' => (int) ($run->employee_count ?? 0),
                'risk_level'     => $run->ai_risk_level ?? 'low',
                'anomaly_count'  => $anomalyCount,
            ],
            actorId:     $userId,
            subjectId:   $run->id,
            subjectType: PayrollRun::class,
        ));
    }
}
