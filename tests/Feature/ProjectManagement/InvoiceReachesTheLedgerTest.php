<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Operations\ProjectManagement\Models\ExternalRef;
use App\Modules\Operations\ProjectManagement\Models\Invoice;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Services\InvoicingService;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\FinAccountMappingSeeder;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Support\Str;

/**
 * An issued project invoice becoming a receivable.
 *
 * This was the half that was missing. Approved project *costs* have had a route to the ledger for
 * some time, so a project that spent money and billed for it showed only the spending: the client
 * never became a debtor, the invoice never aged, no statement carried it and dunning never chased
 * it. Every project invoice was in effect raised twice — once for the client, once for the books —
 * with a reconciliation in between that somebody did by hand at month end.
 *
 * Issuing is the accounting event, because that is the moment the client owes the money.
 */
class InvoiceReachesTheLedgerTest extends ProjectManagementTestCase
{
    private Company $company;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        if (! FinAccount::where('code', '4100')->exists()) {
            (new FinanceGlSeeder())->run();
        }

        (new FinAccountMappingSeeder())->run();

        if (! FinFiscalPeriod::forDate(now(), null)) {
            FinFiscalPeriod::create([
                'company_id' => null, 'fiscal_year' => (int) now()->year, 'period_no' => (int) now()->month,
                'start_date' => now()->startOfMonth(), 'end_date' => now()->endOfMonth(), 'status' => 'open',
            ]);
        }

        $this->company = Company::create(['name' => 'Aseda Revenue']);
        $this->entitleCompany($this->company);

        /*
         * And Accounting, which is the whole point of these tests.
         *
         * `ProviderRegistry` hands back the *local* sink unless the tenant carries the
         * `finance.postings` capability, and the local sink does nothing and says nothing — so
         * without this the tests fail with no receivable and no log line, which reads like a broken
         * sink rather than a tenant who never bought the module.
         */
        \Illuminate\Support\Facades\DB::table('company_modules')->updateOrInsert(
            ['company_id' => $this->company->id, 'module_code' => 'accounting'],
            ['source' => 'addon', 'price_monthly' => 0, 'created_at' => now(), 'updated_at' => now()],
        );
        \Illuminate\Support\Facades\Cache::flush();
        $this->manager->forceFill(['company_id' => $this->company->id])->save();

        $this->project = $this->asCompany($this->company, function () {
            $client = ExternalRef::create([
                'local_type' => 'project_client', 'source_module' => 'local', 'source_entity' => 'client',
                'display_name' => 'Kwaggafontein Municipality', 'sync_status' => 'ok',
                'snapshot' => ['email' => 'creditors@kwagga.test'],
            ]);

            return $this->makeProject([
                'code' => 'MP23001', 'name' => 'Recreational Centre',
                'status' => 'active', 'client_ref_id' => $client->id,
            ]);
        });
    }

    private function service(): InvoicingService
    {
        return app(InvoicingService::class);
    }

    /**
     * An account with money on it, built through the engine rather than typed into the row.
     *
     * `generate()` recalculates before it freezes — deliberately, so what is frozen is what the
     * rows say — which means figures written straight onto the invoice are wiped on the way past.
     * The fee has to come from a stage and the disbursement from a disbursement line, exactly as it
     * does on the screens.
     */
    private function account(float $worksValue = 1000000.0, float $disbursement = 5000.0): Invoice
    {
        $project = $this->project;

        return $this->asCompany($this->company, function () use ($project, $worksValue, $disbursement) {
            $invoice = $this->service()->startNextAccount($project);

            /*
             * The fee account already exists — `startNextAccount()` opens one — so this fills the
             * row in rather than adding a second. A second is not an error and is worse than one:
             * `feeAccount` is a hasOne, so it keeps returning the original with its zeros, and the
             * engine then values the works at nothing while the figures sit in a row nobody reads.
             */
            $fee = $invoice->feeAccount ?: $invoice->feeAccount()->make();
            $fee->fill([
                'external_works'   => $worksValue,
                'structural_works' => 0,
                // Stored, not derived: the controller computes this from the split, so a fee
                // account built directly has to carry it or the works are valued at nothing.
                'total_value_of_works' => $worksValue,
                // 10% of the works, so the arithmetic in the assertions stays legible.
                'fee_percentage'   => 0.10,
            ]);
            $invoice->feeAccount()->save($fee);

            // One stage, fully apportioned, all of it claimed this month.
            $invoice->stages()->create([
                'code' => '3.1', 'label' => 'Inception',
                'apportionment' => 1, 'progress' => 1,
            ]);

            if ($disbursement > 0) {
                $invoice->disbursements()->create([
                    'block' => 'category', 'description' => 'Town planners',
                    'this_account' => $disbursement,
                ]);
            }

            $invoice->forceFill([
                // A fraction, which is what PM stores and validates (`between:0,1`).
                'vat_rate'     => 0.15,
                'invoice_date' => now()->toDateString(),
                'currency'     => 'ZAR',
            ])->save();

            $this->service()->recalculate($invoice->fresh());

            return $invoice->fresh();
        });
    }

    private function receivableFor(Invoice $invoice): ?FinInvoice
    {
        return $invoice->fresh()->ledger_invoice_id
            ? FinInvoice::find($invoice->fresh()->ledger_invoice_id)
            : null;
    }

    // ── The gap, closed ──────────────────────────────────────────────────────

    public function test_issuing_an_invoice_creates_a_receivable(): void
    {
        $invoice = $this->account();

        $this->asCompany($this->company, fn () => $this->service()->generate($invoice));

        $this->assertNotNull($this->receivableFor($invoice),
            'An issued project invoice still does not reach the books.');
    }

    /** The client becomes a debtor — which is the thing a journal alone would not have achieved. */
    public function test_the_client_becomes_a_debtor(): void
    {
        $invoice = $this->account();

        $this->asCompany($this->company, fn () => $this->service()->generate($invoice));

        $receivable = $this->receivableFor($invoice);

        $this->assertSame('receivable', $receivable->direction);
        $this->assertSame('Kwaggafontein Municipality', FinParty::find($receivable->party_id)->name);
        $this->assertGreaterThan(0, $receivable->outstanding());
    }

    /** Fees and disbursements stay apart: income earned and cost recovered are different things. */
    public function test_fees_and_disbursements_are_separate_lines(): void
    {
        $invoice = $this->account(worksValue: 1000000, disbursement: 5000);

        $this->asCompany($this->company, fn () => $this->service()->generate($invoice));

        $fresh = $invoice->fresh();
        $lines = $this->receivableFor($invoice)->lines;

        $this->assertCount(2, $lines, 'Fees and disbursements were rolled into one line.');
        $this->assertEqualsWithDelta(
            (float) $fresh->fees_now_claimed + (float) $fresh->disbursements_now_claimed,
            (float) $lines->sum('line_total'),
            0.01,
        );
    }

    /** VAT has to come out the same on both documents, or the two disagree in front of SARS. */
    public function test_the_vat_matches_the_project_invoice(): void
    {
        $invoice = $this->account(worksValue: 1000000, disbursement: 0);

        $this->asCompany($this->company, fn () => $this->service()->generate($invoice));

        $this->assertEqualsWithDelta(
            (float) $invoice->fresh()->vat_amount,
            (float) $this->receivableFor($invoice)->tax_total,
            0.01,
            'The two documents disagree on the VAT, in front of SARS.',
        );
    }

    /** One number the client can quote, findable from either side. */
    public function test_the_two_documents_reference_each_other(): void
    {
        $invoice = $this->account();

        $this->asCompany($this->company, fn () => $this->service()->generate($invoice));

        $receivable = $this->receivableFor($invoice);

        $this->assertSame($invoice->fresh()->invoice_number, $receivable->party_ref);
        $this->assertSame($receivable->number, $invoice->fresh()->ledger_reference);
    }

    // ── Withdrawal ───────────────────────────────────────────────────────────

    /**
     * Cancelling raises a credit note, never a deletion. The client holds the document, and a
     * receivable that simply disappears is one nobody can explain at year end.
     */
    public function test_cancelling_an_issued_invoice_raises_a_credit_note(): void
    {
        $invoice = $this->account();

        $this->asCompany($this->company, function () use ($invoice) {
            $this->service()->generate($invoice);
            $this->service()->cancel($invoice->fresh(), 'Issued against the wrong stage');
        });

        $receivable = $this->receivableFor($invoice);

        $this->assertSame(0.0, round($receivable->fresh()->outstanding(), 2),
            'The debtor still owes money on a withdrawn invoice.');
    }

    // ── What must not happen ─────────────────────────────────────────────────

    /** A retry after a partial failure must not bill the client twice for the same work. */
    public function test_posting_the_same_invoice_twice_creates_one_receivable(): void
    {
        $invoice = $this->account();

        $this->asCompany($this->company, function () use ($invoice) {
            $sink = app(\App\Modules\Operations\ProjectManagement\Providers\Integrated\AccountingRevenueSink::class);
            $sink->post($invoice);
            $sink->post($invoice->fresh());
        });

        $this->assertSame(1, FinInvoice::where('party_ref', $invoice->fresh()->invoice_number)->count());
    }

    /** A nil account is a real thing and is not a debt. */
    public function test_an_account_claiming_nothing_creates_no_receivable(): void
    {
        $invoice = $this->account(worksValue: 0, disbursement: 0);

        $this->asCompany($this->company, fn () => $this->service()->generate($invoice));

        $this->assertNull($this->receivableFor($invoice));
    }

    /**
     * A ledger problem must never undo an invoice a person has issued to a client.
     *
     * With no revenue account to post to, the invoice is still issued and still frozen — it simply
     * has no receivable behind it yet, and stays re-postable.
     */
    public function test_a_failure_to_post_does_not_undo_the_issue(): void
    {
        $invoice = $this->account();

        $this->asCompany($this->company, function () use ($invoice) {
            app(\App\Modules\Finance\Accounting\Services\AccountMap::class)
                ->set('project_management', 'revenue.fees', '9999');

            $this->service()->generate($invoice);
        });

        $this->assertSame('generated', $invoice->fresh()->status, 'A ledger failure withdrew an issued invoice.');
        $this->assertNull($this->receivableFor($invoice));
    }

    /** A project with no client has nobody to owe the money. */
    public function test_a_project_without_a_client_issues_but_does_not_post(): void
    {
        $bare = $this->asCompany($this->company, fn () => $this->makeProject([
            'code' => 'MP23002', 'status' => 'active', 'client_ref_id' => null,
        ]));

        $invoice = $this->asCompany($this->company, function () use ($bare) {
            $i = $this->service()->startNextAccount($bare);
            $i->forceFill(['fees_now_claimed' => 5000, 'vat_rate' => 0.15])->save();

            return $i->fresh();
        });

        $this->asCompany($this->company, fn () => $this->service()->generate($invoice));

        $this->assertSame('generated', $invoice->fresh()->status);
        $this->assertNull($this->receivableFor($invoice));
    }
}
