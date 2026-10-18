<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Finance\Accounting\Models\FinSetting;
use App\Modules\Operations\ProjectManagement\Models\InvoicingSetting;
use App\Modules\Operations\ProjectManagement\Services\InvoicingService;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * An invoice knows who it is from.
 *
 * The details were never missing from the system — they were sitting in Company Setup, where
 * somebody had typed them once. Project Management read only its own invoicing settings, so on an
 * installation where nobody had filled those in a second time, every invoice went out with no
 * practice name, no address and no VAT number: the PDF fell back to `config('app.name')` for the
 * sender and printed an empty "To:".
 *
 * A VAT invoice without the supplier's VAT number is not a valid tax invoice, so this is a
 * compliance matter rather than a cosmetic one.
 */
class InvoiceUsesCompanySetupTest extends ProjectManagementTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Aseda Test']);
        $this->entitleCompany($this->company);
    }

    private function service(): InvoicingService
    {
        return app(InvoicingService::class);
    }

    /** Company Setup as somebody would have filled it in. */
    private function companySetup(array $overrides = []): void
    {
        DB::table('fin_settings')->insert(array_merge([
            'company_id' => $this->company->id,
            'entity_id' => $this->businessFor($this->company->id),
            'company_name' => 'Mkhaya MK Pty (Ltd)',
            'trading_name' => 'Mkhaya MK',
            'address' => 'Design Quarter, Leslie Rd, Fourways, Johannesburg, 2191',
            'vat_number' => '4370228530',
            'registration_number' => '2019/023490/07',
            'bank_name' => 'FNB',
            'bank_account_name' => 'MKhaya MK (PTY) LTD',
            'bank_account_number' => '62700170082',
            'branch_code' => '270243',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_a_new_invoice_takes_the_practice_details_from_company_setup(): void
    {
        $invoice = $this->asCompany($this->company, function () {
            $this->companySetup();

            return $this->service()->startNextAccount($this->makeProject());
        });

        $this->assertSame('Mkhaya MK', $invoice->consultant_name,
            'The invoice does not know who it is from.');
        $this->assertStringContainsString('Fourways', (string) $invoice->consultant_address);
        // Without this the document is not a valid tax invoice.
        $this->assertSame('4370228530', $invoice->consultant_vat_number);
    }

    public function test_the_banking_details_come_across_too(): void
    {
        $invoice = $this->asCompany($this->company, function () {
            $this->companySetup();

            return $this->service()->startNextAccount($this->makeProject());
        });

        $this->assertSame('FNB', $invoice->banking_name);
        $this->assertSame('62700170082', $invoice->banking_account_number);
        $this->assertSame('270243', $invoice->banking_branch_code);
    }

    /**
     * The trading name is what a client recognises on an invoice. The registered name is the
     * fallback, and it is the one that appears on the VAT return.
     */
    public function test_the_registered_name_is_used_when_there_is_no_trading_name(): void
    {
        $invoice = $this->asCompany($this->company, function () {
            $this->companySetup(['trading_name' => null]);

            return $this->service()->startNextAccount($this->makeProject());
        });

        $this->assertSame('Mkhaya MK Pty (Ltd)', $invoice->consultant_name);
    }

    /**
     * A practice invoicing under an arrangement of its own is a real thing, and this is where it is
     * expressed — the company record is the fallback beneath it, not a replacement for it.
     */
    public function test_project_managements_own_setting_still_wins(): void
    {
        $invoice = $this->asCompany($this->company, function () {
            $this->companySetup();

            InvoicingSetting::current()->fill([
                'consultant_name' => 'Mkhaya Engineering Practice',
                'consultant_vat_number' => '4999999999',
            ])->save();

            return $this->service()->startNextAccount($this->makeProject());
        });

        $this->assertSame('Mkhaya Engineering Practice', $invoice->consultant_name);
        $this->assertSame('4999999999', $invoice->consultant_vat_number);
        // Still filled from Company Setup, because PM's settings say nothing about it.
        $this->assertStringContainsString('Fourways', (string) $invoice->consultant_address);
    }

    /**
     * What the caller passes is the most specific answer there is, and it must survive both
     * fallbacks — otherwise correcting a detail on one invoice would be silently undone.
     */
    public function test_what_the_caller_passes_still_wins_over_both(): void
    {
        $invoice = $this->asCompany($this->company, function () {
            $this->companySetup();

            return $this->service()->startNextAccount($this->makeProject(), [
                'consultant_name' => 'Typed on this invoice',
            ]);
        });

        $this->assertSame('Typed on this invoice', $invoice->consultant_name);
    }

    /**
     * Project Management must not stop issuing invoices because Finance is not installed. With no
     * company record to read, this is exactly where it was before.
     */
    public function test_an_invoice_still_opens_with_nothing_in_company_setup(): void
    {
        $invoice = $this->asCompany($this->company, fn () => $this->service()->startNextAccount($this->makeProject()));

        $this->assertNotNull($invoice->invoice_number, 'The invoice could not be opened at all.');
        $this->assertNull($invoice->consultant_name);
    }

    /** One tenant's letterhead must never appear on another tenant's invoice. */
    public function test_the_details_do_not_cross_between_tenants(): void
    {
        $this->asCompany($this->company, fn () => $this->companySetup());

        $other = Company::create(['name' => 'Another Practice']);
        $this->entitleCompany($other);

        $invoice = $this->asCompany($other, fn () => $this->service()->startNextAccount(
            $this->makeProject(['company_id' => $other->id])
        ));

        $this->assertNull($invoice->consultant_name,
            "Another tenant's practice name was printed on this tenant's invoice.");
    }

    // ── The client's half of the same document ───────────────────────────────

    /**
     * A client added from a project screen arrives on its first invoice complete.
     *
     * The add-client form took a name, an email and a town. The client's first invoice then
     * printed an empty "To:" block and no VAT number, and somebody retyped both from memory onto
     * a document that is not a valid tax invoice without them.
     */
    public function test_a_client_added_with_its_details_reaches_the_invoice(): void
    {
        $invoice = $this->asCompany($this->company, function () {
            $this->actingAs($this->manager)
                ->post(route('project-management.clients.store'), [
                    'name' => 'Osizweni Education & Development',
                    'email' => 'accounts@osizweni.org.za',
                    'address' => "P.O. Box 6377\nSecunda\n2302",
                    'vat_number' => '4370228530',
                ])->assertRedirect();

            $ref = \App\Modules\Operations\ProjectManagement\Models\ExternalRef::query()
                ->where('local_type', 'project_client')
                ->latest('id')->firstOrFail();

            $project = $this->makeProject(['client_ref_id' => $ref->id]);

            return $this->service()->startNextAccount($project);
        });

        $this->assertSame('Osizweni Education & Development', $invoice->client_name);
        $this->assertStringContainsString('Secunda', (string) $invoice->client_address,
            'The invoice would have printed an empty "To:" block.');
        $this->assertSame('4370228530', $invoice->client_vat_number,
            'Without the VAT number the document is not a valid tax invoice.');
    }

    /**
     * The VAT number survives the client being resolved.
     *
     * `refFor` replaces the snapshot with whatever the provider reports, and remembering the
     * invoicing details merges into it — so doing them in the wrong order silently discards the
     * number somebody has just typed. This is that ordering, asserted.
     */
    public function test_the_vat_number_survives_the_client_being_resolved(): void
    {
        $ref = $this->asCompany($this->company, function () {
            $this->actingAs($this->manager)
                ->post(route('project-management.clients.store'), [
                    'name' => 'A Client With Tax Numbers',
                    'vat_number' => '4999999999',
                ])->assertRedirect();

            return \App\Modules\Operations\ProjectManagement\Models\ExternalRef::query()
                ->where('local_type', 'project_client')
                ->latest('id')->firstOrFail();
        });

        $this->assertSame('4999999999', $ref->snapshot['vat_number'] ?? null);
    }
}
