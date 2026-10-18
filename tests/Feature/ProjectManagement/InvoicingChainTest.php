<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\Invoice;
use App\Modules\Operations\ProjectManagement\Models\InvoiceAnnexure;
use App\Modules\Operations\ProjectManagement\Models\InvoiceAnnexureItem;
use App\Modules\Operations\ProjectManagement\Models\InvoiceDisbursement;
use App\Modules\Operations\ProjectManagement\Models\InvoiceFeeStage;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Services\InvoicingService;
use App\Modules\Operations\ProjectManagement\Support\Invoicing\AnnexureCatalogue;
use App\Modules\Tenancy\Models\Company;

/**
 * The cumulative claim chain — the invariant the whole feature turns on.
 *
 * A professional fee account claims what a stage has earned to date **less what earlier accounts
 * already claimed against it**. The client's brief called this out as critical, and it is the one
 * rule a generic billing system gets wrong: bill the progress percentage again each period and the
 * client is charged several times for the same work.
 *
 * These tests run a project through three consecutive accounts and assert the chain holds.
 */
class InvoicingChainTest extends ProjectManagementTestCase
{
    private Company $company;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Aseda Test']);
        $this->entitleCompany($this->company);
    }

    private function inTenant(callable $callback): mixed
    {
        return $this->asCompany($this->company, $callback);
    }

    private function service(): InvoicingService
    {
        return app(InvoicingService::class);
    }

    /**
     * Account 1: a R33.1m job at 8%, with a single stage carrying the whole fee.
     *
     * Kept to one stage so the arithmetic in these tests is readable; the multi-stage apportionment
     * is covered exhaustively against the real workbook in InvoicingCalculationTest.
     */
    private function openFirstAccount(): Invoice
    {
        $this->project = $this->makeProject(['code' => 'MP23001', 'currency' => 'ZAR']);

        $invoice = $this->service()->startNextAccount($this->project, [
            'client_name'       => 'Thembisile Hani Local Municipality',
            'client_vat_number' => '4000000000',
            'vat_rate'          => '0.15',
        ]);

        $invoice->feeAccount->update([
            'total_value_of_works' => '33105272.80',
            'fee_percentage'       => '0.08',
        ]);

        InvoiceFeeStage::create([
            'invoice_id'    => $invoice->id,
            'code'          => '3.6',
            'label'         => 'Contract Admin & Inspection',
            'sort'          => 6,
            'apportionment' => '1',
            'progress'      => '0.10',
        ]);

        return $invoice->fresh();
    }

    // ── The chain ───────────────────────────────────────────────────────────

    public function test_the_first_account_claims_everything_earned_so_far(): void
    {
        $this->inTenant(function () {
            $invoice = $this->openFirstAccount();
            $this->service()->recalculate($invoice);

            $stage = $invoice->stages()->first();

            // 33 105 272.80 × 8% = 2 648 421.82 total fee; 10% of it earned.
            $this->assertSame('264842.18', $stage->value_to_date);
            $this->assertSame('0.00', $stage->previously_claimed);
            $this->assertSame('264842.18', $stage->this_account);
        });
    }

    /**
     * Account 2 claims the increment, not the whole of the new percentage.
     *
     * Progress moves 10% → 25%. The stage has now earned R662 105.46 in total, but R264 842.18 was
     * claimed on account 1, so account 2 may claim R397 263.28 and no more.
     */
    public function test_the_second_account_claims_only_the_increment(): void
    {
        $this->inTenant(function () {
            $first = $this->openFirstAccount();
            $this->service()->generate($first);

            $second = $this->service()->startNextAccount($this->project);
            $second->stages()->first()->update(['progress' => '0.25']);
            $this->service()->recalculate($second);

            $stage = $second->fresh()->stages()->first();

            $this->assertSame('662105.46', $stage->value_to_date);
            $this->assertSame('264842.18', $stage->previously_claimed);
            $this->assertSame('397263.28', $stage->this_account);
        });
    }

    /** Three accounts in, the sum of the claims still equals what has been earned. */
    public function test_the_claims_across_three_accounts_sum_to_the_work_earned(): void
    {
        $this->inTenant(function () {
            $first = $this->openFirstAccount();
            $this->service()->generate($first);

            $second = $this->service()->startNextAccount($this->project);
            $second->stages()->first()->update(['progress' => '0.25']);
            $this->service()->generate($second);

            $third = $this->service()->startNextAccount($this->project);
            $third->stages()->first()->update(['progress' => '0.60']);
            $this->service()->generate($third);

            $claimed = Invoice::forProject($this->project->id)
                ->claimable()
                ->get()
                ->sum(fn (Invoice $i) => (float) $i->stages()->first()->this_account);

            // 60% of a R2 648 421.82 fee.
            $this->assertEqualsWithDelta(1589053.09, $claimed, 0.01);
            $this->assertSame('1589053.09', $third->fresh()->stages()->first()->value_to_date);
        });
    }

    public function test_a_stage_that_does_not_move_claims_nothing(): void
    {
        $this->inTenant(function () {
            $first = $this->openFirstAccount();
            $this->service()->generate($first);

            $second = $this->service()->startNextAccount($this->project);
            // Progress carried forward unchanged — nothing new was done this period.
            $this->service()->recalculate($second);

            $this->assertSame('0.00', $second->fresh()->stages()->first()->this_account);
        });
    }

    // ── What counts as claimed ──────────────────────────────────────────────

    /** A draft has been issued to nobody, so it must not reduce the next account. */
    public function test_a_draft_account_does_not_count_as_claimed(): void
    {
        $this->inTenant(function () {
            $first = $this->openFirstAccount();
            $this->service()->recalculate($first); // left as a draft

            $second = $this->service()->startNextAccount($this->project);
            $second->stages()->first()->update(['progress' => '0.25']);
            $this->service()->recalculate($second);

            $this->assertSame(
                '0.00',
                $second->fresh()->stages()->first()->previously_claimed,
                'An unissued draft must not suppress the next account.',
            );
        });
    }

    /** Cancelling gives the work back to the next account. */
    public function test_a_cancelled_account_stops_counting_as_claimed(): void
    {
        $this->inTenant(function () {
            $first = $this->openFirstAccount();
            $this->service()->generate($first);
            $this->service()->cancel($first, 'Issued against the wrong certificate.');

            $second = $this->service()->startNextAccount($this->project);
            $second->stages()->first()->update(['progress' => '0.10']);
            $this->service()->recalculate($second);

            $stage = $second->fresh()->stages()->first();

            $this->assertSame('0.00', $stage->previously_claimed);
            $this->assertSame('264842.18', $stage->this_account);
        });
    }

    // ── Numbering and freezing ──────────────────────────────────────────────

    public function test_accounts_are_numbered_in_sequence_and_a_cancelled_number_is_not_reused(): void
    {
        $this->inTenant(function () {
            $first = $this->openFirstAccount();
            $this->service()->generate($first);
            $this->service()->cancel($first, 'Withdrawn.');

            $second = $this->service()->startNextAccount($this->project);

            $this->assertSame(1, $first->account_number);
            $this->assertSame(2, $second->account_number);
            $this->assertSame('MP23001/02', $second->invoice_number);
        });
    }

    public function test_an_issued_account_cannot_be_recalculated(): void
    {
        $this->inTenant(function () {
            $invoice = $this->openFirstAccount();
            $this->service()->generate($invoice);

            $this->expectException(\RuntimeException::class);

            $this->service()->recalculate($invoice->fresh());
        });
    }

    /** The client's details are copied forward so a VAT number is not retyped every month. */
    public function test_the_next_account_carries_the_clients_details_forward(): void
    {
        $this->inTenant(function () {
            $first = $this->openFirstAccount();
            $this->service()->generate($first);

            $second = $this->service()->startNextAccount($this->project);

            $this->assertSame('Thembisile Hani Local Municipality', $second->client_name);
            $this->assertSame('4000000000', $second->client_vat_number);
            $this->assertSame('0.150000', $second->vat_rate);
        });
    }

    /**
     * Correcting an earlier account must not restate one that has been issued.
     *
     * Previously-claimed figures are snapshotted onto an account when it is opened, not derived on
     * read. Account 2 has been signed and sent; editing account 1 afterwards cannot be allowed to
     * change what account 2 says it deducted.
     */
    public function test_an_issued_account_keeps_the_figures_it_was_issued_with(): void
    {
        $this->inTenant(function () {
            $first = $this->openFirstAccount();
            $this->service()->generate($first);

            $second = $this->service()->startNextAccount($this->project);
            $second->stages()->first()->update(['progress' => '0.25']);
            $this->service()->generate($second);

            $before = $second->fresh()->stages()->first()->previously_claimed;

            // Somebody meddles with the issued account 1 directly.
            $first->stages()->first()->update(['this_account' => '1.00']);

            $this->assertSame(
                $before,
                $second->fresh()->stages()->first()->previously_claimed,
                'A signed account must keep saying what it said when it was sent.',
            );
        });
    }

    // ── Annexures feeding the disbursement schedule ─────────────────────────

    public function test_an_annexure_totals_its_lines_and_feeds_the_disbursement_row(): void
    {
        $this->inTenant(function () {
            $invoice = $this->openFirstAccount();

            $annexure = InvoiceAnnexure::create([
                'invoice_id'     => $invoice->id,
                'number'         => 4,
                'title'          => AnnexureCatalogue::title(4),
                'line_type'      => AnnexureCatalogue::lineType(4),
                'surcharge_rate' => AnnexureCatalogue::surcharge(4),
            ]);

            // The workbook's own figure: a R113 011.57 appointment plus the 10% surcharge.
            InvoiceAnnexureItem::create([
                'annexure_id'        => $annexure->id,
                'party'              => 'Geotech SA',
                'appointment_amount' => '113011.57',
            ]);

            InvoiceDisbursement::create([
                'invoice_id'  => $invoice->id,
                'block'       => 'category',
                'description' => AnnexureCatalogue::summaryDescription(4),
                'reference'   => 'Annexure 4',
            ]);

            $this->service()->recalculate($invoice);

            $this->assertSame('124312.73', $annexure->fresh()->this_invoice);

            $row = InvoiceDisbursement::where('invoice_id', $invoice->id)->first();
            $this->assertSame('124312.73', $row->this_account);

            // And it reaches the Tax Invoice's three figures.
            $fresh = $invoice->fresh();
            $this->assertSame('124312.73', $fresh->disbursements_now_claimed);
            $this->assertSame('389154.91', $fresh->subtotal);      // 264842.18 + 124312.73
            $this->assertSame('58373.24', $fresh->vat_amount);
            $this->assertSame('447528.15', $fresh->amount_now_due);
        });
    }

    /** Every pm_ table is tenant-scoped; the invoicing ones are no exception. */
    public function test_an_account_belongs_to_its_tenant(): void
    {
        $invoice = $this->inTenant(fn () => $this->openFirstAccount());

        $this->assertSame($this->company->id, $invoice->company_id);

        $other = Company::create(['name' => 'Someone Else']);
        $this->asCompany($other, function () {
            $this->assertSame(0, Invoice::count(), 'Another tenant must not see these accounts.');
        });
    }
}
