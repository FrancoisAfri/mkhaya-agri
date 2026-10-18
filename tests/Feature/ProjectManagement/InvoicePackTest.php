<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\Invoice;
use App\Modules\Operations\ProjectManagement\Models\InvoiceAnnexure;
use App\Modules\Operations\ProjectManagement\Models\InvoiceAnnexureItem;
use App\Modules\Operations\ProjectManagement\Models\InvoiceCashflow;
use App\Modules\Operations\ProjectManagement\Models\InvoiceDisbursement;
use App\Modules\Operations\ProjectManagement\Models\InvoiceFeeStage;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Services\InvoicePackService;
use App\Modules\Operations\ProjectManagement\Services\InvoicingService;
use App\Modules\Operations\ProjectManagement\Support\Invoicing\AnnexureCatalogue;
use App\Modules\Tenancy\Models\Company;

/**
 * The account pack: the documents a client receives.
 *
 * Tax Invoice, Summary, Form 1, Form 2, the annexures actually used, and the cashflow — as PDF or
 * as a workbook. The tests care about two things: that the figures on a document are the ones
 * stored on the account, and that the pack contains what the account has rather than a fixed set
 * of thirteen mostly-empty annexures.
 */
class InvoicePackTest extends ProjectManagementTestCase
{
    private Company $company;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Aseda Consulting Engineers (Pty) Ltd']);
        $this->entitleCompany($this->company);
        $this->manager->forceFill(['company_id' => $this->company->id])->save();
        $this->fieldWorker->forceFill(['company_id' => $this->company->id])->save();

        $this->project = $this->asCompany(
            $this->company,
            fn () => $this->makeProject(['code' => 'MP23001', 'name' => 'Kwaggafontein Recreational Centre'])
        );
    }

    private function anAccount(bool $withAnnexure = true): Invoice
    {
        return $this->asCompany($this->company, function () use ($withAnnexure) {
            $service = app(InvoicingService::class);

            $invoice = $service->startNextAccount($this->project, [
                'client_name'       => 'Thembisile Hani Local Municipality',
                'client_vat_number' => '4000000000',
                'attention'         => 'The Municipal Manager',
                'consultant_name'   => 'Aseda Consulting Engineers (Pty) Ltd',
                'description'       => 'Professional Fee and disbursements account',
            ]);

            $invoice->feeAccount->update([
                'total_value_of_works' => '33105272.80',
                'fee_percentage'       => '0.08',
                'payment_certificate'  => 'PC-07',
            ]);

            InvoiceFeeStage::create([
                'invoice_id'    => $invoice->id,
                'code'          => '3.6',
                'label'         => 'Contract Admin & Inspection',
                'apportionment' => '1',
                'progress'      => '0.10',
            ]);

            if ($withAnnexure) {
                $annexure = InvoiceAnnexure::create([
                    'invoice_id'     => $invoice->id,
                    'number'         => 4,
                    'title'          => AnnexureCatalogue::title(4),
                    'line_type'      => AnnexureCatalogue::lineType(4),
                    'surcharge_rate' => AnnexureCatalogue::surcharge(4),
                ]);

                InvoiceAnnexureItem::create([
                    'annexure_id'        => $annexure->id,
                    'party'              => 'Geotech SA',
                    'description'        => 'Geotechnical investigation',
                    'appointment_amount' => '113011.57',
                ]);

                InvoiceDisbursement::create([
                    'invoice_id'  => $invoice->id,
                    'block'       => 'category',
                    'description' => AnnexureCatalogue::summaryDescription(4),
                    'reference'   => 'Annexure 4',
                ]);
            }

            return $service->recalculate($invoice->fresh());
        });
    }

    // ── What the pack contains ──────────────────────────────────────────────

    public function test_the_pack_lists_only_the_annexures_the_account_actually_has(): void
    {
        $invoice = $this->anAccount();

        $available = $this->asCompany($this->company, fn () => app(InvoicePackService::class)->available($invoice));

        $this->assertArrayHasKey('tax_invoice', $available);
        $this->assertArrayHasKey('form_1', $available);
        $this->assertArrayHasKey('form_2', $available);
        $this->assertArrayHasKey('summary', $available);
        $this->assertArrayHasKey('annexure_4', $available);
        // A pack printing all thirteen with ten empty is not the client's document.
        $this->assertArrayNotHasKey('annexure_1', $available);
        $this->assertArrayNotHasKey('annexure_13', $available);
    }

    public function test_the_cashflow_appears_only_once_there_is_one(): void
    {
        $invoice = $this->anAccount();

        $this->asCompany($this->company, function () use ($invoice) {
            $this->assertArrayNotHasKey('cashflow', app(InvoicePackService::class)->available($invoice));

            InvoiceCashflow::create([
                'project_id'      => $this->project->id,
                'forecast_year'   => 2026,
                'forecast_month'  => 3,
                'amount_incl_vat' => '95000.00',
            ]);

            $this->assertArrayHasKey('cashflow', app(InvoicePackService::class)->available($invoice));
        });
    }

    /** Every figure on a document is read from the account, never recalculated. */
    public function test_the_tax_invoice_carries_the_accounts_own_figures(): void
    {
        $invoice = $this->anAccount();

        $document = $this->asCompany(
            $this->company,
            fn () => app(InvoicePackService::class)->document($invoice, 'tax_invoice')
        );

        $this->assertSame('TAX INVOICE', $document['title']);
        // 33 105 272.80 × 8% × 10% fees, plus a R113 011.57 appointment at +10%.
        $this->assertSame('264842.18', $document['body']['lines'][0]['amount']);
        $this->assertSame('124312.73', $document['body']['lines'][1]['amount']);
        $this->assertSame('389154.91', $document['body']['subtotal']);
        $this->assertSame('58373.24', $document['body']['vat']);
        $this->assertSame('447528.15', $document['body']['total']);
    }

    public function test_form_one_prints_the_clients_own_numbering(): void
    {
        $invoice = $this->anAccount();

        $document = $this->asCompany(
            $this->company,
            fn () => app(InvoicePackService::class)->document($invoice, 'form_1')
        );

        $this->assertSame('landscape', $document['orientation']);
        $this->assertSame('3.6', $document['body']['stages'][0]['code']);
        $this->assertSame('8%', $document['body']['fee_percentage']);
        $this->assertSame('264842.18', $document['body']['totals']['this_account']['excl_vat']);
    }

    /** The annexure template is one file; the columns come from the line type. */
    public function test_an_annexure_gets_the_columns_its_line_type_needs(): void
    {
        $invoice = $this->anAccount();

        $document = $this->asCompany(
            $this->company,
            fn () => app(InvoicePackService::class)->document($invoice, 'annexure_4')
        );

        $this->assertStringContainsString('GEOTECHNICAL', $document['title']);
        $this->assertContains('Appointment amount', $document['body']['columns']);
        $this->assertSame('124312.73', $document['body']['totals']['this_invoice']);
    }

    // ── Rendering ───────────────────────────────────────────────────────────

    public function test_a_single_document_downloads_as_a_pdf(): void
    {
        $invoice = $this->anAccount();

        $response = $this->actingAs($this->manager)
            ->get(route('project-management.invoicing.download', [$invoice->uuid, 'tax_invoice']));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        // DomPDF returns a plain response, not a streamed one — unlike the XLSX writer below.
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_the_whole_pack_renders_in_one_pdf(): void
    {
        $invoice = $this->anAccount();

        $response = $this->actingAs($this->manager)
            ->get(route('project-management.invoicing.download', [$invoice->uuid, 'pack']));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_a_document_downloads_as_a_workbook(): void
    {
        $invoice = $this->anAccount();

        $response = $this->actingAs($this->manager)->get(
            route('project-management.invoicing.download', [$invoice->uuid, 'form_1']) . '?format=xlsx'
        );

        $response->assertOk();
        $response->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
    }

    public function test_an_unknown_document_or_format_is_refused(): void
    {
        $invoice = $this->anAccount();

        $this->actingAs($this->manager)
            ->get(route('project-management.invoicing.download', [$invoice->uuid, 'annexure_13']))
            ->assertNotFound();

        $this->actingAs($this->manager)
            ->get(route('project-management.invoicing.download', [$invoice->uuid, 'summary']) . '?format=docx')
            ->assertNotFound();
    }

    /** An account is nothing but cost, so the documents are refused outright too. */
    public function test_somebody_without_cost_visibility_cannot_download_anything(): void
    {
        $invoice = $this->anAccount();

        $this->actingAs($this->fieldWorker)
            ->get(route('project-management.invoicing.download', [$invoice->uuid, 'tax_invoice']))
            ->assertForbidden();
    }

    /**
     * A user-supplied value that Excel would run is neutralised.
     *
     * Client names, descriptions and travel destinations all come from people, and a leading `=`
     * makes a cell a formula in the spreadsheet that opens it.
     */
    public function test_a_formula_in_user_text_cannot_reach_a_spreadsheet_live(): void
    {
        $invoice = $this->anAccount();

        $this->asCompany($this->company, function () use ($invoice) {
            $invoice->annexures()->first()->items()->first()
                ->update(['description' => '=HYPERLINK("http://evil.test","click")']);
        });

        $response = $this->actingAs($this->manager)->get(
            route('project-management.invoicing.download', [$invoice->uuid, 'annexure_4']) . '?format=xlsx'
        );

        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'pack') . '.xlsx';
        file_put_contents($path, $response->streamedContent());
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $values = $book->getActiveSheet()->toArray();
        @unlink($path);

        $flat = collect($values)->flatten()->filter()->implode(' | ');

        $this->assertStringContainsString("'=HYPERLINK", $flat, 'The formula must arrive quoted, not live.');
    }
}
