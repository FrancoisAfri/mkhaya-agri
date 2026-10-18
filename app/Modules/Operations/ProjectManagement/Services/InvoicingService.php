<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Contracts\Providers\RevenueSink;
use App\Modules\Operations\ProjectManagement\Models\Invoice;
use App\Modules\Operations\ProjectManagement\Models\InvoiceAnnexure;
use App\Modules\Operations\ProjectManagement\Models\InvoiceDisbursement;
use App\Modules\Operations\ProjectManagement\Models\InvoiceFeeAccount;
use App\Modules\Operations\ProjectManagement\Models\InvoiceFeeStage;
use App\Modules\Operations\ProjectManagement\Models\InvoicePreviousClaim;
use App\Modules\Operations\ProjectManagement\Models\InvoicingSetting;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Support\Invoicing\AccountSummary;
use App\Modules\Operations\ProjectManagement\Support\Invoicing\AnnexureCatalogue;
use App\Modules\Operations\ProjectManagement\Support\Invoicing\DisbursementAccount;
use App\Modules\Operations\ProjectManagement\Support\Invoicing\FeeStage;
use App\Modules\Operations\ProjectManagement\Support\Invoicing\Money;
use App\Modules\Operations\ProjectManagement\Support\Invoicing\ProfessionalFeeAccount;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Issuing accounts against a project, in sequence.
 *
 * The service exists for one hard part: **an account only makes sense in the context of every
 * account before it.** Account 7 claims what stage 3.6 has earned to date less what accounts 1–6
 * already claimed against it. Get that wrong and the client is billed twice.
 *
 * ── The sequence ────────────────────────────────────────────────────────────
 *
 *   startNextAccount()   allocates the next number, carries the previous account's setup forward,
 *                        and snapshots what has been claimed so far
 *   recalculate()        runs the pure engine over the stored rows and writes the results back
 *   generate()           freezes the account: no further recalculation
 *
 * Recalculation is explicit rather than automatic on save. A user editing a progress percentage
 * expects to see the effect; a background recalculation of an account somebody else has already
 * signed is how a signed document stops matching its signature.
 */
class InvoicingService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ProviderRegistry $providers,
    )
    {
    }

    /**
     * Open the next account on a project.
     *
     * Numbering is allocated under a row lock inside the transaction. Two people pressing "Create
     * New Account" at the same moment would otherwise both read account 6 and both write account 7,
     * and the partial unique index would reject the loser with a constraint violation rather than
     * a number.
     */
    public function startNextAccount(Project $project, array $attributes = []): Invoice
    {
        return DB::transaction(function () use ($project, $attributes) {
            // The project row is the serialisation point. Locking the invoices instead is what one
            // reaches for first and Postgres refuses it — FOR UPDATE cannot be combined with an
            // aggregate, and there is nothing to lock at all when a project has no accounts yet,
            // which is exactly when two simultaneous "Create New Account" clicks collide.
            Project::whereKey($project->id)->lockForUpdate()->first();

            // Two different predecessors, and conflating them is a bug.
            //
            // `latest` is the most recent account of any status, and it supplies the *shape*: the
            // client's details, the works value, the stage rows and their apportionments. Those
            // carry forward even from a draft that was abandoned or an account that was withdrawn,
            // because they describe the appointment, not the claim.
            //
            // `previous` is the most recent *issued* account, and it is the only thing that may
            // reduce what this account claims. Letting a draft do that would suppress work the
            // client has never been billed for.
            $latest = Invoice::forProject($project->id)
                ->orderByDesc('account_number')
                ->first();

            $previous = Invoice::forProject($project->id)
                ->claimable()
                ->orderByDesc('account_number')
                ->first();

            $highest = (int) Invoice::forProject($project->id)
                ->withTrashed()
                ->max('account_number');

            $invoice = Invoice::create(array_merge([
                'project_id'     => $project->id,
                'client_ref_id'  => $project->client_ref_id,
                'account_number' => $highest + 1,
                'invoice_number' => $this->nextInvoiceNumber($project, $highest + 1),
                'invoice_date'   => now()->toDateString(),
                'currency'       => $project->currency ?? 'ZAR',
                'status'         => 'draft',
                'created_by'     => Auth::id(),
            ],
                // Least specific first: the practice's own details, then the client's, then
                // whatever the previous invoice said, then whatever the caller passed. So a first
                // invoice arrives filled in and a later one keeps what was corrected on it.
                InvoicingSetting::current()->defaultsForInvoice(),
                $this->clientDefaults($project),
                $this->carriedForward($latest),
                $attributes,
            ));

            $this->carryFeeAccount($invoice, $latest);
            $this->snapshotPreviousClaims($invoice);

            $this->audit->recordAction($invoice, 'invoice.opened', [
                'project'        => $project->uuid,
                'account_number' => $invoice->account_number,
                'follows'        => $previous?->uuid,
                'shape_from'     => $latest?->uuid,
            ]);

            return $invoice->fresh();
        });
    }

/**
     * The client's details, from whichever system owns them.
     *
     * With CRM installed the name, address and contact come from the CRM account; the invoicing
     * extras CRM has no column for — VAT number, who to address it to — are held on PM's own link
     * record. Without CRM, PM holds all of it. Either way an invoice is filled in rather than typed.
     *
     * @return array<string, mixed>
     */
    private function clientDefaults(Project $project): array
    {
        $ref = $project->clientRef;

        if (! $ref) {
            return [];
        }

        $snapshot = $ref->snapshot ?? [];

        return array_filter([
            'client_name'           => $ref->display_name,
            'client_address'        => $snapshot['address'] ?? null,
            'client_vat_number'     => $snapshot['vat_number'] ?? null,
            'attention'             => $snapshot['attention'] ?? null,
            'client_project_number' => $snapshot['client_project_number'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * The client's details and the account's rates from the most recent account, of any status.
     *
     * "Copy Previous Month" for an invoice: an account is nearly always the previous one with the
     * figures moved on, and retyping a VAT number every month is how a VAT number ends up wrong.
     */
    private function carriedForward(?Invoice $latest): array
    {
        if (! $latest) {
            return [];
        }

        $previous = $latest;

        return array_filter([
            'account_type'          => $previous->account_type,
            'client_name'           => $previous->client_name,
            'client_address'        => $previous->client_address,
            'client_vat_number'     => $previous->client_vat_number,
            'attention'             => $previous->attention,
            'client_project_number' => $previous->client_project_number,
            'consultant_name'       => $previous->consultant_name,
            'consultant_address'    => $previous->consultant_address,
            'consultant_reference'  => $previous->consultant_reference,
            'vendor_number'         => $previous->vendor_number,
            'consultant_vat_number' => $previous->consultant_vat_number,
            'file_number'           => $previous->file_number,
            'description'           => $previous->description,
            'banking_name'          => $previous->banking_name,
            'banking_account_name'  => $previous->banking_account_name,
            'banking_account_number' => $previous->banking_account_number,
            'banking_branch_code'   => $previous->banking_branch_code,
            'vat_rate'              => $previous->vat_rate,
            'disbursement_fee_rate' => $previous->disbursement_fee_rate,
        ], fn ($value) => $value !== null);
    }

    /**
     * Form 1's header and its stage rows, carried forward with the progress left where it was.
     *
     * Taken from the most recent account of any status — see startNextAccount. A withdrawn account
     * still tells you which stages this appointment has.
     */
    private function carryFeeAccount(Invoice $invoice, ?Invoice $latest): void
    {
        $source = $latest?->feeAccount;

        InvoiceFeeAccount::create([
            'invoice_id'                     => $invoice->id,
            // Null-safe throughout: the first account on a project has nothing to carry forward,
            // and `?? 0` does not save you — reading a property on null still raises a warning,
            // which Laravel's handler turns into an exception.
            'external_works'                 => $source?->external_works ?? 0,
            'structural_works'               => $source?->structural_works ?? 0,
            'other_works'                    => $source?->other_works ?? 0,
            'total_value_of_works'           => $source?->total_value_of_works ?? 0,
            'contingency_allowance'          => $source?->contingency_allowance ?? 0,
            'construction_completed_to_date' => $source?->construction_completed_to_date ?? 0,
            'fee_percentage'                 => $source?->fee_percentage ?? 0,
            'approved_fee'                   => $source?->approved_fee,
            // Never carried forward: an adjusted fee is a proposal about *this* account.
            'adjusted_fee'                   => null,
            'previously_paid_incl_vat'       => $this->paidToDate($invoice),
        ]);

        foreach ($latest?->stages ?? [] as $stage) {
            InvoiceFeeStage::create([
                'invoice_id'          => $invoice->id,
                'code'                => $stage->code,
                'label'               => $stage->label,
                'sort'                => $stage->sort,
                'apportionment'       => $stage->apportionment,
                // Progress carries over; the user moves it on for this period.
                'progress'            => $stage->progress,
                'fixed_value_to_date' => $stage->fixed_value_to_date,
            ]);
        }
    }

    /** What the client has actually paid across every issued account, for Form 1's line 3.13. */
    private function paidToDate(Invoice $invoice): string
    {
        return Money::money(
            Invoice::forProject($invoice->project_id)
                ->claimable()
                ->where('id', '!=', $invoice->id)
                ->sum('amount_now_due')
        );
    }

    /**
     * Freeze what earlier accounts claimed onto this one.
     *
     * Summed from the issued accounts and written down, never re-derived. See InvoicePreviousClaim.
     */
    public function snapshotPreviousClaims(Invoice $invoice): void
    {
        $earlier = Invoice::forProject($invoice->project_id)
            ->claimable()
            ->where('account_number', '<', $invoice->account_number)
            ->with(['stages', 'disbursements', 'annexures'])
            ->get();

        InvoicePreviousClaim::where('invoice_id', $invoice->id)->delete();

        $totals = [];

        foreach ($earlier as $source) {
            foreach ($source->stages as $stage) {
                $this->accrue($totals, 'fee_stage', $stage->code, $stage->this_account, $source->id);
            }

            foreach ($source->disbursements as $row) {
                $this->accrue($totals, 'disbursement', $row->description, $row->this_account, $source->id);
            }

            foreach ($source->annexures as $annexure) {
                $this->accrue($totals, 'annexure', (string) $annexure->number, $annexure->this_invoice, $source->id);
            }
        }

        foreach ($totals as $row) {
            InvoicePreviousClaim::create([
                'invoice_id'        => $invoice->id,
                'source_invoice_id' => $row['source_invoice_id'],
                'subject_type'      => $row['subject_type'],
                'subject_key'       => $row['subject_key'],
                'amount'            => Money::money($row['amount']),
            ]);
        }

        // Push the resolved figures onto the rows that will use them.
        $this->applyPreviousClaims($invoice);
    }

    /** @param  array<string, array<string, mixed>>  $totals */
    private function accrue(array &$totals, string $type, string $key, $amount, int $sourceId): void
    {
        $index = $type . '|' . $key;

        $totals[$index] ??= [
            'subject_type'      => $type,
            'subject_key'       => $key,
            'amount'            => '0',
            // The most recent account this figure came from, which is the one worth linking to.
            'source_invoice_id' => $sourceId,
        ];

        $totals[$index]['amount'] = Money::add($totals[$index]['amount'], $amount);
        $totals[$index]['source_invoice_id'] = $sourceId;
    }

    private function applyPreviousClaims(Invoice $invoice): void
    {
        $claims = InvoicePreviousClaim::where('invoice_id', $invoice->id)
            ->get()
            ->keyBy(fn (InvoicePreviousClaim $c) => $c->subject_type . '|' . $c->subject_key);

        foreach ($invoice->stages()->get() as $stage) {
            $stage->update([
                'previously_claimed' => $claims['fee_stage|' . $stage->code]->amount ?? 0,
            ]);
        }

        foreach ($invoice->disbursements()->get() as $row) {
            $row->update([
                'previously_claimed' => $claims['disbursement|' . $row->description]->amount ?? 0,
            ]);
        }

        foreach ($invoice->annexures()->get() as $annexure) {
            $annexure->update([
                'previously_claimed' => $claims['annexure|' . $annexure->number]->amount ?? 0,
            ]);
        }
    }

    // ── Recalculation ────────────────────────────────────────────────────────

    /**
     * Run the engine over the stored rows and write the results back.
     *
     * Refuses on a frozen account. An account that has been generated has been printed, signed and
     * very likely sent; recalculating it would leave a signature describing figures that no longer
     * exist.
     */
    public function recalculate(Invoice $invoice): Invoice
    {
        if ($invoice->isFrozen()) {
            throw new RuntimeException('Account ' . $invoice->account_number . ' has been issued and can no longer be recalculated.');
        }

        return DB::transaction(function () use ($invoice) {
            $this->recalculateAnnexures($invoice);

            $fee = $this->feeCalculator($invoice);
            $feeLines = $fee->lines();

            foreach ($invoice->stages()->get() as $stage) {
                $line = collect($feeLines)->firstWhere('code', $stage->code);

                if ($line) {
                    $stage->update([
                        'stage_fee'     => Money::money($line['stage_fee']),
                        'value_to_date' => Money::money($line['value_to_date']),
                        'this_account'  => Money::money($line['this_account']),
                    ]);
                }
            }

            $disbursements = $this->disbursementCalculator($invoice);
            $summary = new AccountSummary(
                feesNowClaimed: $fee->thisAccountExclVat(),
                disbursementCategories: $invoice->disbursements()
                    ->where('block', 'category')
                    ->get()
                    ->map(fn (InvoiceDisbursement $d) => [
                        'description' => $d->description,
                        'reference'   => $d->reference ?? '',
                        'now_claimed' => $d->this_account,
                    ])->all(),
                vatRate: (string) $invoice->vat_rate,
            );

            $invoice->update([
                'fees_now_claimed'          => Money::money($summary->subTotalFees()),
                'disbursements_now_claimed' => Money::money($summary->subTotalDisbursements()),
                'subtotal'                  => Money::money($summary->totalNowClaimed()),
                'vat_amount'                => Money::money($summary->vat()),
                'amount_now_due'            => Money::money($summary->amountNowDue()),
            ]);

            // Referenced so the disbursement form's own totals stay available to the caller even
            // though the header takes its figures from the Summary, which is what the client's
            // Tax Invoice reads off.
            unset($disbursements);

            return $invoice->fresh();
        });
    }

    /** Each annexure's lines, then its total, then the Form 2 row that carries it. */
    private function recalculateAnnexures(Invoice $invoice): void
    {
        foreach ($invoice->annexures()->with('items')->get() as $annexure) {
            $total = '0';

            foreach ($annexure->items as $item) {
                $thisInvoice = AnnexureCatalogue::calculate($annexure->number, [
                    'quantity'            => $item->quantity,
                    'tariff'              => $item->tariff,
                    'kilometres'          => $item->kilometres,
                    'less_kilometres'     => $item->less_kilometres,
                    'less_hours'          => $item->less_hours,
                    'hours'               => $item->hours,
                    'appointment_amount'  => $item->appointment_amount,
                    'claimed_this_period' => $item->claimed_this_period,
                ]);

                $item->update([
                    'this_invoice' => Money::money($thisInvoice),
                    'total'        => Money::money(Money::add($item->previously_claimed, $thisInvoice)),
                ]);

                $total = Money::add($total, $thisInvoice);
            }

            $annexure->update([
                'this_invoice' => Money::money($total),
                'total'        => Money::money(Money::add($annexure->previously_claimed, $total)),
            ]);

            // The Form 2 category row this annexure feeds, matched on the Summary's wording.
            $description = AnnexureCatalogue::summaryDescription($annexure->number);

            InvoiceDisbursement::where('invoice_id', $invoice->id)
                ->where('block', 'category')
                ->where('description', $description)
                ->update([
                    'this_account' => Money::money($total),
                    'total'        => Money::money(Money::add(
                        InvoiceDisbursement::where('invoice_id', $invoice->id)
                            ->where('description', $description)
                            ->value('previously_claimed') ?? 0,
                        $total,
                    )),
                ]);
        }
    }

    /** The pure Form 1 calculator, built from what is stored. */
    public function feeCalculator(Invoice $invoice): ProfessionalFeeAccount
    {
        $header = $invoice->feeAccount;

        $stages = $invoice->stages()->get()->map(function (InvoiceFeeStage $stage): FeeStage {
            return $stage->isFixed()
                ? FeeStage::fixed($stage->code, $stage->label, $stage->fixed_value_to_date, $stage->previously_claimed)
                : FeeStage::apportioned(
                    $stage->code,
                    $stage->label,
                    $stage->apportionment,
                    $stage->progress,
                    $stage->previously_claimed,
                );
        })->all();

        return new ProfessionalFeeAccount(
            totalValueOfWorks: (string) ($header?->total_value_of_works ?? '0'),
            feePercentage: (string) ($header?->fee_percentage ?? '0'),
            stages: $stages,
            vatRate: (string) $invoice->vat_rate,
            previouslyPaidInclVat: (string) ($header?->previously_paid_incl_vat ?? '0'),
            approvedFeeOverride: $header?->approved_fee !== null ? (string) $header->approved_fee : null,
        );
    }

    /** The pure Form 2 calculator, built from what is stored. */
    public function disbursementCalculator(Invoice $invoice): DisbursementAccount
    {
        $rows = $invoice->disbursements()->get();

        $shape = fn (InvoiceDisbursement $d): array => [
            'description'        => $d->description,
            'reference'          => $d->reference ?? '',
            'this_account'       => $d->this_account,
            'previously_claimed' => $d->previously_claimed,
        ];

        return new DisbursementAccount(
            feeLines: $rows->where('block', 'fee')->map($shape)->values()->all(),
            categoryLines: $rows->where('block', 'category')->map($shape)->values()->all(),
            vatRate: (string) $invoice->vat_rate,
            feeBlockRate: (string) $invoice->disbursement_fee_rate,
        );
    }

    // ── Issuing ──────────────────────────────────────────────────────────────

    /**
     * Freeze the account.
     *
     * After this its figures are what the client was sent, and the next account will read them as
     * "previously claimed". Recalculated once more first, so what is frozen is what the rows say.
     */
    public function generate(Invoice $invoice): Invoice
    {
        if ($invoice->isFrozen()) {
            throw new RuntimeException('That account has already been issued.');
        }

        $this->recalculate($invoice);

        $invoice->update(['status' => 'generated', 'generated_at' => now()]);

        /*
         * And into the books, where Accounting is installed.
         *
         * Issuing is the accounting event: this is the moment the client owes the money, so it is
         * the moment they become a debtor. Before this the invoice was a document PM produced and
         * the ledger never heard of it, which meant every project invoice was raised twice — once
         * for the client and once for the books.
         *
         * Outside the freeze, deliberately. Whether Accounting accepted the receivable is Finance's
         * problem and must never undo an invoice a person has issued; the sink logs its own
         * failures and the invoice stays re-postable.
         */
        $reference = $this->providers->for(RevenueSink::class)->post($invoice);

        $this->audit->recordAction($invoice, 'invoice.generated', [
            'account_number' => $invoice->account_number,
            'amount_now_due' => (string) $invoice->amount_now_due,
            'receivable'     => $reference,
        ]);

        return $invoice->fresh();
    }

/**
     * Mark an issued invoice as sent to the client.
     *
     * The client's workflow has four states and this is the third. It changes nothing about the
     * figures — a sent invoice is as frozen as a generated one — but "issued" and "the client has
     * it" are different facts, and the register is the only place anybody can see which.
     */
    public function markSent(Invoice $invoice): Invoice
    {
        if ($invoice->status !== 'generated') {
            throw new RuntimeException('Only a generated invoice can be marked as sent.');
        }

        $invoice->update(['status' => 'sent', 'sent_at' => now()]);

        $this->audit->recordAction($invoice, 'invoice.sent', [
            'account_number' => $invoice->account_number,
        ]);

        return $invoice->fresh();
    }

    /**
     * Withdraw an account.
     *
     * Cancelled rather than deleted, and it stops counting towards what has been claimed — so the
     * next account correctly claims the work back. The number is not reused; the sequence is a
     * record of what was issued, including what was withdrawn.
     */
    public function cancel(Invoice $invoice, string $reason): Invoice
    {
        if ($invoice->status === 'cancelled') {
            return $invoice;
        }

        $invoice->update([
            'status'              => 'cancelled',
            'cancelled_at'        => now(),
            'cancellation_reason' => $reason,
        ]);

        // A credit note, never a deletion — the client holds the document, and a receivable that
        // simply disappears is one nobody can explain at year end.
        $credit = $this->providers->for(RevenueSink::class)->reverse($invoice, $reason);

        $this->audit->recordAction($invoice, 'invoice.cancelled', [
            'reason'      => $reason,
            'credit_note' => $credit,
        ]);

        return $invoice->fresh();
    }

    /** `MP23001/07` — the project's own reference and the account number. */
    private function nextInvoiceNumber(Project $project, int $accountNumber): string
    {
        return sprintf('%s/%02d', $project->code, $accountNumber);
    }
}
