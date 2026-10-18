<?php

namespace App\Modules\Finance\StaffLoan\Services;

use App\Modules\Finance\StaffLoan\Models\SalaryAdvance;
use App\Modules\Finance\StaffLoan\Models\SalaryAdvanceRepayment;
use App\Modules\Finance\StaffLoan\Models\StaffLoan;
use App\Modules\Finance\StaffLoan\Models\StaffLoanAffordabilityCheck;
use App\Modules\Finance\StaffLoan\Models\StaffLoanApproval;
use App\Modules\Finance\StaffLoan\Models\StaffLoanRepayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class StaffLoanService
{
    // ── Loan ──────────────────────────────────────────────────────────────────

    public function createStaffLoanRequest(array $data, int $actorId): StaffLoan
    {
        return DB::transaction(function () use ($data, $actorId) {
            $loan = StaffLoan::create([
                'employee_id'       => $actorId,
                'loan_type_id'      => $data['loan_type_id'] ?? null,
                'amount_requested'  => $data['amount_requested'],
                'term_months'       => $data['term_months'],
                'purpose'           => $data['purpose'],
                'employment_status' => $data['employment_status'] ?? null,
                'is_permanent'      => $data['is_permanent'] ?? false,
                'probation_completed'=> $data['probation_completed'] ?? false,
                'is_suspended'      => $data['is_suspended'] ?? false,
                'monthly_net_pay'   => $data['monthly_net_pay'] ?? null,
                'notes'             => $data['notes'] ?? null,
                'status'            => 'pending',
            ]);

            if (isset($data['monthly_net_pay']) && $data['monthly_net_pay'] > 0) {
                $this->runAffordabilityCheck($loan, $data['monthly_net_pay']);
            }

            return $loan;
        });
    }

    public function approveLoanRequest(StaffLoan $loan, int $approverId, string $level, ?string $comments = null): StaffLoan
    {
        return DB::transaction(function () use ($loan, $approverId, $level, $comments) {
            $loan->update([
                'status'      => $level === 'finance' ? 'approved' : 'under_review',
                'approved_by' => $level === 'finance' ? $approverId : $loan->approved_by,
                'approved_at' => $level === 'finance' ? now() : $loan->approved_at,
            ]);

            StaffLoanApproval::create([
                'loan_id'        => $loan->id,
                'approver_id'    => $approverId,
                'approval_level' => $level,
                'action'         => 'approved',
                'comments'       => $comments,
                'acted_at'       => now(),
            ]);

            return $loan->fresh();
        });
    }

    public function rejectLoanRequest(StaffLoan $loan, int $rejectorId, string $reason, string $level = 'finance'): StaffLoan
    {
        return DB::transaction(function () use ($loan, $rejectorId, $reason, $level) {
            $loan->update([
                'status'           => 'rejected',
                'rejected_by'      => $rejectorId,
                'rejected_at'      => now(),
                'rejection_reason' => $reason,
            ]);

            StaffLoanApproval::create([
                'loan_id'        => $loan->id,
                'approver_id'    => $rejectorId,
                'approval_level' => $level,
                'action'         => 'rejected',
                'comments'       => $reason,
                'acted_at'       => now(),
            ]);

            return $loan->fresh();
        });
    }

    public function disburseLoan(StaffLoan $loan, float $amountApproved, int $actorId): StaffLoan
    {
        return DB::transaction(function () use ($loan, $amountApproved, $actorId) {
            $monthlyInstallment = $this->calculateLoanRepayment(
                $amountApproved,
                (float) $loan->interest_rate,
                $loan->term_months,
                $loan->loanType?->interest_type ?? 'none'
            );

            $loan->update([
                'status'             => 'active',
                'amount_approved'    => $amountApproved,
                'outstanding_balance'=> $amountApproved,
                'monthly_installment'=> $monthlyInstallment,
                'disbursed_at'       => now(),
                'next_payment_date'  => now()->addMonth()->startOfMonth(),
            ]);

            $this->generateRepaymentSchedule($loan->fresh());

            // Post the disbursement to the GL (Phase 5.6 ledger bridge). Guarded so
            // a ledger issue can never roll back the operational disbursement.
            try {
                app(\App\Modules\Finance\Accounting\Services\StaffLoanLedgerService::class)
                    ->disburse($loan->id, $amountApproved, $loan->company_id ?? null);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Staff loan GL post (disburse) skipped', ['loan' => $loan->id, 'error' => $e->getMessage()]);
            }

            return $loan->fresh();
        });
    }

    public function closeLoanAccount(StaffLoan $loan, int $actorId): StaffLoan
    {
        $loan->update([
            'status'            => 'closed',
            'outstanding_balance'=> 0,
            'closed_at'         => now(),
        ]);

        return $loan->fresh();
    }

    public function recordRepayment(StaffLoan $loan, float $amount, int $actorId, string $method = 'payroll'): StaffLoanRepayment
    {
        return DB::transaction(function () use ($loan, $amount, $actorId, $method) {
            $nextDue = $loan->repayments()
                ->whereIn('status', ['pending', 'overdue', 'partial'])
                ->orderBy('installment_number')
                ->first();

            if (!$nextDue) {
                throw new \RuntimeException('No pending repayment found for this loan.');
            }

            $balanceBefore = (float) $loan->outstanding_balance;
            $balanceAfter  = max(0, $balanceBefore - $amount);

            $nextDue->update([
                'amount_paid'      => (float) $nextDue->amount_paid + $amount,
                'balance_before'   => $balanceBefore,
                'balance_after'    => $balanceAfter,
                'status'           => $amount >= (float) $nextDue->amount_due ? 'paid' : 'partial',
                'paid_at'          => now(),
                'payment_method'   => $method,
                'payroll_deducted' => $method === 'payroll',
            ]);

            $newBalance = max(0, (float) $loan->outstanding_balance - $amount);
            $loan->update([
                'outstanding_balance' => $newBalance,
                'status'              => $newBalance <= 0 ? 'closed' : 'active',
                'closed_at'           => $newBalance <= 0 ? now() : null,
            ]);

            if ($newBalance <= 0) {
                $loan->update(['next_payment_date' => null]);
            } else {
                $nextPending = $loan->repayments()->where('status', 'pending')->orderBy('due_date')->first();
                if ($nextPending) {
                    $loan->update(['next_payment_date' => $nextPending->due_date]);
                }
            }

            // Mirror the repayment into the GL (Phase 5.6). Guarded — never breaks payroll.
            try {
                app(\App\Modules\Finance\Accounting\Services\StaffLoanLedgerService::class)
                    ->repay($nextDue->id, $loan->id, $amount, $loan->company_id ?? null);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Staff loan GL post (repay) skipped', ['loan' => $loan->id, 'error' => $e->getMessage()]);
            }

            return $nextDue->fresh();
        });
    }

    public function deductRepaymentFromPayroll(StaffLoan $loan, int $payrollPeriodId): array
    {
        // Stub: creates a payroll deduction entry when payroll module is integrated.
        // Returns the deduction details for payroll processing.
        return [
            'loan_id'          => $loan->id,
            'reference_number' => $loan->reference_number,
            'employee_id'      => $loan->employee_id,
            'amount'           => $loan->monthly_installment,
            'payroll_period_id'=> $payrollPeriodId,
            'status'           => 'queued',
            'queued_at'        => now()->toISOString(),
        ];
    }

    // ── Advance ───────────────────────────────────────────────────────────────

 public function createSalaryAdvanceRequest(array $data, int $actorId): SalaryAdvance
{
    return DB::transaction(function () use ($data, $actorId) {
        $monthlyNetPay = $data['monthly_net_pay'] ?? null;
        $pctOfNet      = $monthlyNetPay > 0
            ? min(round(($data['amount_requested'] / $monthlyNetPay) * 100, 2), 999.99)
            : null;

        $advance = SalaryAdvance::create([
            'employee_id'         => $actorId,
            'amount_requested'    => $data['amount_requested'],
            'monthly_net_pay'     => $monthlyNetPay,
            'percentage_of_net'   => $pctOfNet,
            'repayment_month'     => $data['repayment_month'],
            'reason'              => $data['reason'],
            'employment_status'   => $data['employment_status'] ?? null,
            'is_permanent'        => $data['is_permanent'] ?? false,
            'probation_completed' => $data['probation_completed'] ?? false,
            'is_suspended'        => $data['is_suspended'] ?? false,
            'notes'               => $data['notes'] ?? null,
            'status'              => 'pending',
        ]);

        if ($monthlyNetPay > 0) {
            $this->runAdvanceAffordabilityCheck($advance, $monthlyNetPay);
        }

        return $advance;
    });
}

    public function approveAdvance(SalaryAdvance $advance, int $approverId, ?string $comments = null): SalaryAdvance
    {
        $advance->update([
            'status'         => 'approved',
            'approved_by'    => $approverId,
            'approved_at'    => now(),
            'amount_approved'=> $advance->amount_requested,
        ]);

        return $advance->fresh();
    }

    public function rejectAdvance(SalaryAdvance $advance, int $rejectorId, string $reason): SalaryAdvance
    {
        $advance->update([
            'status'           => 'rejected',
            'rejection_reason' => $reason,
        ]);

        return $advance->fresh();
    }

    public function disburseAdvance(SalaryAdvance $advance, int $actorId): SalaryAdvance
    {
        return DB::transaction(function () use ($advance, $actorId) {
            $advance->update([
                'status'       => 'disbursed',
                'disbursed_at' => now(),
            ]);

            SalaryAdvanceRepayment::create([
                'advance_id'  => $advance->id,
                'due_date'    => $advance->repayment_month->endOfMonth(),
                'amount_due'  => $advance->amount_approved ?? $advance->amount_requested,
                'status'      => 'pending',
            ]);

            return $advance->fresh();
        });
    }

    public function settleAdvance(SalaryAdvance $advance, int $actorId): SalaryAdvance
    {
        $advance->update([
            'status'      => 'settled',
            'settled_at'  => now(),
        ]);

        $advance->repayments()->where('status', 'pending')->update([
            'status'  => 'paid',
            'paid_at' => now(),
        ]);

        return $advance->fresh();
    }

    // ── Calculations ──────────────────────────────────────────────────────────

    public function calculateLoanRepayment(float $principal, float $annualRate, int $termMonths, string $interestType = 'none'): float
    {
        if ($interestType === 'none' || $annualRate <= 0) {
            return round($principal / $termMonths, 2);
        }

        $monthlyRate = ($annualRate / 100) / 12;

        if ($interestType === 'fixed') {
            $totalInterest = $principal * ($annualRate / 100) * ($termMonths / 12);
            return round(($principal + $totalInterest) / $termMonths, 2);
        }

        // Reducing balance: standard amortisation formula
        return round(
            $principal * ($monthlyRate * pow(1 + $monthlyRate, $termMonths))
            / (pow(1 + $monthlyRate, $termMonths) - 1),
            2
        );
    }

    public function generateRepaymentSchedule(StaffLoan $loan): void
    {
        $principal    = (float) $loan->amount_approved;
        $annualRate   = (float) $loan->interest_rate;
        $months       = $loan->term_months;
        $interestType = $loan->loanType?->interest_type ?? 'none';
        $monthlyRate  = $annualRate > 0 ? ($annualRate / 100) / 12 : 0;
        $installment  = (float) $loan->monthly_installment;
        $balance      = $principal;
        $startDate    = now()->addMonth()->startOfMonth();

        for ($i = 1; $i <= $months; $i++) {
            if ($interestType === 'reducing_balance' && $monthlyRate > 0) {
                $interestComponent  = round($balance * $monthlyRate, 2);
                $principalComponent = round($installment - $interestComponent, 2);
            } elseif ($interestType === 'fixed' && $monthlyRate > 0) {
                $interestComponent  = round(($principal * ($annualRate / 100) * ($months / 12)) / $months, 2);
                $principalComponent = round($installment - $interestComponent, 2);
            } else {
                $principalComponent = $installment;
                $interestComponent  = 0;
            }

            $balanceBefore = $balance;
            $balance       = max(0, round($balance - $principalComponent, 2));

            StaffLoanRepayment::create([
                'loan_id'              => $loan->id,
                'installment_number'   => $i,
                'due_date'             => $startDate->copy()->addMonths($i - 1)->endOfMonth(),
                'amount_due'           => $installment,
                'principal_component'  => $principalComponent,
                'interest_component'   => $interestComponent,
                'balance_before'       => $balanceBefore,
                'balance_after'        => $balance,
                'status'               => 'pending',
            ]);
        }
    }

    // ── Affordability ─────────────────────────────────────────────────────────

    public function runAffordabilityCheck(StaffLoan $loan, float $monthlyNetPay): StaffLoanAffordabilityCheck
    {
        $existingLoanDeductions = StaffLoan::where('employee_id', $loan->employee_id)
            ->where('status', 'active')
            ->where('id', '!=', $loan->id)
            ->sum('monthly_installment');

        $activeAdvanceAmount = \App\Modules\Finance\StaffLoan\Models\SalaryAdvance::where('employee_id', $loan->employee_id)
            ->whereIn('status', ['approved', 'disbursed'])
            ->count() > 0 ? ($monthlyNetPay * 0.1) : 0;

        $existingDeductions = (float) $existingLoanDeductions + $activeAdvanceAmount;
        $proposedDeduction  = (float) ($loan->monthly_installment ?? $this->calculateLoanRepayment(
            (float) $loan->amount_requested,
            (float) $loan->interest_rate,
            $loan->term_months,
            $loan->loanType?->interest_type ?? 'none'
        ));

        $totalAfter         = $existingDeductions + $proposedDeduction;
        $ratio              = $monthlyNetPay > 0 ? $totalAfter / $monthlyNetPay : 1;
        $disposable         = $monthlyNetPay - $totalAfter;
        $passed             = $ratio <= 0.25;
        $ratioPct           = round($ratio * 100, 1);

        return StaffLoanAffordabilityCheck::create([
            'loan_id'               => $loan->id,
            'employee_id'           => $loan->employee_id,
            'monthly_net_pay'       => $monthlyNetPay,
            'existing_deductions'   => $existingDeductions,
            'proposed_deduction'    => $proposedDeduction,
            'total_deductions_after'=> $totalAfter,
            'affordability_ratio'   => $ratio,
            'disposable_income'     => $disposable,
            'passed'                => $passed,
            'failure_reason'        => $passed ? null : "Total deductions ({$ratioPct}%) exceed the 25% net pay threshold.",
            'checked_at'            => now(),
        ]);
    }

    public function runAdvanceAffordabilityCheck(SalaryAdvance $advance, float $monthlyNetPay): StaffLoanAffordabilityCheck
    {
        $existingDeductions = StaffLoan::where('employee_id', $advance->employee_id)
            ->where('status', 'active')
            ->sum('monthly_installment');

        $proposedDeduction  = (float) $advance->amount_requested;
        $totalAfter         = (float) $existingDeductions + $proposedDeduction;
        $ratio              = $monthlyNetPay > 0 ? ($proposedDeduction / $monthlyNetPay) : 1;
        $disposable         = $monthlyNetPay - $totalAfter;
        $passed             = $ratio <= 0.30;
        $ratioPct           = round($ratio * 100, 1);

        return StaffLoanAffordabilityCheck::create([
            'advance_id'            => $advance->id,
            'employee_id'           => $advance->employee_id,
            'monthly_net_pay'       => $monthlyNetPay,
            'existing_deductions'   => (float) $existingDeductions,
            'proposed_deduction'    => $proposedDeduction,
            'total_deductions_after'=> $totalAfter,
            'affordability_ratio'   => $ratio,
            'disposable_income'     => $disposable,
            'passed'                => $passed,
            'failure_reason'        => $passed ? null : "Advance ({$ratioPct}% of net pay) exceeds the 30% salary advance limit.",
            'checked_at'            => now(),
        ]);
    }

    // ── Overdue sync ──────────────────────────────────────────────────────────

    public function markOverdueInstallments(): int
    {
        return StaffLoanRepayment::where('status', 'pending')
            ->where('due_date', '<', now()->toDateString())
            ->update(['status' => 'overdue']);
    }
}
