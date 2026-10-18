<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\Invoice;
use App\Modules\Operations\ProjectManagement\Models\InvoiceFeeStage;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Services\InvoicingService;
use App\Modules\Operations\ProjectManagement\Support\RoleCatalog;
use App\Modules\Tenancy\Models\Company;

/**
 * The invoicing screens: who may reach them, and what reaches the browser.
 *
 * An account is nothing but cost — a fee percentage, a client's VAT number, every previously
 * claimed figure. Elsewhere in this module a viewer without `pm.costs.view` gets the page with the
 * money removed; here that would be an empty form, so the routes are refused outright. These tests
 * are mostly about that refusal holding.
 */
class InvoicingScreenTest extends ProjectManagementTestCase
{
    private Company $company;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Invoicing Screens']);
        $this->entitleCompany($this->company);
        $this->manager->forceFill(['company_id' => $this->company->id])->save();
        $this->fieldWorker->forceFill(['company_id' => $this->company->id])->save();

        $this->project = $this->asCompany(
            $this->company,
            fn () => $this->makeProject(['code' => 'MP23001', 'name' => 'Kwaggafontein Recreational Centre'])
        );
    }

    private function anAccount(): Invoice
    {
        return $this->asCompany($this->company, function () {
            $invoice = app(InvoicingService::class)->startNextAccount($this->project, [
                'client_name'       => 'Thembisile Hani Local Municipality',
                'client_vat_number' => '4000000000',
            ]);

            $invoice->feeAccount->update(['total_value_of_works' => '33105272.80', 'fee_percentage' => '0.08']);

            InvoiceFeeStage::create([
                'invoice_id'    => $invoice->id,
                'code'          => '3.6',
                'label'         => 'Contract Admin & Inspection',
                'apportionment' => '1',
                'progress'      => '0.10',
            ]);

            return app(InvoicingService::class)->recalculate($invoice->fresh());
        });
    }

    // ── Access ──────────────────────────────────────────────────────────────

    public function test_the_register_loads_for_someone_who_may_see_cost(): void
    {
        $this->anAccount();

        $response = $this->actingAs($this->manager)->get(route('project-management.invoicing.index'));

        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertCount(1, $props['invoices']['data']);
        $this->assertSame('MP23001/01', $props['invoices']['data'][0]['invoice_number']);
    }

    /**
     * The field worker has `pm.projects.view` but not `pm.costs.view`.
     *
     * There is no redacted view of an account, so this is a 403 rather than a thinner page.
     */
    public function test_somebody_without_cost_visibility_is_refused(): void
    {
        $this->anAccount();

        $this->actingAs($this->fieldWorker)
            ->get(route('project-management.invoicing.index'))
            ->assertForbidden();
    }

    public function test_an_outsider_cannot_reach_an_account(): void
    {
        $invoice = $this->anAccount();

        $this->actingAs($this->outsider)
            ->get(route('project-management.invoicing.show', $invoice->uuid))
            ->assertForbidden();
    }

    // ── Payloads ────────────────────────────────────────────────────────────

    public function test_the_account_page_carries_the_forms_and_the_totals(): void
    {
        $invoice = $this->anAccount();

        $response = $this->actingAs($this->manager)
            ->get(route('project-management.invoicing.show', $invoice->uuid));

        $response->assertOk();
        $props = $response->viewData('page')['props'];

        // 33 105 272.80 × 8% × 10% = 264 842.18, +15% VAT.
        $this->assertSame('264842.18', $props['invoice']['fees_now_claimed']);
        $this->assertSame('39726.33', $props['invoice']['vat_amount']);
        $this->assertSame('304568.51', $props['invoice']['amount_now_due']);

        $this->assertSame('2648421.82', $props['feeAccount']['total_fee']);
        $this->assertArrayHasKey('fee_block', $props['disbursements']);
        $this->assertCount(1, $props['invoice']['stages']);
    }

    /**
     * The 14% block reports itself to the screen rather than to nobody.
     *
     * The account carries the workbook's rate as issued; the page shows a warning off this flag so
     * a reader is told, instead of being left to notice a rounding difference.
     */
    public function test_the_page_is_told_when_the_disbursement_rate_disagrees_with_vat(): void
    {
        $invoice = $this->anAccount();

        $props = $this->actingAs($this->manager)
            ->get(route('project-management.invoicing.show', $invoice->uuid))
            ->viewData('page')['props'];

        $this->assertTrue($props['disbursements']['fee_block']['rate_looks_stale']);
        $this->assertSame('0.140000', $props['invoice']['disbursement_fee_rate']);
        $this->assertSame('0.150000', $props['invoice']['vat_rate']);
    }

    /** Payloads are built field by field, so nothing arrives that was not chosen. */
    public function test_the_payload_carries_no_internal_identifiers(): void
    {
        $invoice = $this->anAccount();

        $props = $this->actingAs($this->manager)
            ->get(route('project-management.invoicing.show', $invoice->uuid))
            ->viewData('page')['props'];

        $this->assertArrayNotHasKey('project_id', $props['invoice']);
        $this->assertArrayNotHasKey('client_ref_id', $props['invoice']);
        $this->assertSame($invoice->uuid, $props['invoice']['id']);
    }

    // ── Actions ─────────────────────────────────────────────────────────────

    public function test_a_manager_can_open_the_next_account_from_the_screen(): void
    {
        $this->anAccount();

        $this->actingAs($this->manager)
            ->post(route('project-management.invoicing.store', $this->project->uuid), [])
            ->assertRedirect();

        $this->asCompany($this->company, function () {
            $this->assertSame(2, Invoice::forProject($this->project->id)->max('account_number'));
        });
    }

    public function test_generating_freezes_the_account_and_a_second_attempt_is_refused(): void
    {
        $invoice = $this->anAccount();

        $this->actingAs($this->manager)
            ->post(route('project-management.invoicing.generate', $invoice->uuid))
            ->assertRedirect();

        $this->asCompany($this->company, function () use ($invoice) {
            $this->assertSame('generated', $invoice->fresh()->status);
        });

        $this->actingAs($this->manager)
            ->post(route('project-management.invoicing.recalculate', $invoice->uuid))
            ->assertSessionHasErrors('invoice');
    }

    public function test_cancelling_requires_a_reason(): void
    {
        $invoice = $this->anAccount();

        $this->actingAs($this->manager)
            ->post(route('project-management.invoicing.cancel', $invoice->uuid), [])
            ->assertSessionHasErrors('reason');
    }

    public function test_adding_an_annexure_also_creates_the_disbursement_row_it_feeds(): void
    {
        $invoice = $this->anAccount();

        $this->actingAs($this->manager)
            ->post(route('project-management.invoicing.annexures.store', $invoice->uuid), ['number' => 4])
            ->assertRedirect();

        $this->asCompany($this->company, function () use ($invoice) {
            $this->assertSame(1, $invoice->annexures()->count());
            $this->assertSame(
                'Geotechnical engineer',
                $invoice->disbursements()->where('block', 'category')->value('description'),
            );
        });
    }

    public function test_an_annexure_that_is_not_in_the_catalogue_is_refused(): void
    {
        $invoice = $this->anAccount();

        // The workbook has no annexure 9 — the numbering gap is real, not a transcription slip.
        $this->actingAs($this->manager)
            ->post(route('project-management.invoicing.annexures.store', $invoice->uuid), ['number' => 9])
            ->assertSessionHasErrors('number');
    }

    /** Moving a stage's progress is the number a user changes each period. */
    public function test_a_stage_progress_can_be_updated_and_recalculated(): void
    {
        $invoice = $this->anAccount();
        $stage = $this->asCompany($this->company, fn () => $invoice->stages()->first());

        $this->actingAs($this->manager)->patch(
            route('project-management.invoicing.stages.update', [$invoice->uuid, $stage->uuid]),
            ['progress' => '0.25'],
        )->assertRedirect();

        $this->actingAs($this->manager)
            ->post(route('project-management.invoicing.recalculate', $invoice->uuid))
            ->assertRedirect();

        $this->asCompany($this->company, function () use ($invoice) {
            $this->assertSame('662105.46', $invoice->fresh()->stages()->first()->value_to_date);
        });
    }

    public function test_the_cashflow_page_loads(): void
    {
        $this->actingAs($this->manager)
            ->get(route('project-management.invoicing.cashflow'))
            ->assertOk();
    }

    /** The hub landing exists and is reachable, which is what puts the ribbon in the menu. */
    public function test_the_invoicing_hub_landing_loads(): void
    {
        $this->manager->givePermissionTo(RoleCatalog::VIEW_COSTS);

        $this->actingAs($this->manager)
            ->get(route('project-management.hub.invoicing'))
            ->assertOk();
    }

    // ── Reached from the project ────────────────────────────────────────────

    /**
     * The tab on a project lands on that job's accounts, not the whole portfolio.
     *
     * An account belongs to a job, so the project page needs a way in that is already scoped.
     */
    public function test_a_project_has_its_own_scoped_account_register(): void
    {
        $this->anAccount();

        $response = $this->actingAs($this->manager)
            ->get(route('project-management.projects.invoicing', $this->project->uuid));

        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertSame('MP23001', $props['scopedTo']['code']);
        $this->assertCount(1, $props['invoices']['data']);
        // Only this project is offered, so the filter cannot wander off the job.
        $this->assertCount(1, $props['projects']);
    }

    /** The project page only draws the tab for somebody the invoicing routes would admit. */
    public function test_the_project_page_reports_whether_invoicing_is_reachable(): void
    {
        $manager = $this->actingAs($this->manager)
            ->get(route('project-management.projects.show', $this->project->uuid))
            ->viewData('page')['props'];

        $this->assertTrue($manager['can']['viewInvoicing']);

        $field = $this->actingAs($this->fieldWorker)
            ->get(route('project-management.projects.show', $this->project->uuid))
            ->viewData('page')['props'];

        $this->assertFalse($field['can']['viewInvoicing']);
    }

    public function test_the_scoped_register_is_refused_without_cost_visibility(): void
    {
        $this->actingAs($this->fieldWorker)
            ->get(route('project-management.projects.invoicing', $this->project->uuid))
            ->assertForbidden();
    }
}
