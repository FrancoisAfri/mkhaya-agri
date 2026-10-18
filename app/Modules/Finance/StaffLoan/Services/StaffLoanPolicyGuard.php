<?php

namespace App\Modules\Finance\StaffLoan\Services;

use App\Modules\Finance\StaffLoan\Models\SalaryAdvance;
use App\Modules\Finance\StaffLoan\Models\StaffLoan;
use App\Modules\Policies\DTOs\EvaluationResultDTO;
use App\Modules\Policies\DTOs\PolicyEventDTO;
use App\Modules\Policies\Services\Enforcement\EnforcementActionHandler;
use App\Modules\Policies\Services\RuleEngine\RuleEvaluationEngine;
use Illuminate\Validation\ValidationException;

/**
 * Bridge between the Staff Loan module and the global Policy rule engine.
 *
 * EventRuleMap module name: 'StaffLoan'
 * Action patterns:
 *   staff_loan.request.submitted
 *   staff_loan.request.approved
 *   staff_loan.advance.submitted
 *   staff_loan.repayment.overdue
 */
class StaffLoanPolicyGuard
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

    // ── Loan submitted ────────────────────────────────────────────────────────

    public function forLoanSubmitted(StaffLoan $loan, int $actorId): array
    {
        $activeLoans = StaffLoan::where('employee_id', $loan->employee_id)
            ->where('status', 'active')
            ->where('id', '!=', $loan->id)
            ->count();

        $repaymentAffordabilityRatio = 0;
        if ($loan->monthly_net_pay > 0 && $loan->monthly_installment) {
            $existingDeductions = StaffLoan::where('employee_id', $loan->employee_id)
                ->where('status', 'active')
                ->where('id', '!=', $loan->id)
                ->sum('monthly_installment');
            $repaymentAffordabilityRatio = ((float) $existingDeductions + (float) $loan->monthly_installment) / (float) $loan->monthly_net_pay;
        }

        return $this->check(new PolicyEventDTO(
            module:      'StaffLoan',
            action:      'staff_loan.request.submitted',
            payload:     [
                'loan_id'                      => $loan->id,
                'employee_id'                  => $loan->employee_id,
                'amount_requested'             => (float) $loan->amount_requested,
                'is_permanent'                 => $loan->is_permanent,
                'probation_completed'          => $loan->probation_completed,
                'is_suspended'                 => $loan->is_suspended,
                'active_loan_count'            => $activeLoans,
                'repayment_affordability_ratio'=> round($repaymentAffordabilityRatio * 100, 2),
                'requires_executive_approval'  => (float) $loan->amount_requested > 20000,
            ],
            actorId:     $actorId,
            subjectId:   $loan->id,
            subjectType: StaffLoan::class,
        ));
    }

    // ── Advance submitted ─────────────────────────────────────────────────────

    public function forAdvanceSubmitted(SalaryAdvance $advance, int $actorId): array
    {
        $activeAdvanceCount = SalaryAdvance::where('employee_id', $advance->employee_id)
            ->whereIn('status', ['approved', 'disbursed'])
            ->where('id', '!=', $advance->id)
            ->count();

        $percentageOfNet = (float) ($advance->percentage_of_net ?? $advance->calculatePercentageOfNet());

        return $this->check(new PolicyEventDTO(
            module:      'StaffLoan',
            action:      'staff_loan.advance.submitted',
            payload:     [
                'advance_id'          => $advance->id,
                'employee_id'         => $advance->employee_id,
                'amount_requested'    => (float) $advance->amount_requested,
                'is_permanent'        => $advance->is_permanent,
                'probation_completed' => $advance->probation_completed,
                'is_suspended'        => $advance->is_suspended,
                'percentage_of_net'   => $percentageOfNet,
                'active_advance_count'=> $activeAdvanceCount,
            ],
            actorId:     $actorId,
            subjectId:   $advance->id,
            subjectType: SalaryAdvance::class,
        ));
    }

    // ── Repayment overdue ─────────────────────────────────────────────────────

    public function forRepaymentOverdue(StaffLoan $loan, int $overdueCount, int $actorId): array
    {
        return $this->check(new PolicyEventDTO(
            module:      'StaffLoan',
            action:      'staff_loan.repayment.overdue',
            payload:     [
                'loan_id'       => $loan->id,
                'employee_id'   => $loan->employee_id,
                'overdue_count' => $overdueCount,
                'outstanding'   => (float) $loan->outstanding_balance,
            ],
            actorId:     $actorId,
            subjectId:   $loan->id,
            subjectType: StaffLoan::class,
        ));
    }
}
