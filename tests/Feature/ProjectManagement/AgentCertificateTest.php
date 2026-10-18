<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\Invoice;
use App\Modules\Operations\ProjectManagement\Models\InvoiceAgentCertificate;
use App\Modules\Operations\ProjectManagement\Models\InvoiceAgentPayment;
use App\Modules\Operations\ProjectManagement\Models\InvoicingSetting;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Tenancy\Models\Company;

/**
 * The Implementing Agent's payment certificate, driven the way a person drives it: over HTTP.
 *
 * The calculation is proved against the client's own workbook in the unit suite. What is proved
 * here is everything around it — that the certificate can only be added where the practice takes
 * these appointments, that the schedule accumulates by itself across accounts, and that the row
 * the invoice owns cannot be typed over.
 */
class AgentCertificateTest extends ProjectManagementTestCase
{
    private Company $company;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Aseda Implementing']);
        $this->entitleCompany($this->company);
        $this->manager->forceFill(['company_id' => $this->company->id])->save();

        $this->project = $this->asCompany(
            $this->company,
            fn () => $this->makeProject(['code' => 'MP23001', 'name' => 'Kwaggafontein Recreational Centre'])
        );
    }

    /** The practice says once that it takes these appointments. */
    private function enableImplementingAgent(): void
    {
        $this->asCompany($this->company, function () {
            InvoicingSetting::current()->update([
                'implementing_agent'     => true,
                'default_project_funder' => 'THLM / MIG',
            ]);
        });
    }

    private function openAccount(): Invoice
    {
        $this->actingAs($this->manager)
            ->post(route('project-management.invoicing.store', $this->project->uuid), [
                'client_name' => 'Thembisile Hani Local Municipality',
            ])->assertRedirect();

        return $this->asCompany(
            $this->company,
            fn () => Invoice::forProject($this->project->id)->latest('id')->first()
        );
    }

    private function certificateOf(Invoice $invoice): InvoiceAgentCertificate
    {
        return $this->asCompany(
            $this->company,
            fn () => InvoiceAgentCertificate::where('invoice_id', $invoice->id)->firstOrFail()
        );
    }

    /**
     * A practice that has not said it does this work cannot produce one by accident.
     *
     * The certificate commits a municipality's managers to signing a page. Nobody should reach it
     * by clicking through an invoice on a job where it does not apply.
     */
    public function test_the_certificate_cannot_be_added_unless_the_practice_takes_the_appointments(): void
    {
        $invoice = $this->openAccount();

        $this->actingAs($this->manager)
            ->post(route('project-management.invoicing.certificate.open', $invoice->uuid))
            ->assertForbidden();

        $this->asCompany($this->company, fn () => $this->assertDatabaseCount('pm_invoice_agent_certificates', 0));
    }

    /** And the invoice screen does not offer it. */
    public function test_the_invoice_screen_only_offers_the_certificate_where_it_applies(): void
    {
        $invoice = $this->openAccount();

        $this->actingAs($this->manager)
            ->get(route('project-management.invoicing.show', $invoice->uuid))
            ->assertInertia(fn ($page) => $page
                ->where('can.addCertificate', false)
                ->where('certificate', null));

        $this->enableImplementingAgent();

        $this->actingAs($this->manager)
            ->get(route('project-management.invoicing.show', $invoice->uuid))
            ->assertInertia(fn ($page) => $page->where('can.addCertificate', true));
    }

    /** Opening one seeds the workbook's six questions and the row this account claims. */
    public function test_opening_a_certificate_seeds_the_kpis_and_the_claim_row(): void
    {
        $this->enableImplementingAgent();
        $invoice = $this->openAccount();

        $this->actingAs($this->manager)
            ->post(route('project-management.invoicing.certificate.open', $invoice->uuid))
            ->assertRedirect();

        $certificate = $this->certificateOf($invoice);

        $this->asCompany($this->company, function () use ($certificate, $invoice) {
            $this->assertCount(6, $certificate->kpis);
            $this->assertSame('Is an EIA necessary?', $certificate->kpis->first()->question);
            // Unanswered, because an answer is a statement about this period.
            $this->assertNull($certificate->kpis->first()->answer);

            $this->assertSame('THLM / MIG', $certificate->project_funder);
            $this->assertSame((string) $invoice->account_number, $certificate->certificate_number);

            $claim = $certificate->payments()->where('is_current_claim', true)->first();
            $this->assertNotNull($claim, 'The schedule must carry the row this account claims.');
        });
    }

    /** Adding it twice is the same certificate, not two. */
    public function test_opening_is_idempotent(): void
    {
        $this->enableImplementingAgent();
        $invoice = $this->openAccount();

        foreach (range(1, 2) as $ignored) {
            $this->actingAs($this->manager)
                ->post(route('project-management.invoicing.certificate.open', $invoice->uuid))
                ->assertRedirect();
        }

        $this->asCompany($this->company, fn () => $this->assertDatabaseCount('pm_invoice_agent_certificates', 1));
    }

    /**
     * The figures reach the screen, calculated.
     *
     * 4 000 000 base × 115% is the ceiling; the construction chain runs off what was entered.
     */
    public function test_the_certificate_calculates_on_the_screen(): void
    {
        $this->enableImplementingAgent();
        $invoice = $this->openAccount();

        $this->actingAs($this->manager)
            ->post(route('project-management.invoicing.certificate.open', $invoice->uuid))->assertRedirect();

        $this->actingAs($this->manager)
            ->put(route('project-management.invoicing.certificate.update', $invoice->uuid), [
                'max_fee_base'      => '4000000.00',
                'work_done_to_date' => '1000000.00',
            ])->assertRedirect();

        $this->actingAs($this->manager)
            ->get(route('project-management.invoicing.show', $invoice->uuid))
            ->assertInertia(fn ($page) => $page
                ->where('certificate.calculated.management_fee.max_incl_vat', '4600000.00')
                // 1 000 000 + 15% = 1 150 000, retention 115 000, due 1 035 000.
                ->where('certificate.calculated.construction.work_done_incl_vat', '1150000.00')
                ->where('certificate.calculated.construction.retention', '115000.00')
                ->where('certificate.calculated.construction.due_for_construction', '1035000.00')
                ->where('certificate.calculated.management_fee.exceeds_maximum', false));
    }

    /** The sheet's warning is the point of the ceiling, so it has to reach the page. */
    public function test_the_over_ceiling_warning_reaches_the_screen(): void
    {
        $this->enableImplementingAgent();
        $invoice = $this->openAccount();

        $this->actingAs($this->manager)
            ->post(route('project-management.invoicing.certificate.open', $invoice->uuid))->assertRedirect();

        $this->actingAs($this->manager)
            ->put(route('project-management.invoicing.certificate.update', $invoice->uuid), [
                'max_fee_base' => '100.00',
            ])->assertRedirect();

        $this->actingAs($this->manager)
            ->post(route('project-management.invoicing.certificate.payments.store', $invoice->uuid), [
                'service_provider' => 'Aseda Consulting Engineers',
                'to_consultant'    => '900.00',
            ])->assertRedirect();

        $this->actingAs($this->manager)
            ->get(route('project-management.invoicing.show', $invoice->uuid))
            ->assertInertia(fn ($page) => $page
                ->where('certificate.calculated.management_fee.exceeds_maximum', true)
                ->where('certificate.calculated.management_fee.warning', 'YOU HAVE EXCEEDED MAX MANAGEMENT FEE !!!'));
    }

    /**
     * The claim row is the link to the invoice, so it cannot be typed over.
     *
     * If it could, a certificate would be able to certify a figure the account it belongs to does
     * not say — which is the one thing a certificate exists to prevent.
     */
    public function test_the_claim_row_cannot_be_edited_or_deleted(): void
    {
        $this->enableImplementingAgent();
        $invoice = $this->openAccount();

        $this->actingAs($this->manager)
            ->post(route('project-management.invoicing.certificate.open', $invoice->uuid))->assertRedirect();

        $claim = $this->asCompany($this->company, fn () => InvoiceAgentPayment::query()
            ->where('certificate_id', $this->certificateOf($invoice)->id)
            ->where('is_current_claim', true)->firstOrFail());

        $this->actingAs($this->manager)
            ->patch(route('project-management.invoicing.certificate.payments.update', [$invoice->uuid, $claim->uuid]), [
                'to_consultant' => '999999.00',
            ])->assertStatus(422);

        $this->actingAs($this->manager)
            ->delete(route('project-management.invoicing.certificate.payments.destroy', [$invoice->uuid, $claim->uuid]))
            ->assertStatus(422);
    }

    /** A historical row, by contrast, is entered and edited by hand. */
    public function test_a_payment_the_funder_made_is_entered_by_hand(): void
    {
        $this->enableImplementingAgent();
        $invoice = $this->openAccount();

        $this->actingAs($this->manager)
            ->post(route('project-management.invoicing.certificate.open', $invoice->uuid))->assertRedirect();

        $this->actingAs($this->manager)
            ->post(route('project-management.invoicing.certificate.payments.store', $invoice->uuid), [
                'paid_on'          => '2025-03-19',
                'service_provider' => 'Aseda Consulting Engineers',
                'to_consultant'    => '87991.61',
            ])->assertRedirect();

        $this->actingAs($this->manager)
            ->get(route('project-management.invoicing.show', $invoice->uuid))
            ->assertInertia(fn ($page) => $page
                ->where('certificate.calculated.management_fee.total_claimed', '87991.61'));
    }

    /**
     * The schedule accumulates across accounts, and that is what "claimed previously" is.
     *
     * Account 1 records a payment. Opening account 2's certificate copies it forward, so the
     * second certificate knows what was claimed before without anybody typing the figure.
     */
    public function test_the_schedule_carries_forward_to_the_next_account(): void
    {
        $this->enableImplementingAgent();

        $first = $this->openAccount();
        $this->actingAs($this->manager)
            ->post(route('project-management.invoicing.certificate.open', $first->uuid))->assertRedirect();
        $this->actingAs($this->manager)
            ->post(route('project-management.invoicing.certificate.payments.store', $first->uuid), [
                'paid_on'       => '2025-03-19',
                'to_consultant' => '87991.61',
            ])->assertRedirect();

        $this->actingAs($this->manager)
            ->post(route('project-management.invoicing.generate', $first->uuid))->assertRedirect();

        $second = $this->openAccount();
        $this->actingAs($this->manager)
            ->post(route('project-management.invoicing.certificate.open', $second->uuid))->assertRedirect();

        $this->asCompany($this->company, function () use ($second) {
            $certificate = InvoiceAgentCertificate::where('invoice_id', $second->id)->firstOrFail();

            // Two carried rows — the earlier payment and the first account's own claim — plus this
            // account's claim row, and every carried one is history now.
            $this->assertSame(2, $certificate->payments()->where('is_current_claim', false)->count());
            $this->assertSame(1, $certificate->payments()->where('is_current_claim', true)->count());

            $this->assertSame(
                '87991.61',
                \App\Modules\Operations\ProjectManagement\Support\Invoicing\Money::money(
                    $certificate->calculator()->claimedPreviously()
                ),
                'What the first account recorded is what the second says was claimed previously.',
            );
        });
    }

    /** It joins the document pack, and only when it exists. */
    public function test_the_certificate_appears_in_the_document_pack(): void
    {
        $this->enableImplementingAgent();
        $invoice = $this->openAccount();

        $this->actingAs($this->manager)
            ->get(route('project-management.invoicing.show', $invoice->uuid))
            ->assertInertia(fn ($page) => $page->where(
                'documents',
                fn ($documents) => ! collect($documents)->contains('kind', 'agent_certificate'),
            ));

        $this->actingAs($this->manager)
            ->post(route('project-management.invoicing.certificate.open', $invoice->uuid))->assertRedirect();

        $this->actingAs($this->manager)
            ->get(route('project-management.invoicing.show', $invoice->uuid))
            ->assertInertia(fn ($page) => $page->where(
                'documents',
                fn ($documents) => collect($documents)->contains('kind', 'agent_certificate'),
            ));
    }

    /** And it downloads. */
    public function test_the_certificate_downloads(): void
    {
        $this->enableImplementingAgent();
        $invoice = $this->openAccount();

        $this->actingAs($this->manager)
            ->post(route('project-management.invoicing.certificate.open', $invoice->uuid))->assertRedirect();

        $this->actingAs($this->manager)
            ->get(route('project-management.invoicing.download', [$invoice->uuid, 'agent_certificate']))
            ->assertOk();
    }

    /** A KPI is answered from the list the workbook uses. */
    public function test_a_kpi_is_answered(): void
    {
        $this->enableImplementingAgent();
        $invoice = $this->openAccount();

        $this->actingAs($this->manager)
            ->post(route('project-management.invoicing.certificate.open', $invoice->uuid))->assertRedirect();

        $kpi = $this->asCompany($this->company, fn () => $this->certificateOf($invoice)->kpis()->first());

        $this->actingAs($this->manager)
            ->patch(route('project-management.invoicing.certificate.kpis.update', [$invoice->uuid, $kpi->uuid]), [
                'answer' => 'YES',
            ])->assertRedirect();

        $this->asCompany($this->company, fn () => $this->assertSame('YES', $kpi->fresh()->answer));

        // Anything outside the form's own vocabulary is refused.
        $this->actingAs($this->manager)
            ->patch(route('project-management.invoicing.certificate.kpis.update', [$invoice->uuid, $kpi->uuid]), [
                'answer' => 'Maybe',
            ])->assertSessionHasErrors('answer');
    }

    /** One certificate belongs to one account, and never leaks to another tenant. */
    public function test_a_certificate_is_scoped_to_its_company(): void
    {
        $this->enableImplementingAgent();
        $invoice = $this->openAccount();

        $this->actingAs($this->manager)
            ->post(route('project-management.invoicing.certificate.open', $invoice->uuid))->assertRedirect();

        $other = Company::create(['name' => 'Someone Else']);

        $this->asCompany($other, fn () => $this->assertSame(
            0,
            InvoiceAgentCertificate::query()->count(),
            'Row-level security must keep another tenant from seeing this certificate.',
        ));
    }
}
