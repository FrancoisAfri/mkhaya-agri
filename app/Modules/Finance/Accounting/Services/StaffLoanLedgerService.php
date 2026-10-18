<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Modules\Finance\Accounting\Contracts\FinancialEvent;
use App\Modules\Finance\Accounting\Models\FinAccount;
use Illuminate\Support\Carbon;

/**
 * Bridges the (existing) Staff Loan module into the shared ledger (Phase 5.6) so
 * disbursements and repayments hit the GL and appear in reports without special
 * casing. No-ops safely if the staff-loan receivable account isn't in the chart,
 * so it can never break the operational loan flow.
 */
class StaffLoanLedgerService
{
    public function __construct(private Ledger $ledger)
    {
    }

    private function receivableCode(): ?string
    {
        $code = config('finance.staff_loan_receivable');

        return FinAccount::where('code', $code)->where('is_postable', true)->exists() ? $code : null;
    }

    /** Disbursement: Dr Staff Loans Receivable / Cr Bank. Returns the journal id or null. */
    public function disburse(int $loanId, float $amount, ?int $companyId = null, ?string $date = null, ?string $bankContra = null): ?int
    {
        $receivable = $this->receivableCode();
        if (! $receivable || $amount <= 0) {
            return null;
        }

        $journal = $this->ledger->post(new FinancialEvent(
            sourceModule: 'staff_loans', eventType: 'loan.disbursed',
            debits:  [['account' => $receivable, 'amount' => round($amount, 2), 'narration' => "Staff loan #{$loanId} disbursed"]],
            credits: [['account' => $bankContra ?: config('finance.default_bank'), 'amount' => round($amount, 2), 'narration' => "Staff loan #{$loanId} disbursed"]],
            eventDate: Carbon::parse($date ?? now()), companyId: $companyId,
            sourceRef: "staff_loan:{$loanId}", description: "Staff loan #{$loanId} disbursement",
            idempotencyKey: "staff_loan_disburse:{$loanId}",
        ));

        return $journal->id;
    }

    /** Repayment: Dr Bank (or payroll clearing) / Cr Staff Loans Receivable. */
    public function repay(int $repaymentId, int $loanId, float $amount, ?int $companyId = null, ?string $date = null, ?string $contra = null): ?int
    {
        $receivable = $this->receivableCode();
        if (! $receivable || $amount <= 0) {
            return null;
        }

        $journal = $this->ledger->post(new FinancialEvent(
            sourceModule: 'staff_loans', eventType: 'loan.repaid',
            debits:  [['account' => $contra ?: config('finance.default_bank'), 'amount' => round($amount, 2), 'narration' => "Staff loan #{$loanId} repayment"]],
            credits: [['account' => $receivable, 'amount' => round($amount, 2), 'narration' => "Staff loan #{$loanId} repayment"]],
            eventDate: Carbon::parse($date ?? now()), companyId: $companyId,
            sourceRef: "staff_loan:{$loanId}", description: "Staff loan #{$loanId} repayment",
            idempotencyKey: "staff_loan_repay:{$repaymentId}",
        ));

        return $journal->id;
    }
}
