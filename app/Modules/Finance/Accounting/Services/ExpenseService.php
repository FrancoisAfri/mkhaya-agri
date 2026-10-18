<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Modules\Finance\Accounting\Contracts\FinancialEvent;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinExpense;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Expense engine (Phase 5.5). Captures an expense or mileage claim and posts it
 * to the ledger on approval: Dr expense (+ VAT input) / Cr the funding side.
 */
class ExpenseService
{
    public function __construct(private Ledger $ledger)
    {
    }

    public function create(array $data): FinExpense
    {
        if (! empty($data['is_mileage'])) {
            $data['amount'] = round((float) ($data['km'] ?? 0) * (float) ($data['rate'] ?? 0), 2);
            $data['tax_amount'] = 0;
        }
        $data['total'] = round((float) ($data['amount'] ?? 0) + (float) ($data['tax_amount'] ?? 0), 2);
        $data['created_by'] = Auth::id();

        return FinExpense::create($data);
    }

    /** Approve and post the expense. */
    public function approve(FinExpense $expense): FinExpense
    {
        if ($expense->status === 'posted') {
            throw new PostingException('This expense is already posted.');
        }
        $amount = round((float) $expense->amount, 2);
        $tax    = round((float) $expense->tax_amount, 2);
        $total  = round($amount + $tax, 2);
        if ($total <= 0) {
            throw new PostingException('Nothing to post — the expense total is zero.');
        }

        $contra = $expense->contra_code ?: match ($expense->payment_method) {
            'petty_cash'   => '1150',
            'company_card' => config('finance.default_bank'),
            default        => config('finance.ap_control'), // reimbursable — owed to employee
        };
        $label = $expense->description ?: ($expense->category ?: 'Expense');

        return DB::transaction(function () use ($expense, $amount, $tax, $total, $contra, $label) {
            $debits = [['account' => $expense->expense_account_id, 'amount' => $amount, 'narration' => $label]];
            if ($tax > 0) {
                $debits[] = ['account' => config('finance.vat_input'), 'amount' => $tax, 'narration' => 'VAT input'];
            }

            $journal = $this->ledger->post(new FinancialEvent(
                sourceModule: 'expenses', eventType: 'expense.posted', debits: $debits,
                credits: [['account' => $contra, 'amount' => $total, 'narration' => $label]],
                eventDate: Carbon::parse($expense->expense_date), companyId: $expense->company_id,
                sourceRef: "expense:{$expense->id}", description: "Expense — {$label}",
                idempotencyKey: "expense:{$expense->id}",
            ));

            $expense->update(['status' => 'posted', 'journal_id' => $journal->id, 'approved_by' => Auth::id()]);

            return $expense;
        });
    }

    public function reject(FinExpense $expense): FinExpense
    {
        if ($expense->status === 'posted') {
            throw new PostingException('A posted expense cannot be rejected — reverse the journal instead.');
        }
        $expense->update(['status' => 'rejected']);

        return $expense;
    }
}
