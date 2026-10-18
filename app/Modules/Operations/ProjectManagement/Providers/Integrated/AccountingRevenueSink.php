<?php

namespace App\Modules\Operations\ProjectManagement\Providers\Integrated;

use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Services\AccountMap;
use App\Modules\Finance\Accounting\Services\ArApService;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\RevenueSink;
use App\Modules\Operations\ProjectManagement\Models\Invoice;
use App\Modules\Operations\ProjectManagement\Services\ProjectPartyResolver;
use Illuminate\Support\Facades\Log;

/**
 * An issued project invoice, recorded as a receivable in Accounting.
 *
 * This is the half that was missing. Approved project *costs* have had a route to the ledger for
 * some time, so a project that spent money and billed for it showed only the spending: the client
 * never became a debtor, the invoice never aged, no statement carried it, dunning never chased it,
 * and the VAT output was raised again by hand somewhere else. Every project invoice was in effect
 * raised twice — once for the client, once for the books — with a reconciliation in between.
 *
 * ── An invoice, not a journal ───────────────────────────────────────────────
 *
 * The narrower option was to emit a `FinancialEvent` and put revenue in the trial balance. That is
 * a day's work and it is the wrong day's work: it gets the number into the books and reaches none
 * of the machinery a receivable is *for*. `ArApService::createInvoice()` posts the journal as part
 * of its own job, so going through it gives the ledger entry and the ageing, the statement, the
 * dunning and the payment allocation for the same effort.
 *
 * ── Two documents, one number ───────────────────────────────────────────────
 *
 * Project Management keeps its own document. The client's invoice is the PM one — its layout, its
 * agent certificate, its annexures, and the account number the client has been quoting all year.
 * The Accounting invoice is the financial record, and it carries the PM number as its reference so
 * a payment quoting either one can be found. Replacing the PM document with a Finance-generated
 * one would change what lands on the client's desk, which is not a change that belongs inside a
 * ledger integration.
 *
 * ── Failure must not undo the issue ─────────────────────────────────────────
 *
 * An invoice is issued by a person on an operational screen. Whether Accounting accepted the
 * receivable is Finance's problem, and it must never roll back the issuing — the same rule the
 * expense sink already follows for approvals. Failures are logged and the invoice keeps its own
 * record, so it stays re-postable.
 */
class AccountingRevenueSink implements RevenueSink
{
    /** Where project fee income lands when the tenant has said nothing. Sales, in every chart. */
    private const STANDARD_CHART_REVENUE = '4100';

    public function __construct(
        private readonly ProjectPartyResolver $parties,
        private readonly ArApService $arap,
        private readonly AccountMap $accounts,
    ) {
    }

    public function post(Invoice $invoice): ?string
    {
        // Already recorded. Issuing is idempotent at the PM end, but a retry after a partial
        // failure must not raise the client a second debt for the same work.
        if ($invoice->ledger_invoice_id) {
            return $invoice->ledger_reference;
        }

        $project = $invoice->project;
        $party = $project ? $this->parties->resolveOrCreate($project) : null;

        if (! $party) {
            $this->refuse($invoice, 'no accounting customer for the project client');

            return null;
        }

        $revenue = $this->revenueAccountId();

        if ($revenue === null) {
            $this->refuse($invoice, 'no postable revenue account is mapped');

            return null;
        }

        $lines = $this->lines($invoice, $revenue);

        if ($lines === []) {
            // A nil account is a real thing — a month where nothing was claimed — and it is not a
            // receivable. `createInvoice` would refuse it anyway; refusing here says why.
            $this->refuse($invoice, 'nothing is claimed on this account');

            return null;
        }

        try {
            $receivable = $this->arap->createInvoice([
                'party_id'     => $party->id,
                'direction'    => 'receivable',
                'invoice_date' => $invoice->invoice_date?->toDateString(),
                'currency'     => $invoice->currency ?: 'ZAR',
                // The client's own reference is PM's account number, so a payment quoting either
                // document can be traced to the other.
                'party_ref'    => $invoice->invoice_number,
                'description'  => $invoice->description ?: ('Project ' . ($project->code ?? '')),
                'lines'        => $lines,
            ]);

            $invoice->forceFill([
                'ledger_invoice_id' => $receivable->id,
                'ledger_reference'  => $receivable->number,
            ])->save();

            return $receivable->number;
        } catch (\Throwable $e) {
            // Never let a ledger problem undo an invoice somebody has issued to a client.
            Log::warning('pm: could not record an issued invoice as a receivable', [
                'invoice' => $invoice->uuid,
                'error'   => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Withdrawing an invoice raises a credit note.
     *
     * Never a deletion. The client holds the document, and a receivable that simply disappears is
     * one nobody can explain at year end — least of all in a period that has closed since.
     */
    public function reverse(Invoice $invoice, string $reason): ?string
    {
        if (! $invoice->ledger_invoice_id) {
            return null;
        }

        $receivable = FinInvoice::find($invoice->ledger_invoice_id);

        if (! $receivable) {
            return null;
        }

        try {
            $outstanding = round($receivable->outstanding(), 2);

            if ($outstanding <= 0) {
                /*
                 * Already settled, wholly or in part.
                 *
                 * Crediting a paid invoice would leave the customer's account in credit for money
                 * that was genuinely owed and genuinely received — a refund, which is a decision
                 * somebody makes rather than a side effect of withdrawing a document.
                 */
                Log::info('pm: withdrawn invoice was already paid; no credit note raised', [
                    'invoice'    => $invoice->uuid,
                    'receivable' => $receivable->number,
                ]);

                return null;
            }

            $credit = $this->arap->createCreditNote($receivable, $outstanding, $reason);

            return $credit->number;
        } catch (\Throwable $e) {
            Log::warning('pm: could not raise a credit note for a withdrawn invoice', [
                'invoice' => $invoice->uuid,
                'error'   => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function postsToLedger(): bool
    {
        return $this->revenueAccountId() !== null;
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * Fees and disbursements as separate lines.
     *
     * Kept apart because they are different things to the client and to the accountant: a fee is
     * income earned, a disbursement is a cost recovered. Rolling them into one line loses that on
     * the one document where somebody might reconcile it.
     *
     * @return list<array<string, mixed>>
     */
    private function lines(Invoice $invoice, int $revenueAccountId): array
    {
        /*
         * A fraction here, a percentage there.
         *
         * Project Management stores `vat_rate` as a fraction — its own validation is
         * `between:0,1`, and the fee calculator's default is the string '0.15'. `ArApService`
         * takes a percentage, and computes `line_total × rate / 100`. Passing 0.15 straight
         * through would charge 0.15% VAT: an invoice a hundred times short on output tax, correct
         * on the client's copy and wrong in the return, which is the sort of disagreement nobody
         * finds until SARS does.
         */
        $rate = round((float) ($invoice->vat_rate ?? 0) * 100, 3);

        $candidates = [
            ['fees', (float) $invoice->fees_now_claimed, __('project_management.invoicing.fees_claimed')],
            ['disbursements', (float) $invoice->disbursements_now_claimed, __('project_management.invoicing.disbursements_claimed')],
        ];

        $lines = [];

        foreach ($candidates as [$key, $amount, $label]) {
            if (round($amount, 2) <= 0) {
                continue;
            }

            $lines[] = [
                'account_id'  => $revenueAccountId,
                'description' => $label,
                'quantity'    => 1,
                'unit_price'  => round($amount, 2),
                'tax_rate'    => $rate,
            ];
        }

        return $lines;
    }

    /** The mapped revenue account, as an id, or null when there is nothing safe to post to. */
    private function revenueAccountId(): ?int
    {
        $code = $this->accounts->codeFor('project_management', 'revenue.fees', self::STANDARD_CHART_REVENUE);

        if ($code === null) {
            return null;
        }

        return FinAccount::where('code', $code)->value('id');
    }

    /** Said once, plainly, with the thing somebody has to go and fix. */
    private function refuse(Invoice $invoice, string $why): void
    {
        Log::info('pm: issued invoice not recorded as a receivable', [
            'invoice' => $invoice->uuid,
            'reason'  => $why,
        ]);
    }
}
