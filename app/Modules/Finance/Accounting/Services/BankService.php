<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Modules\Finance\Accounting\Contracts\FinancialEvent;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinBankAccount;
use App\Modules\Finance\Accounting\Models\FinBankTransfer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BankService
{
    public function __construct(private Ledger $ledger)
    {
    }

    /**
     * Move funds between two of the company's own bank/cash accounts. Posts one
     * balanced journal — Dr destination bank, Cr source bank — plus an optional
     * bank charge (Dr fee account, Cr source bank). Reconcilable on both sides.
     */
    public function transfer(array $data): FinBankTransfer
    {
        $from = FinBankAccount::findOrFail($data['from_bank_id']);
        $to   = FinBankAccount::findOrFail($data['to_bank_id']);

        if ($from->id === $to->id) {
            throw new PostingException('Choose two different accounts to transfer between.');
        }
        $amount = round((float) $data['amount'], 2);
        if ($amount <= 0) {
            throw new PostingException('Enter an amount greater than zero.');
        }
        $fee = round((float) ($data['fee'] ?? 0), 2);
        if ($fee > 0 && empty($data['fee_account'])) {
            throw new PostingException('Choose an account for the bank charge.');
        }

        $date = $data['transfer_date'] ?? now();
        $desc = ($data['description'] ?? '') ?: "Transfer {$from->name} → {$to->name}";

        return DB::transaction(function () use ($from, $to, $amount, $fee, $date, $desc, $data) {
            $debits  = [['account' => $to->account_id, 'amount' => $amount, 'narration' => $desc]];
            $credits = [['account' => $from->account_id, 'amount' => $amount, 'narration' => $desc]];
            if ($fee > 0) {
                $debits[]  = ['account' => $data['fee_account'], 'amount' => $fee, 'narration' => 'Bank charge on transfer'];
                $credits[] = ['account' => $from->account_id, 'amount' => $fee, 'narration' => 'Bank charge on transfer'];
            }

            $transfer = FinBankTransfer::create([
                'company_id'    => $from->company_id,
                'from_bank_id'  => $from->id,
                'to_bank_id'    => $to->id,
                'amount'        => $amount,
                'fee'           => $fee,
                'transfer_date' => $date,
                'reference'     => $data['reference'] ?? null,
                'description'   => $desc,
                'created_by'    => Auth::id(),
            ]);

            $journal = $this->ledger->post(new FinancialEvent(
                sourceModule: 'bank', eventType: 'bank.transfer', debits: $debits, credits: $credits,
                eventDate: Carbon::parse($date), companyId: $from->company_id, currency: $from->currency,
                sourceRef: "bank_transfer:{$transfer->id}", description: $desc,
                idempotencyKey: 'bank_transfer:' . $transfer->id,
            ));

            $transfer->update(['journal_id' => $journal->id]);

            return $transfer;
        });
    }

    /**
     * Create a bank account. If no GL account is supplied, auto-create a postable
     * asset account under the bank range so the user never has to set up the CoA
     * side by hand.
     */
    public function create(array $data): FinBankAccount
    {
        return DB::transaction(function () use ($data) {
            if (empty($data['account_id'])) {
                $data['account_id'] = $this->autoCreateGlAccount($data['name'], $data['company_id'] ?? null, $data['type'] ?? 'bank')->id;
            }

            return FinBankAccount::create($data);
        });
    }

    public function update(FinBankAccount $bank, array $data): FinBankAccount
    {
        if (empty($data['account_id'])) {
            unset($data['account_id']); // never clear an existing link on edit
        }
        $bank->update($data);

        return $bank;
    }

    private function autoCreateGlAccount(string $name, ?int $companyId, string $type): FinAccount
    {
        // petty cash / wallet / bank all live in current assets; loans are liabilities.
        $isLiability = $type === 'loan';
        $accountType = $isLiability ? 'liability' : 'asset';
        [$low, $high] = $isLiability ? [2200, 2299] : [1100, 1199];

        $code = $this->nextCode($low, $high, $companyId);

        return FinAccount::create([
            'company_id'   => $companyId,
            'code'         => $code,
            'name'         => $name,
            'type'         => $accountType,
            'subtype'      => $isLiability ? 'current_liability' : 'current_asset',
            'is_postable'  => true,
            'is_active'    => true,
            'bank_link'    => $type === 'bank' ? 'bank' : null,
        ]);
    }

    /** Next free numeric code in [low, high]; falls back to low+1 if the band fills. */
    private function nextCode(int $low, int $high, ?int $companyId): string
    {
        $used = FinAccount::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->whereRaw("code ~ '^[0-9]+$'")
            ->whereBetween(DB::raw('code::int'), [$low, $high])
            ->pluck('code')->map(fn ($c) => (int) $c)->all();

        for ($code = $low + 1; $code <= $high; $code++) {
            if (! in_array($code, $used, true)) {
                return (string) $code;
            }
        }

        return (string) ($high); // band exhausted — reuse the top; extremely unlikely
    }
}
