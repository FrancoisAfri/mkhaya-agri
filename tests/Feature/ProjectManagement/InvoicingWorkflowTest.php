<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\Invoice;
use App\Modules\Operations\ProjectManagement\Models\InvoiceAnnexure;
use App\Modules\Operations\ProjectManagement\Models\InvoiceDisbursement;
use App\Modules\Operations\ProjectManagement\Models\InvoiceFeeStage;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Tenancy\Models\Company;

/**
 * Preparing an account the way a person does: entirely over HTTP.
 *
 * ── Why this suite exists ───────────────────────────────────────────────────
 *
 * The earlier tests built their fixtures by writing rows straight to the database, which proved
 * the arithmetic and proved nothing about whether anybody could *enter* an account. They could
 * not: there was no way to set the value of the works, add a stage, add an annexure line or type a
 * disbursement. The feature computed accounts nobody could create, and the tests were the reason
 * that went unnoticed.
 *
 * So every step below goes through a route. If a screen cannot do it, this test cannot do it
 * either.
 */
class InvoicingWorkflowTest extends ProjectManagementTestCase
{
    private Company $company;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Aseda Workflow']);
        $this->entitleCompany($this->company);
        $this->manager->forceFill(['company_id' => $this->company->id])->save();

        $this->project = $this->asCompany(
            $this->company,
            fn () => $this->makeProject(['code' => 'MP23001', 'name' => 'Kwaggafontein Recreational Centre'])
        );
    }

    private function openAccount(): Invoice
    {
        $this->actingAs($this->manager)
            ->post(route('project-management.invoicing.store', $this->project->uuid), [
                'client_name' => 'Thembisile Hani Local Municipality',
            ])->assertRedirect();

        return $this->asCompany($this->company, fn () => Invoice::forProject($this->project->id)->latest('id')->first());
    }

    /**
     * The whole job, start to finish, without touching the database directly.
     *
     * Open an account → state the works and the fee → add the stages → move a stage on → add an
     * annexure and a line on it → add a disbursement typed by hand → recalculate → generate.
     */
    public function test_an_account_can_be_prepared_end_to_end_through_the_screens(): void
    {
        $invoice = $this->openAccount();
        $as = fn () => $this->actingAs($this->manager);

        // ── Form 1's header ──────────────────────────────────────────────────
        $as()->put(route('project-management.invoicing.fee_account.update', $invoice->uuid), [
            'external_works'      => '30000000.00',
            'structural_works'    => '3105272.80',
            'fee_percentage'      => '0.08',
            'payment_certificate' => 'PC-07',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->asCompany($this->company, function () use ($invoice) {
            // The total is the sum of the split, not a fourth number to type and get wrong.
            $this->assertSame('33105272.80', $invoice->fresh()->feeAccount->total_value_of_works);
        });

        // ── The client's standard stage structure ────────────────────────────
        $as()->post(route('project-management.invoicing.stages.seed', $invoice->uuid))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->asCompany($this->company, function () use ($invoice) {
            $codes = $invoice->stages()->pluck('code')->all();

            $this->assertContains('3.1', $codes);
            $this->assertContains('3.6', $codes);
            // Their numbering has no 3.7, and seeding must not invent one.
            $this->assertNotContains('3.7', $codes);
        });

        // ── Set an apportionment and move the stage on ───────────────────────
        $stage = $this->asCompany($this->company, fn () => $invoice->stages()->where('code', '3.6')->first());

        $as()->post(route('project-management.invoicing.stages.store', $invoice->uuid), [
            'code'          => '3.6',
            'label'         => 'Contract Admin & Inspection',
            'apportionment' => '1',
            'progress'      => '0.10',
        ])->assertRedirect()->assertSessionHasNoErrors();

        // ── An annexure and a line on it ─────────────────────────────────────
        $as()->post(route('project-management.invoicing.annexures.store', $invoice->uuid), ['number' => 4])
            ->assertRedirect();

        $annexure = $this->asCompany($this->company, fn () => $invoice->annexures()->where('number', 4)->first());

        $as()->post(
            route('project-management.invoicing.annexure_items.store', [$invoice->uuid, $annexure->uuid]),
            ['party' => 'Geotech SA', 'appointment_amount' => '113011.57'],
        )->assertRedirect()->assertSessionHasNoErrors();

        // ── A disbursement with no annexure behind it ────────────────────────
        $as()->post(route('project-management.invoicing.disbursements.store', $invoice->uuid), [
            'block'        => 'category',
            'description'  => 'Town planners',
            'this_account' => '15000.00',
        ])->assertRedirect()->assertSessionHasNoErrors();

        // ── Recalculate and read the Tax Invoice's figures ───────────────────
        $as()->post(route('project-management.invoicing.recalculate', $invoice->uuid))->assertRedirect();

        $this->asCompany($this->company, function () use ($invoice) {
            $fresh = $invoice->fresh();

            // 33 105 272.80 × 8% × 10%
            $this->assertSame('264842.18', $fresh->fees_now_claimed);
            // 113 011.57 + 10% surcharge, plus the 15 000 typed by hand
            $this->assertSame('139312.73', $fresh->disbursements_now_claimed);
            $this->assertSame('404154.91', $fresh->subtotal);
            $this->assertSame('60623.24', $fresh->vat_amount);
            $this->assertSame('464778.15', $fresh->amount_now_due);
        });

        // ── Issue it ─────────────────────────────────────────────────────────
        $as()->post(route('project-management.invoicing.generate', $invoice->uuid))->assertRedirect();

        $this->asCompany($this->company, fn () => $this->assertSame('generated', $invoice->fresh()->status));

        // And the pack downloads.
        $as()->get(route('project-management.invoicing.download', [$invoice->uuid, 'pack']))->assertOk();
    }

    // ── Editing stops when the account is issued ────────────────────────────

    public function test_an_issued_account_refuses_every_edit(): void
    {
        $invoice = $this->openAccount();
        $as = fn () => $this->actingAs($this->manager);

        $as()->post(route('project-management.invoicing.generate', $invoice->uuid))->assertRedirect();

        $as()->put(route('project-management.invoicing.fee_account.update', $invoice->uuid), ['fee_percentage' => '0.09'])
            ->assertStatus(422);
        $as()->post(route('project-management.invoicing.stages.seed', $invoice->uuid))
            ->assertStatus(422);
        $as()->post(route('project-management.invoicing.disbursements.store', $invoice->uuid), [
            'block' => 'category', 'description' => 'Advertising',
        ])->assertStatus(422);
    }

    // ── The pieces on their own ─────────────────────────────────────────────

    public function test_a_stage_can_be_added_and_removed(): void
    {
        $invoice = $this->openAccount();

        $this->actingAs($this->manager)->post(route('project-management.invoicing.stages.store', $invoice->uuid), [
            'code' => '3.4', 'label' => 'Design Review & Development', 'apportionment' => '0.25',
        ])->assertRedirect();

        $stage = $this->asCompany($this->company, fn () => $invoice->stages()->where('code', '3.4')->first());
        $this->assertNotNull($stage);

        $this->actingAs($this->manager)
            ->delete(route('project-management.invoicing.stages.destroy', [$invoice->uuid, $stage->uuid]))
            ->assertRedirect();

        $this->asCompany($this->company, fn () => $this->assertSame(0, $invoice->stages()->count()));
    }

    /** Adding the same stage code twice edits it rather than duplicating the row. */
    public function test_adding_a_stage_twice_updates_it(): void
    {
        $invoice = $this->openAccount();

        foreach (['0.20', '0.25'] as $apportionment) {
            $this->actingAs($this->manager)->post(route('project-management.invoicing.stages.store', $invoice->uuid), [
                'code' => '3.4', 'label' => 'Design Review & Development', 'apportionment' => $apportionment,
            ])->assertRedirect();
        }

        $this->asCompany($this->company, function () use ($invoice) {
            $this->assertSame(1, $invoice->stages()->count());
            $this->assertSame('0.250000', $invoice->stages()->first()->apportionment);
        });
    }

    public function test_an_annexure_line_can_be_removed_and_the_totals_follow(): void
    {
        $invoice = $this->openAccount();

        $this->actingAs($this->manager)
            ->post(route('project-management.invoicing.annexures.store', $invoice->uuid), ['number' => 13])
            ->assertRedirect();

        $annexure = $this->asCompany($this->company, fn () => $invoice->annexures()->first());

        $this->actingAs($this->manager)->post(
            route('project-management.invoicing.annexure_items.store', [$invoice->uuid, $annexure->uuid]),
            ['party' => 'Training SA', 'appointment_amount' => '12500.00'],
        )->assertRedirect();

        // 12 500 + 10%
        $this->asCompany($this->company, fn () => $this->assertSame('13750.00', $annexure->fresh()->this_invoice));

        $item = $this->asCompany($this->company, fn () => $annexure->items()->first());

        $this->actingAs($this->manager)
            ->delete(route('project-management.invoicing.annexure_items.destroy', [$invoice->uuid, $item->uuid]))
            ->assertRedirect();

        $this->asCompany($this->company, fn () => $this->assertSame('0.00', $annexure->fresh()->this_invoice));
    }

    public function test_a_disbursement_typed_by_hand_reaches_the_total(): void
    {
        $invoice = $this->openAccount();

        $this->actingAs($this->manager)->post(route('project-management.invoicing.disbursements.store', $invoice->uuid), [
            'block' => 'category', 'description' => 'Geohydrologist', 'this_account' => '48000.00',
        ])->assertRedirect();

        $this->asCompany($this->company, function () use ($invoice) {
            $this->assertSame('48000.00', $invoice->fresh()->disbursements_now_claimed);
            $this->assertSame('7200.00', $invoice->fresh()->vat_amount);
        });
    }

    /**
     * Seeded stages arrive usable, not dead.
     *
     * They previously arrived with a null apportionment, which meant zero fee — and the page only
     * offered a progress input when an apportionment already existed, so a freshly seeded row
     * could not be edited into a working one either. Eight rows of ZAR 0.00 and no way forward.
     */
    public function test_seeded_stages_carry_the_clients_own_apportionments(): void
    {
        $invoice = $this->openAccount();

        $this->actingAs($this->manager)->put(
            route('project-management.invoicing.fee_account.update', $invoice->uuid),
            ['external_works' => '33105272.80', 'fee_percentage' => '0.08'],
        )->assertRedirect();

        $this->actingAs($this->manager)
            ->post(route('project-management.invoicing.stages.seed', $invoice->uuid))
            ->assertRedirect();

        $this->actingAs($this->manager)
            ->post(route('project-management.invoicing.recalculate', $invoice->uuid))
            ->assertRedirect();

        $this->asCompany($this->company, function () use ($invoice) {
            $stages = $invoice->stages()->get()->keyBy('code');

            // 5 / 15 / 25 / 25 / 25 / 5, read out of Account 7's own formulas.
            $this->assertSame('0.050000', $stages['3.2']->apportionment);
            $this->assertSame('0.250000', $stages['3.4']->apportionment);

            // And they earn: 33 105 272.80 x 8% x 25% is a real stage fee, not zero.
            $this->assertSame('662105.46', $stages['3.4']->stage_fee);

            // The split adds up to the whole fee.
            $total = $invoice->stages()->whereNotNull('apportionment')->sum('apportionment');
            $this->assertEqualsWithDelta(1.0, (float) $total, 0.0001);
        });
    }

    /** Both editable columns save, and the account follows without pressing Recalculate. */
    public function test_a_stages_apportionment_and_progress_can_both_be_edited(): void
    {
        $invoice = $this->openAccount();

        $this->actingAs($this->manager)->put(
            route('project-management.invoicing.fee_account.update', $invoice->uuid),
            ['external_works' => '1000000.00', 'fee_percentage' => '0.10'],
        )->assertRedirect();

        $this->actingAs($this->manager)->post(route('project-management.invoicing.stages.store', $invoice->uuid), [
            'code' => '3.4', 'label' => 'Design Review & Development',
        ])->assertRedirect();

        $stage = $this->asCompany($this->company, fn () => $invoice->stages()->first());
        $url = route('project-management.invoicing.stages.update', [$invoice->uuid, $stage->uuid]);

        $this->actingAs($this->manager)->patch($url, ['apportionment' => 0.5])->assertRedirect();
        $this->actingAs($this->manager)->patch($url, ['progress' => 0.4])->assertRedirect();

        $this->asCompany($this->company, function () use ($invoice) {
            $fresh = $invoice->fresh()->stages()->first();

            // 1 000 000 x 10% = 100 000 fee; half of it is this stage; 40% of that is earned.
            $this->assertSame('50000.00', $fresh->stage_fee);
            $this->assertSame('20000.00', $fresh->value_to_date);
            $this->assertSame('20000.00', $fresh->this_account);
            // Recalculated on save, so the header moved too.
            $this->assertSame('20000.00', $invoice->fresh()->fees_now_claimed);
        });
    }

    /**
     * A first account has somewhere to type the client, the consultant and the bank.
     *
     * These carry forward on every later account, so the gap only showed on the first one — where
     * the Tax Invoice printed with no client and no banking block.
     */
    public function test_the_account_details_can_be_entered_and_reach_the_tax_invoice(): void
    {
        $invoice = $this->openAccount();

        $this->actingAs($this->manager)->put(
            route('project-management.invoicing.details.update', $invoice->uuid),
            [
                'client_name'            => 'Thembisile Hani Local Municipality',
                'client_vat_number'      => '4000000000',
                'attention'              => 'The Municipal Manager',
                'consultant_name'        => 'Aseda Consulting Engineers (Pty) Ltd',
                'banking_name'           => 'Standard Bank',
                'banking_account_number' => '123456789',
                'banking_branch_code'    => '051001',
            ],
        )->assertRedirect()->assertSessionHasNoErrors();

        $document = $this->asCompany($this->company, fn () => app(
            \App\Modules\Operations\ProjectManagement\Services\InvoicePackService::class
        )->document($invoice->fresh(), 'tax_invoice'));

        $this->assertSame('Thembisile Hani Local Municipality', $document['header']['client_name']);
        $this->assertSame('Aseda Consulting Engineers (Pty) Ltd', $document['header']['consultant_name']);
        $this->assertSame('Standard Bank', $document['body']['banking']['bank']);
        // The payment reference falls back to the invoice number when none is given.
        $this->assertSame('MP23001/01', $document['body']['banking']['reference']);
    }

    /** Details are frozen with the rest of the account. */
    public function test_details_cannot_be_changed_once_issued(): void
    {
        $invoice = $this->openAccount();
        $this->actingAs($this->manager)->post(route('project-management.invoicing.generate', $invoice->uuid))
            ->assertRedirect();

        $this->actingAs($this->manager)->put(
            route('project-management.invoicing.details.update', $invoice->uuid),
            ['client_name' => 'Someone Else'],
        )->assertStatus(422);
    }

    // ── Details entered once, inherited everywhere ──────────────────────────

    /**
     * A first invoice arrives filled in from Setup and from the client record.
     *
     * The practice's own details and bank are entered once in Setup; the client's address and VAT
     * number sit on the client record. Neither should be retyped onto an invoice, and typing a
     * bank account number on every first invoice is how one of them ends up wrong.
     */
    public function test_a_new_invoice_inherits_the_practice_and_client_details(): void
    {
        $this->asCompany($this->company, function () {
            \App\Modules\Operations\ProjectManagement\Models\InvoicingSetting::current()->update([
                'consultant_name'        => 'Aseda Consulting Engineers (Pty) Ltd',
                'consultant_vat_number'  => '4111111111',
                'banking_name'           => 'Standard Bank',
                'banking_account_number' => '123456789',
                'account_description'    => 'Professional Fee and disbursements account',
                'vat_rate'               => '0.15',
            ]);

            // The client, with the invoicing details a CRM account has no column for.
            $ref = \App\Modules\Operations\ProjectManagement\Models\ExternalRef::create([
                'local_type'    => 'project_client',
                'source_module' => 'local',
                'source_entity' => 'client',
                'source_id'     => 'client-1',
                'display_name'  => 'Thembisile Hani Local Municipality',
                'snapshot'      => [
                    'address'    => 'PO Box 1, KwaMhlanga',
                    'vat_number' => '4000000000',
                    'attention'  => 'The Municipal Manager',
                ],
                'sync_status'   => 'ok',
            ]);

            $this->project->update(['client_ref_id' => $ref->id]);
        });

        $invoice = $this->openAccount();

        $this->assertSame('Aseda Consulting Engineers (Pty) Ltd', $invoice->consultant_name);
        $this->assertSame('Standard Bank', $invoice->banking_name);
        $this->assertSame('Professional Fee and disbursements account', $invoice->description);

        $this->assertSame('Thembisile Hani Local Municipality', $invoice->client_name);
        $this->assertSame('PO Box 1, KwaMhlanga', $invoice->client_address);
        $this->assertSame('4000000000', $invoice->client_vat_number);
        $this->assertSame('The Municipal Manager', $invoice->attention);
    }

    /** What was corrected on the last invoice wins over the Setup default. */
    public function test_a_correction_on_one_invoice_carries_to_the_next(): void
    {
        $this->asCompany($this->company, fn () =>
            \App\Modules\Operations\ProjectManagement\Models\InvoicingSetting::current()
                ->update(['banking_name' => 'Standard Bank']));

        // Opened without a client name, so what the previous invoice says is what carries —
        // the shared helper always posts one, and an explicit value correctly beats everything.
        $this->actingAs($this->manager)
            ->post(route('project-management.invoicing.store', $this->project->uuid), [])
            ->assertRedirect();

        $first = $this->asCompany(
            $this->company,
            fn () => Invoice::forProject($this->project->id)->orderByDesc('account_number')->first()
        );

        $this->actingAs($this->manager)->put(
            route('project-management.invoicing.details.update', $first->uuid),
            ['banking_name' => 'Nedbank', 'client_name' => 'Corrected Client'],
        )->assertRedirect();
        $this->actingAs($this->manager)->post(route('project-management.invoicing.generate', $first->uuid))
            ->assertRedirect();

        $this->actingAs($this->manager)
            ->post(route('project-management.invoicing.store', $this->project->uuid), [])
            ->assertRedirect();

        $second = $this->asCompany(
            $this->company,
            fn () => Invoice::forProject($this->project->id)->orderByDesc('account_number')->first()
        );

        $this->assertSame('Nedbank', $second->banking_name);
        $this->assertSame('Corrected Client', $second->client_name);
    }

    /** Changing Setup never restates an invoice already issued. */
    public function test_changing_setup_does_not_touch_an_issued_invoice(): void
    {
        $this->asCompany($this->company, fn () =>
            \App\Modules\Operations\ProjectManagement\Models\InvoicingSetting::current()
                ->update(['banking_name' => 'Standard Bank']));

        $invoice = $this->openAccount();
        $this->actingAs($this->manager)->post(route('project-management.invoicing.generate', $invoice->uuid))
            ->assertRedirect();

        $this->asCompany($this->company, fn () =>
            \App\Modules\Operations\ProjectManagement\Models\InvoicingSetting::current()
                ->update(['banking_name' => 'A Different Bank']));

        $this->assertSame(
            'Standard Bank',
            $this->asCompany($this->company, fn () => $invoice->fresh()->banking_name),
        );
    }

    // ── The rest of the client's specification ──────────────────────────────

    /** §64: DRAFT → GENERATED → SENT. "Issued" and "the client has it" are different facts. */
    public function test_a_generated_invoice_can_be_marked_as_sent(): void
    {
        $invoice = $this->openAccount();
        $this->actingAs($this->manager)->post(route('project-management.invoicing.generate', $invoice->uuid))
            ->assertRedirect();

        $this->actingAs($this->manager)->post(route('project-management.invoicing.sent', $invoice->uuid))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->asCompany($this->company, function () use ($invoice) {
            $this->assertSame('sent', $invoice->fresh()->status);
            $this->assertNotNull($invoice->fresh()->sent_at);
        });
    }

    public function test_a_draft_cannot_be_marked_as_sent(): void
    {
        $invoice = $this->openAccount();

        $this->actingAs($this->manager)->post(route('project-management.invoicing.sent', $invoice->uuid))
            ->assertSessionHasErrors('invoice');
    }

    /**
     * §39/40: the certification block.
     *
     * A signature binds to the figures as they stood. If the invoice ever changed, the signature
     * would visibly stop describing it.
     */
    public function test_an_issued_invoice_can_be_certified_by_both_signatories(): void
    {
        $invoice = $this->openAccount();
        $this->actingAs($this->manager)->post(route('project-management.invoicing.generate', $invoice->uuid))
            ->assertRedirect();

        foreach ([['consulting_engineer', 'O. Simba'], ['programme_manager', 'N. Mashabela']] as [$role, $name]) {
            $this->actingAs($this->manager)->post(
                route('project-management.invoicing.sign', $invoice->uuid),
                ['role' => $role, 'signer_name' => $name, 'registration_number' => 'ECSA-123'],
            )->assertRedirect()->assertSessionHasNoErrors();
        }

        $props = $this->actingAs($this->manager)
            ->get(route('project-management.invoicing.show', $invoice->uuid))
            ->viewData('page')['props'];

        $this->assertCount(2, $props['signatures']);
        $this->assertTrue($props['signatures'][0]['matches'], 'A fresh signature describes the figures.');
    }

    public function test_a_draft_cannot_be_certified(): void
    {
        $invoice = $this->openAccount();

        $this->actingAs($this->manager)->post(
            route('project-management.invoicing.sign', $invoice->uuid),
            ['role' => 'consulting_engineer', 'signer_name' => 'O. Simba'],
        )->assertStatus(422);
    }
}
