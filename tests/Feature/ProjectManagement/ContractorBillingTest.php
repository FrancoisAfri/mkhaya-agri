<?php

namespace Tests\Feature\ProjectManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\ProjectManagement\Models\ProjectContract;
use App\Modules\Operations\ProjectManagement\Models\ContractorInvoice;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Tenancy\Models\Company;

/**
 * Subcontractor contracts and billing, driven over HTTP from both sides of the wall.
 *
 * The contractor submits from the portal; the project manager decides from the module. Most of
 * what is asserted here is that boundary — because the one rule the client stated twice is that
 * the markup is ours and must not be visible to the people it is applied to.
 */
class ContractorBillingTest extends ProjectManagementTestCase
{
    private Company $company;

    private Project $project;

    private ProjectMember $contractor;

    private User $contractorUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Aseda Subcontracting']);
        $this->entitleCompany($this->company);
        $this->manager->forceFill(['company_id' => $this->company->id])->save();

        $this->project = $this->asCompany(
            $this->company,
            fn () => $this->makeProject(['code' => 'MP23001', 'name' => 'Kwaggafontein Recreational Centre'])
        );

        // `users` has no name column in this schema — a member's display name lives on the
        // membership, which is where every screen reads it from.
        $this->contractorUser = User::factory()->create([
            'company_id' => $this->company->id,
            'email'      => 'accounts@sizweplant.co.za',
            'status'     => 1,
        ]);

        $this->contractor = $this->asCompany($this->company, fn () => ProjectMember::create([
            'project_id'   => $this->project->id,
            'user_id'      => $this->contractorUser->id,
            'role_key'     => 'contractor',
            'display_name' => 'Sizwe Plant Hire',
            'email'        => 'accounts@sizweplant.co.za',
            'is_active'    => true,
        ]));
    }

    /** Open a draft, put a line on it, and send it. Returns the submitted invoice. */
    private function submitAnInvoice(string $rate = '2500.00', string $quantity = '4'): ContractorInvoice
    {
        $this->actingAs($this->contractorUser)
            ->post(route('project-management.portal.invoices.store', $this->project->uuid), [
                'reference'   => 'SPH-0042',
                'description' => 'Plant hire, February',
                'vat_rate'    => '0.15',
            ])->assertRedirect();

        $invoice = $this->asCompany($this->company, fn () => ContractorInvoice::query()->latest('id')->firstOrFail());

        $this->actingAs($this->contractorUser)
            ->post(route('project-management.portal.invoices.lines.store', [$this->project->uuid, $invoice->uuid]), [
                'description' => 'TLB, day rate',
                'quantity'    => $quantity,
                'rate'        => $rate,
            ])->assertRedirect();

        $this->actingAs($this->contractorUser)
            ->post(route('project-management.portal.invoices.submit', [$this->project->uuid, $invoice->uuid]))
            ->assertRedirect();

        return $this->asCompany($this->company, fn () => $invoice->fresh());
    }

    /** Raise a contract for the contractor on this project, through PM's own register. */
    private function draftContract(array $overrides = []): void
    {
        $this->actingAs($this->manager)
            ->post(route('project-management.contracts.store'), array_merge([
                'project'        => $this->project->uuid,
                'member'         => $this->contractor->uuid,
                'contract_value' => '250000.00',
            ], $overrides))
            ->assertRedirect();
    }

    /** The contractor's own totals: 4 × 2 500 = 10 000, plus 15% = 11 500. */
    public function test_a_contractor_prepares_and_submits_an_invoice(): void
    {
        $invoice = $this->submitAnInvoice();

        $this->asCompany($this->company, function () use ($invoice) {
            $this->assertSame('submitted', $invoice->status);
            $this->assertSame('10000.00', (string) $invoice->subtotal);
            $this->assertSame('1500.00', (string) $invoice->vat_amount);
            $this->assertSame('11500.00', (string) $invoice->total);
            $this->assertNotNull($invoice->submitted_at);
        });
    }

    /**
     * An invoice with nothing on it is refused at the door.
     *
     * Kinder than a project manager rejecting an empty claim a week later.
     */
    public function test_an_empty_invoice_cannot_be_sent(): void
    {
        $this->actingAs($this->contractorUser)
            ->post(route('project-management.portal.invoices.store', $this->project->uuid))
            ->assertRedirect();

        $invoice = $this->asCompany($this->company, fn () => ContractorInvoice::query()->latest('id')->firstOrFail());

        $this->actingAs($this->contractorUser)
            ->post(route('project-management.portal.invoices.submit', [$this->project->uuid, $invoice->uuid]))
            ->assertSessionHasErrors('invoice');
    }

    /** Once sent it is a document we have received, not a draft they can still edit. */
    public function test_a_submitted_invoice_can_no_longer_be_changed_by_the_contractor(): void
    {
        $invoice = $this->submitAnInvoice();

        $this->actingAs($this->contractorUser)
            ->post(route('project-management.portal.invoices.lines.store', [$this->project->uuid, $invoice->uuid]), [
                'description' => 'A line added after the fact',
                'quantity'    => '1',
                'rate'        => '100000.00',
            ])->assertStatus(422);
    }

    /** One contractor cannot touch another's claim by passing its uuid. */
    public function test_a_contractor_cannot_edit_someone_elses_invoice(): void
    {
        $invoice = $this->submitAnInvoice();

        $intruderUser = User::factory()->create(['company_id' => $this->company->id, 'status' => 1]);

        $this->asCompany($this->company, fn () => ProjectMember::create([
            'project_id'   => $this->project->id,
            'user_id'      => $intruderUser->id,
            'role_key'     => 'contractor',
            'display_name' => 'Someone Else Plant',
            'is_active'    => true,
        ]));

        $this->actingAs($intruderUser)
            ->post(route('project-management.portal.invoices.lines.store', [$this->project->uuid, $invoice->uuid]), [
                'description' => 'Not mine', 'quantity' => '1', 'rate' => '1.00',
            ])->assertNotFound();
    }

    /** A client on the project has no business in the contractor invoice routes. */
    public function test_a_client_cannot_submit_a_contractor_invoice(): void
    {
        $clientUser = User::factory()->create(['company_id' => $this->company->id, 'status' => 1]);

        $this->asCompany($this->company, fn () => ProjectMember::create([
            'project_id'   => $this->project->id,
            'user_id'      => $clientUser->id,
            'role_key'     => 'client',
            'display_name' => 'The Municipality',
            'is_active'    => true,
        ]));

        $this->actingAs($clientUser)
            ->post(route('project-management.portal.invoices.store', $this->project->uuid))
            ->assertForbidden();
    }

    /** Approval is what makes the work billable, and the markup is chosen at that moment. */
    public function test_the_manager_approves_with_a_markup_chosen_for_this_invoice(): void
    {
        $invoice = $this->submitAnInvoice();

        $this->actingAs($this->manager)
            ->post(route('project-management.contractors.invoices.approve', $invoice->uuid), [
                'markup_rate' => '0.15',
            ])->assertRedirect();

        $this->asCompany($this->company, function () use ($invoice) {
            $fresh = $invoice->fresh();

            $this->assertSame('approved', $fresh->status);
            $this->assertSame('11500.00', (string) $fresh->total);
            // 11 500 + 15% = 13 225 — what the work is worth to us.
            $this->assertSame('1725.00', (string) $fresh->markup_amount);
            $this->assertSame('13225.00', (string) $fresh->billable_total);
        });
    }

    /**
     * Markup is optional, and not applying one is the normal case.
     *
     * The client was explicit: "this functionality is not always applying". No markup must mean
     * the billable figure is simply the invoice, not a zero that reads as a decision.
     */
    public function test_approving_without_a_markup_bills_the_invoice_as_it_stands(): void
    {
        $invoice = $this->submitAnInvoice();

        $this->actingAs($this->manager)
            ->post(route('project-management.contractors.invoices.approve', $invoice->uuid))
            ->assertRedirect();

        $this->asCompany($this->company, function () use ($invoice) {
            $fresh = $invoice->fresh();

            $this->assertNull($fresh->markup_rate);
            $this->assertSame('0.00', (string) $fresh->markup_amount);
            $this->assertSame('11500.00', (string) $fresh->billable_total);
        });
    }

    /**
     * The rule the client stated twice: the contractor must never see the markup.
     *
     * A contractor who learns their work is resold at 15% learned it from us. This walks the whole
     * portal payload rather than checking a field, because the leak that matters is the one
     * somebody adds later without thinking about it.
     */
    public function test_the_markup_never_reaches_the_contractor(): void
    {
        $invoice = $this->submitAnInvoice();

        $this->actingAs($this->manager)
            ->post(route('project-management.contractors.invoices.approve', $invoice->uuid), [
                'markup_rate' => '0.15',
            ])->assertRedirect();

        $response = $this->actingAs($this->contractorUser)->get(route('project-management.portal'));
        $response->assertOk();

        $payload = json_encode($response->viewData('page')['props'] ?? []);

        foreach (['markup', 'billable', '13225', '1725'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                (string) $payload,
                "The portal payload leaked '{$forbidden}' to the contractor.",
            );
        }

        // What they should see is their own invoice and its own total.
        $this->assertStringContainsString('11500.00', (string) $payload);
    }

    /** A rejection they cannot read is one they cannot act on. */
    public function test_a_rejection_reason_reaches_the_contractor(): void
    {
        $invoice = $this->submitAnInvoice();

        $this->actingAs($this->manager)
            ->post(route('project-management.contractors.invoices.reject', $invoice->uuid), [
                'reason' => 'Day rate is 2 200 on your contract, not 2 500.',
            ])->assertRedirect();

        $response = $this->actingAs($this->contractorUser)->get(route('project-management.portal'));

        $this->assertStringContainsString(
            'Day rate is 2 200 on your contract',
            (string) json_encode($response->viewData('page')['props'] ?? []),
        );
    }

    /** Rejecting without saying why is refused. */
    public function test_a_rejection_requires_a_reason(): void
    {
        $invoice = $this->submitAnInvoice();

        $this->actingAs($this->manager)
            ->post(route('project-management.contractors.invoices.reject', $invoice->uuid), ['reason' => ''])
            ->assertSessionHasErrors('reason');
    }

    /** Only a submitted invoice is decidable — approving twice is refused. */
    public function test_an_invoice_can_only_be_decided_once(): void
    {
        $invoice = $this->submitAnInvoice();

        $this->actingAs($this->manager)
            ->post(route('project-management.contractors.invoices.approve', $invoice->uuid))->assertRedirect();

        $this->actingAs($this->manager)
            ->post(route('project-management.contractors.invoices.approve', $invoice->uuid))->assertStatus(422);
    }

    /** The queue opens on what is waiting, which is the daily job. */
    public function test_the_queue_shows_what_is_awaiting_a_decision(): void
    {
        $this->submitAnInvoice();

        $this->actingAs($this->manager)
            ->get(route('project-management.contractors.index'))
            ->assertInertia(fn ($page) => $page
                ->where('filters.status', 'submitted')
                ->where('costs', true)
                ->has('invoices', 1)
                ->where('invoices.0.reference', 'SPH-0042')
                ->where('invoices.0.total', '11500.00'));
    }

    // ── Contracts ───────────────────────────────────────────────────────────

    /** A contract is raised in PM's own register, bound to the project and the member. */
    public function test_a_contract_is_drafted_for_a_contractor(): void
    {
        $this->draftContract(['title' => 'Plant hire agreement', 'start_date' => '2026-03-01', 'end_date' => '2026-08-31']);

        $this->asCompany($this->company, function () {
            $contract = ProjectContract::query()->where('project_id', $this->project->id)->firstOrFail();

            $this->assertSame('Plant hire agreement', $contract->title);
            $this->assertSame($this->contractor->id, $contract->project_member_id);
            $this->assertSame('MP23001/C001', $contract->contract_number);
            $this->assertSame('draft', $contract->status);
        });
    }

    /**
     * Approved work counts against the contract; the billable figure stays on our side.
     *
     * 250 000 contract, 11 500 approved, so 238 500 remains — and remaining is measured on their
     * invoice, not on what we resell it at.
     */
    public function test_approved_invoices_count_against_the_contract(): void
    {
        $this->draftContract();

        $invoice = $this->submitAnInvoice();

        $this->actingAs($this->manager)
            ->post(route('project-management.contractors.invoices.approve', $invoice->uuid), [
                'markup_rate' => '0.15',
            ])->assertRedirect();

        $this->actingAs($this->manager)
            ->get(route('project-management.contractors.project', $this->project->uuid))
            ->assertInertia(fn ($page) => $page
                ->where('contractors.0.position.approved', '11500.00')
                ->where('contractors.0.position.billable', '13225.00')
                ->where('contractors.0.position.contract_value', '250000.00')
                ->where('contractors.0.position.remaining', '238500.00'));
    }

    /** An invoice raised where a contract exists counts against it without anybody choosing it. */
    public function test_an_invoice_attaches_itself_to_a_live_contract(): void
    {
        $this->draftContract();

        $invoice = $this->submitAnInvoice();

        $this->asCompany($this->company, fn () => $this->assertNotNull(
            $invoice->fresh()->contract_id,
            'An invoice raised where the contractor has a contract must count against it.',
        ));
    }

    /** Invoicing works with no contract at all, which is the common case. */
    public function test_invoicing_works_without_a_contract(): void
    {
        $invoice = $this->submitAnInvoice();

        $this->asCompany($this->company, fn () => $this->assertNull($invoice->fresh()->contract_id));

        $this->actingAs($this->manager)
            ->get(route('project-management.contractors.project', $this->project->uuid))
            ->assertInertia(fn ($page) => $page
                ->where('contractors.0.contract', null)
                // Nothing is "remaining" against an agreement that does not exist.
                ->where('contractors.0.position.remaining', null));
    }

    /** Another tenant sees none of this. */
    public function test_contractor_invoices_are_scoped_to_their_company(): void
    {
        $this->submitAnInvoice();

        $other = Company::create(['name' => 'Someone Else']);

        $this->asCompany($other, fn () => $this->assertSame(0, ContractorInvoice::query()->count()));
    }
}
