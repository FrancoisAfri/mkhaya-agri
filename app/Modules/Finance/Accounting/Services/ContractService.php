<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Modules\Finance\Accounting\Contracts\FinancialEvent;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinContract;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinNumberSeries;
use App\Modules\Finance\Accounting\Models\FinParty;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Progress billing + retention (2.2). A progress claim recognises income on a
 * contract, holds back retention to a retention-receivable account, and raises a
 * receivable for the net-of-retention amount. Retention is released later as a
 * further receivable. All postings go through the shared Ledger.
 */
class ContractService
{
    public function __construct(private Ledger $ledger)
    {
    }

    private function acc(string $key): string
    {
        return (string) config("finance.$key");
    }

    public function save(array $data, ?FinContract $contract = null): FinContract
    {
        $party = FinParty::findOrFail($data['party_id']);
        $contract ??= new FinContract(['company_id' => $party->company_id, 'created_by' => Auth::id()]);
        $contract->fill([
            'party_id' => $party->id, 'income_account_id' => (int) $data['income_account_id'],
            'name' => $data['name'], 'contract_value' => round((float) $data['contract_value'], 2),
            'retention_percent' => round((float) ($data['retention_percent'] ?? 0), 3),
            'status' => $data['status'] ?? ($contract->status ?? 'active'),
            'description' => $data['description'] ?? null,
        ])->save();

        return $contract;
    }

    /** Bill a stage of the contract: recognise net income, hold retention, raise a receivable. */
    public function progressClaim(FinContract $contract, float $net, float $taxRate, string $description): FinInvoice
    {
        $net = round($net, 2);
        if ($net <= 0) {
            throw new PostingException('Claim amount must be positive.');
        }
        if ($net > $contract->remaining() + 0.001) {
            throw new PostingException('Claim exceeds the remaining contract value (' . number_format($contract->remaining(), 2) . ').');
        }

        $exempt = (bool) $contract->party->tax_exempt;
        $retention = round($net * (float) $contract->retention_percent / 100, 2);
        $tax = $exempt ? 0.0 : round($net * $taxRate / 100, 2);
        $arNow = round($net - $retention + $tax, 2);

        return DB::transaction(function () use ($contract, $net, $tax, $retention, $arNow, $description) {
            $inv = FinInvoice::create([
                'company_id' => $contract->company_id, 'party_id' => $contract->party_id,
                'direction' => 'receivable', 'doc_type' => 'invoice', 'contract_id' => $contract->id,
                'number' => FinNumberSeries::next($contract->company_id, 'progress_claim', 'PC-'),
                'invoice_date' => now(), 'due_date' => now()->addDays((int) $contract->party->credit_terms_days),
                'currency' => config('finance.base_currency', 'ZAR'), 'status' => 'open',
                'subtotal' => $net, 'tax_total' => $tax, 'total' => $arNow, 'amount_paid' => 0,
                'description' => trim("{$contract->name} — {$description}" . ($retention > 0 ? " (retention held " . number_format($retention, 2) . ")" : '')),
                'created_by' => Auth::id(),
            ]);

            $debits = [['account' => $this->acc('ar_control'), 'amount' => $arNow, 'narration' => "Progress claim {$inv->number}"]];
            if ($retention > 0) {
                $debits[] = ['account' => $this->acc('retention_account'), 'amount' => $retention, 'narration' => 'Retention held'];
            }
            $credits = [['account' => $contract->income_account_id, 'amount' => $net, 'narration' => "Progress claim {$inv->number}"]];
            if ($tax > 0) {
                $credits[] = ['account' => $this->acc('vat_output'), 'amount' => $tax, 'narration' => 'VAT'];
            }

            $journal = $this->ledger->post(new FinancialEvent(
                sourceModule: 'ar', eventType: 'ar.progress_claim', debits: $debits, credits: $credits,
                eventDate: now(), companyId: $contract->company_id, sourceRef: "progress_claim:{$inv->id}",
                description: "{$inv->number} · {$contract->name}", idempotencyKey: "arap_progress:{$inv->id}",
            ));
            $inv->update(['journal_id' => $journal->id]);

            $contract->update([
                'billed_net' => round((float) $contract->billed_net + $net, 2),
                'retention_held' => round((float) $contract->retention_held + $retention, 2),
            ]);

            return $inv;
        });
    }

    /** Release held retention as a collectible receivable. */
    public function releaseRetention(FinContract $contract, ?float $amount = null): FinInvoice
    {
        $amount = round($amount ?? (float) $contract->retention_held, 2);
        if ($amount <= 0 || $amount > (float) $contract->retention_held + 0.001) {
            throw new PostingException('Release amount must be between 0 and the retention held (' . number_format((float) $contract->retention_held, 2) . ').');
        }

        return DB::transaction(function () use ($contract, $amount) {
            $inv = FinInvoice::create([
                'company_id' => $contract->company_id, 'party_id' => $contract->party_id,
                'direction' => 'receivable', 'doc_type' => 'invoice', 'contract_id' => $contract->id,
                'number' => FinNumberSeries::next($contract->company_id, 'retention_release', 'RR-'),
                'invoice_date' => now(), 'due_date' => now()->addDays((int) $contract->party->credit_terms_days),
                'currency' => config('finance.base_currency', 'ZAR'), 'status' => 'open',
                'subtotal' => $amount, 'tax_total' => 0, 'total' => $amount, 'amount_paid' => 0,
                'description' => "{$contract->name} — retention release", 'created_by' => Auth::id(),
            ]);

            $journal = $this->ledger->post(new FinancialEvent(
                sourceModule: 'ar', eventType: 'ar.retention_release',
                debits: [['account' => $this->acc('ar_control'), 'amount' => $amount, 'narration' => "Retention release {$inv->number}"]],
                credits: [['account' => $this->acc('retention_account'), 'amount' => $amount, 'narration' => "Retention release {$inv->number}"]],
                eventDate: now(), companyId: $contract->company_id, sourceRef: "retention_release:{$inv->id}",
                description: "{$inv->number} · {$contract->name}", idempotencyKey: "arap_retention:{$inv->id}",
            ));
            $inv->update(['journal_id' => $journal->id]);

            $contract->update(['retention_held' => round((float) $contract->retention_held - $amount, 2)]);

            return $inv;
        });
    }
}
