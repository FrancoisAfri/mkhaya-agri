<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinNumberSeries;
use App\Modules\Finance\Accounting\Models\FinPaymentBatch;
use App\Modules\Finance\Accounting\Models\FinSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Supplier payment run with maker-checker control. A batch is created (maker),
 * approved by a different user (checker), then released — posting one payment
 * per supplier through the shared engine — and exported as a bank file.
 */
class PaymentBatchService
{
    public const APPROVE_PERMISSION = 'finance.payments.approve';

    public function __construct(private ArApService $arap)
    {
    }

    /** Create a draft batch from selected open supplier bills. */
    public function create(array $billIds, ?string $bankCode, ?string $paymentDate, ?string $notes): FinPaymentBatch
    {
        $bills = FinInvoice::payable()->open()->whereIn('id', $billIds)
            ->whereRaw('total - amount_paid > 0.001')->get();
        if ($bills->isEmpty()) {
            throw new PostingException('No payable bills selected.');
        }

        return DB::transaction(function () use ($bills, $bankCode, $paymentDate, $notes) {
            $batch = FinPaymentBatch::create([
                'reference'    => FinNumberSeries::next(null, 'payment_batch', 'PB-'),
                'bank_code'    => $bankCode ?: (string) config('finance.default_bank', '1100'),
                'status'       => 'draft',
                'total'        => round($bills->sum(fn ($b) => $b->outstanding()), 2),
                'payment_date' => $paymentDate ? Carbon::parse($paymentDate) : now(),
                'notes'        => $notes, 'created_by' => Auth::id(),
            ]);
            foreach ($bills as $bill) {
                $batch->lines()->create(['invoice_id' => $bill->id, 'party_id' => $bill->party_id, 'amount' => $bill->outstanding()]);
            }

            return $batch->fresh('lines');
        });
    }

    /** Checker approval — must be a different user with the approve permission. */
    public function approve(FinPaymentBatch $batch, User $checker): FinPaymentBatch
    {
        if ($batch->status !== 'draft') {
            throw new PostingException("Only a draft batch can be approved (this one is {$batch->status}).");
        }
        if (! $checker->can(self::APPROVE_PERMISSION)) {
            throw new PostingException('You are not permitted to approve payment batches.');
        }
        if ((int) $batch->created_by === (int) $checker->id) {
            throw new PostingException('Maker-checker: the approver must be different from the person who created the batch.');
        }

        $batch->update(['status' => 'approved', 'approved_by' => $checker->id, 'approved_at' => now()]);

        return $batch;
    }

    public function reject(FinPaymentBatch $batch, string $reason): FinPaymentBatch
    {
        if (! in_array($batch->status, ['draft', 'approved'], true)) {
            throw new PostingException('Only a draft or approved batch can be rejected.');
        }
        $batch->update(['status' => 'rejected', 'notes' => trim(($batch->notes ? $batch->notes . "\n" : '') . 'Rejected: ' . $reason)]);

        return $batch;
    }

    /** Release an approved batch: post one payment per supplier and mark paid. */
    public function pay(FinPaymentBatch $batch): FinPaymentBatch
    {
        if ($batch->status !== 'approved') {
            throw new PostingException('Only an approved batch can be released.');
        }

        DB::transaction(function () use ($batch) {
            foreach ($batch->lines->groupBy('party_id') as $partyId => $lines) {
                $payment = $this->arap->recordPayment([
                    'party_id'        => (int) $partyId, 'direction' => 'payment',
                    'payment_date'    => optional($batch->payment_date)->toDateString(),
                    'bank_account_id' => $batch->bank_code, 'reference' => $batch->reference,
                    'allocations'     => $lines->map(fn ($l) => ['invoice_id' => $l->invoice_id, 'amount' => (float) $l->amount])->all(),
                ]);
                $lines->each(fn ($l) => $l->update(['payment_id' => $payment->id]));
            }
            $batch->update(['status' => 'paid', 'paid_at' => now()]);
        });

        return $batch->fresh();
    }

    /** ISO 20022 pain.001.001.03 credit-transfer initiation for the batch. */
    public function pain001(FinPaymentBatch $batch): string
    {
        $s = FinSetting::current();
        $batch->loadMissing('lines.party');
        $ccy = 'ZAR';
        $n = $batch->lines->count();
        $sum = number_format((float) $batch->total, 2, '.', '');
        $date = optional($batch->payment_date)->toDateString() ?: now()->toDateString();
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_XML1);

        $tx = '';
        foreach ($batch->lines as $l) {
            $amt = number_format((float) $l->amount, 2, '.', '');
            $tx .= '<CdtTrfTxInf>'
                . '<PmtId><EndToEndId>' . $e($batch->reference . '-' . $l->id) . '</EndToEndId></PmtId>'
                . '<Amt><InstdAmt Ccy="' . $ccy . '">' . $amt . '</InstdAmt></Amt>'
                . '<CdtrAgt><FinInstnId><Othr><Id>' . $e($l->party->bank_name ?: 'UNKNOWN') . '</Id></Othr></FinInstnId></CdtrAgt>'
                . '<Cdtr><Nm>' . $e($l->party->name) . '</Nm></Cdtr>'
                . '<CdtrAcct><Id><Othr><Id>' . $e($l->party->bank_account ?: 'UNKNOWN') . '</Id></Othr></Id></CdtrAcct>'
                . '<RmtInf><Ustrd>' . $e('Payment ' . $batch->reference) . '</Ustrd></RmtInf>'
                . '</CdtTrfTxInf>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.001.001.03">'
            . '<CstmrCdtTrfInitn>'
            . '<GrpHdr><MsgId>' . $e($batch->reference) . '</MsgId><CreDtTm>' . now()->toIso8601String() . '</CreDtTm>'
            . '<NbOfTxs>' . $n . '</NbOfTxs><CtrlSum>' . $sum . '</CtrlSum>'
            . '<InitgPty><Nm>' . $e($s->company_name ?: config('app.name')) . '</Nm></InitgPty></GrpHdr>'
            . '<PmtInf><PmtInfId>' . $e($batch->reference) . '</PmtInfId><PmtMtd>TRF</PmtMtd>'
            . '<NbOfTxs>' . $n . '</NbOfTxs><CtrlSum>' . $sum . '</CtrlSum>'
            . '<ReqdExctnDt>' . $date . '</ReqdExctnDt>'
            . '<Dbtr><Nm>' . $e($s->company_name ?: config('app.name')) . '</Nm></Dbtr>'
            . '<DbtrAcct><Id><Othr><Id>' . $e($s->bank_account_number ?: 'UNKNOWN') . '</Id></Othr></Id></DbtrAcct>'
            . '<DbtrAgt><FinInstnId><Othr><Id>' . $e($s->bank_name ?: 'UNKNOWN') . '</Id></Othr></FinInstnId></DbtrAgt>'
            . $tx
            . '</PmtInf></CstmrCdtTrfInitn></Document>';
    }

    /** Generic bank CSV (beneficiary, bank, account, amount, reference). */
    public function csv(FinPaymentBatch $batch): string
    {
        $batch->loadMissing('lines.party');
        $rows = ['Beneficiary,Bank,Account,Amount,Reference'];
        foreach ($batch->lines as $l) {
            $rows[] = implode(',', [
                '"' . str_replace('"', '', (string) $l->party->name) . '"',
                '"' . str_replace('"', '', (string) $l->party->bank_name) . '"',
                (string) $l->party->bank_account,
                number_format((float) $l->amount, 2, '.', ''),
                $batch->reference,
            ]);
        }

        return implode("\n", $rows) . "\n";
    }
}
