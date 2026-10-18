<?php

namespace Tests\Feature\SupplierManagement;

use App\Modules\SupplierManagement\DueDiligence\Services\BankingGate;
use App\Modules\SupplierManagement\DueDiligence\Services\VerificationEngine;
use App\Modules\SupplierManagement\DueDiligence\Support\CheckType;
use App\Modules\SupplierManagement\DueDiligence\Support\ProviderCatalogue;
use App\Modules\SupplierManagement\DueDiligence\Support\VerificationStatus;
use App\Modules\SupplierManagement\Models\PurchaseOrder;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierInvoice;
use App\Modules\SupplierManagement\Models\SupplierPerson;
use App\Modules\SupplierManagement\Models\SupplierRiskFlag;
use App\Modules\SupplierManagement\Models\SupplierSetting;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1: verify who the supplier is, and stop the payment when the answer is wrong.
 *
 * The banking tests carry the most weight in the module. The fraud is mundane — an email asks for
 * banking details to be updated, the details belong to somebody else, the next payment run sends real
 * money to a real stranger — and the control against it is worth nothing if it warns instead of
 * refusing. So: a verified holder-name mismatch must make the payment impossible from the screen, and
 * editing the account number afterwards must not inherit the old answer.
 */
class SupplierVerificationTest extends SupplierTestCase
{
    private VerificationEngine $engine;

    private BankingGate $gate;

    protected function setUp(): void
    {
        parent::setUp();

        ProviderCatalogue::install();
        $this->engine = app(VerificationEngine::class);
        $this->gate = app(BankingGate::class);
    }

    private function supplier(array $attributes = []): Supplier
    {
        return Supplier::create(array_merge([
            'company_name' => 'ABC Holdings (Pty) Ltd',
            'company_registration_number' => '2019/123456/07',
            'tax_reference_number' => '9012345678',
            'account_holder_name' => 'ABC Holdings',
            'bank_account_number' => '62012345678',
            'bank_name' => 'FNB',
            'branch_code' => '250655',
            'is_active' => 1,
        ], $attributes));
    }

    private function invoiceFor(Supplier $supplier): SupplierInvoice
    {
        return SupplierInvoice::create([
            'supplier_id' => $supplier->id,
            'invoice_number' => 'INV-'.uniqid(),
            'amount' => 1000,
            'status' => 'pending',
            'due_date' => now()->addDays(30),
        ]);
    }

    // ── The banking gate ────────────────────────────────────────────────────

    public function test_a_verified_holder_name_mismatch_makes_the_payment_impossible(): void
    {
        $supplier = $this->supplier();

        // The bank says the account belongs to somebody else entirely.
        $observation = $this->engine->recordManual(
            supplier: $supplier,
            checkType: CheckType::BANK_ACCOUNT,
            status: VerificationStatus::VERIFIED,
            observed: ['account_holder_name' => 'Sipho Ndlovu Trading', 'account_number' => '62012345678'],
            claimed: app(VerificationEngine::class)->claimsFor($supplier, CheckType::BANK_ACCOUNT),
        );

        // The comparison overrules the "verified" the person selected: this is not their supplier.
        $this->assertEquals(VerificationStatus::MISMATCH, $observation->status);

        $verdict = $this->gate->verdict($supplier);
        $this->assertEquals(BankingGate::BLOCKED, $verdict['state']);
        $this->assertTrue($verdict['blocks']);

        $invoice = $this->invoiceFor($supplier);
        $this->patch("/suppliers/invoices/{$invoice->id}", ['status' => 'paid'])
            ->assertSessionHasErrors('status');

        $this->assertNotEquals('paid', $invoice->fresh()->status, 'A mismatched account was paid anyway.');
    }

    public function test_a_matching_account_holder_lets_the_payment_through(): void
    {
        $supplier = $this->supplier();

        $observation = $this->engine->recordManual(
            supplier: $supplier,
            checkType: CheckType::BANK_ACCOUNT,
            status: VerificationStatus::VERIFIED,
            // The bank writes the name in caps with the entity form spelled out. Same holder.
            observed: ['account_holder_name' => 'ABC HOLDINGS PROPRIETARY LIMITED', 'account_number' => '620 123 456 78'],
            claimed: $this->engine->claimsFor($supplier, CheckType::BANK_ACCOUNT),
        );

        $this->assertEquals(VerificationStatus::VERIFIED, $observation->status, $observation->summary ?? '');
        $this->assertEquals(BankingGate::OK, $this->gate->verdict($supplier)['state']);

        $invoice = $this->invoiceFor($supplier);
        $this->patch("/suppliers/invoices/{$invoice->id}", ['status' => 'paid'])->assertSessionHasNoErrors();

        $this->assertEquals('paid', $invoice->fresh()->status);
    }

    public function test_an_unverified_account_is_paid_unless_the_company_requires_verification(): void
    {
        $supplier = $this->supplier();

        // Nobody has checked. Blocking here by default would stop every payment in every existing
        // installation the day this ships, so it warns rather than refuses.
        $verdict = $this->gate->verdict($supplier);
        $this->assertEquals(BankingGate::UNVERIFIED, $verdict['state']);
        $this->assertFalse($verdict['blocks']);

        $invoice = $this->invoiceFor($supplier);
        $this->patch("/suppliers/invoices/{$invoice->id}", ['status' => 'paid'])->assertSessionHasNoErrors();

        // A company that has AVS configured can make it mandatory.
        SupplierSetting::query()->firstOrCreate([])->update(['require_bank_verification' => true]);

        $this->assertTrue($this->gate->blocksPayment($supplier->fresh()));

        $second = $this->invoiceFor($supplier);
        $this->patch("/suppliers/invoices/{$second->id}", ['status' => 'paid'])->assertSessionHasErrors('status');
    }

    public function test_changing_the_account_after_a_check_does_not_inherit_the_old_answer(): void
    {
        $supplier = $this->supplier();

        $this->engine->recordManual(
            supplier: $supplier,
            checkType: CheckType::BANK_ACCOUNT,
            status: VerificationStatus::VERIFIED,
            observed: ['account_holder_name' => 'ABC Holdings', 'account_number' => '62012345678'],
            claimed: $this->engine->claimsFor($supplier, CheckType::BANK_ACCOUNT),
        );

        $this->assertEquals(BankingGate::OK, $this->gate->verdict($supplier)['state']);

        // Banking details are edited — the exact move the fraud makes.
        $supplier->update(['bank_account_number' => '99988877766']);

        $verdict = $this->gate->verdict($supplier->fresh());
        $this->assertEquals(BankingGate::UNVERIFIED, $verdict['state'], 'A changed account kept its old verification.');
        $this->assertStringContainsString('changed', $verdict['reason']);
    }

    public function test_a_near_match_on_the_holder_name_is_treated_as_a_stop(): void
    {
        $supplier = $this->supplier();

        // "ABC Holdings" vs "ABC Holdings Group" — close enough to look like a clerical difference,
        // which is exactly what a redirection attempt is designed to look like.
        $observation = $this->engine->recordManual(
            supplier: $supplier,
            checkType: CheckType::BANK_ACCOUNT,
            status: VerificationStatus::VERIFIED,
            observed: ['account_holder_name' => 'ABC Holdings Group', 'account_number' => '62012345678'],
            claimed: $this->engine->claimsFor($supplier, CheckType::BANK_ACCOUNT),
        );

        $this->assertEquals(VerificationStatus::MANUAL_REVIEW, $observation->status);
        $this->assertTrue($this->gate->blocksPayment($supplier), 'A near-match on the holder name must not pay.');
    }

    // ── Failed checks become visible ────────────────────────────────────────

    public function test_a_failed_check_raises_a_risk_flag_and_a_passing_recheck_clears_it(): void
    {
        $supplier = $this->supplier();

        $this->engine->recordManual(
            supplier: $supplier,
            checkType: CheckType::COMPANY_REGISTRY,
            status: VerificationStatus::VERIFIED,
            observed: ['company_name' => 'Not This Company At All', 'registration_number' => '2019/123456/07'],
            claimed: $this->engine->claimsFor($supplier, CheckType::COMPANY_REGISTRY),
        );

        $flag = SupplierRiskFlag::where('supplier_id', $supplier->id)
            ->where('type', 'verification_company_registry')->where('status', 'open')->first();

        $this->assertNotNull($flag, 'A mismatch has to reach the flags procurement works from.');
        $this->assertEquals('high', $flag->severity);
        $this->assertContains('company_name', $flag->context['mismatched_fields']);

        // The supplier corrects their registered name and the check is run again.
        $supplier->update(['company_name' => 'Not This Company At All']);
        $this->engine->recordManual(
            supplier: $supplier->fresh(),
            checkType: CheckType::COMPANY_REGISTRY,
            status: VerificationStatus::VERIFIED,
            observed: ['company_name' => 'Not This Company At All', 'registration_number' => '2019/123456/07'],
            claimed: $this->engine->claimsFor($supplier->fresh(), CheckType::COMPANY_REGISTRY),
        );

        $this->assertEquals('resolved', $flag->fresh()->status,
            'A flag that can only be cleared by hand becomes a flag everyone ignores.');
    }

    public function test_a_bank_mismatch_is_flagged_as_critical(): void
    {
        $supplier = $this->supplier();

        $this->engine->recordManual(
            supplier: $supplier,
            checkType: CheckType::BANK_ACCOUNT,
            status: VerificationStatus::MISMATCH,
            observed: ['account_holder_name' => 'Someone Else'],
        );

        $this->assertEquals('critical', SupplierRiskFlag::where('supplier_id', $supplier->id)
            ->where('type', 'verification_bank_account')->value('severity'));
    }

    public function test_an_unavailable_check_does_not_blame_the_supplier(): void
    {
        $supplier = $this->supplier();

        // No provider is configured, so this records UNAVAILABLE. That is our coverage gap, not the
        // supplier's problem, and a risk flag against them would bury the real ones.
        $this->engine->verify($supplier, CheckType::GOVERNMENT_CSD);

        $this->assertEquals(0, SupplierRiskFlag::where('supplier_id', $supplier->id)->count());
    }

    // ── The routes ──────────────────────────────────────────────────────────

    public function test_running_a_check_with_no_configured_source_tells_the_user_instead_of_logging_nothing(): void
    {
        $supplier = $this->supplier();

        $this->post("/suppliers/{$supplier->uuid}/verifications/run", ['check_type' => CheckType::COMPANY_REGISTRY])
            ->assertSessionHasErrors('check_type');

        // Refused outright: the person pressed a button expecting a check, and an "unavailable" row
        // is not what they asked for.
        $this->assertEquals(0, DB::table('supplier_verifications')->where('supplier_id', $supplier->id)->count());
    }

    public function test_a_person_can_record_a_check_through_the_route(): void
    {
        $supplier = $this->supplier();

        $this->post("/suppliers/{$supplier->uuid}/verifications/record", [
            'check_type' => CheckType::TAX_COMPLIANCE,
            'status' => VerificationStatus::VERIFIED,
            'observed' => ['compliance_status' => 'Compliant', 'tax_reference_number' => '9012345678'],
            'note' => 'Checked on eFiling with the supplier\'s PIN.',
            'reference' => 'PIN-778899',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('supplier_verifications', [
            'supplier_id' => $supplier->id,
            'check_type' => CheckType::TAX_COMPLIANCE,
            'status' => VerificationStatus::VERIFIED,
            'provider_code' => 'manual',
            'reference' => 'PIN-778899',
            'checked_by' => $this->user->id,
        ]);
    }

    public function test_a_check_can_be_recorded_against_a_person_but_not_someone_elses_person(): void
    {
        $supplier = $this->supplier();
        $other = $this->supplier(['company_name' => 'Other Co', 'company_registration_number' => '2020/999999/07']);

        $director = SupplierPerson::create([
            'supplier_id' => $supplier->id,
            'first_name' => 'Thandi', 'surname' => 'Mokoena',
            'role' => 'director', 'source' => 'supplier',
        ]);

        $this->post("/suppliers/{$supplier->uuid}/verifications/record", [
            'check_type' => CheckType::SCREENING_PEP,
            'status' => VerificationStatus::MANUAL_REVIEW,
            'person_id' => $director->id,
            'note' => 'Possible match, needs adjudication.',
        ])->assertRedirect();

        $this->assertDatabaseHas('supplier_verifications', [
            'supplier_id' => $supplier->id,
            'subject_type' => 'person',
            'subject_id' => $director->id,
            'subject_label' => 'Thandi Mokoena',
        ]);

        // Screening the wrong person and filing it against this supplier would be worse than an error.
        $this->post("/suppliers/{$other->uuid}/verifications/record", [
            'check_type' => CheckType::SCREENING_PEP,
            'status' => VerificationStatus::VERIFIED,
            'person_id' => $director->id,
        ])->assertNotFound();
    }

    public function test_the_tcs_pin_is_stored_encrypted_and_never_shown_in_the_clear(): void
    {
        $supplier = $this->supplier();

        $this->post("/suppliers/{$supplier->uuid}/tcs-pin", [
            'tcs_pin' => 'ABCD1234EF',
            'tcs_pin_expires_at' => now()->addYear()->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertEquals('ABCD1234EF', $supplier->fresh()->tcs_pin);

        $raw = DB::table('suppliers')->where('id', $supplier->id)->value('tcs_pin');
        $this->assertStringNotContainsString('ABCD1234EF', (string) $raw, 'The PIN is readable in the database.');
    }

    public function test_an_expired_pin_date_is_refused(): void
    {
        $supplier = $this->supplier();

        $this->post("/suppliers/{$supplier->uuid}/tcs-pin", [
            'tcs_pin' => 'ABCD1234EF',
            'tcs_pin_expires_at' => now()->subDay()->toDateString(),
        ])->assertSessionHasErrors('tcs_pin_expires_at');
    }

    // ── People ──────────────────────────────────────────────────────────────

    public function test_a_persons_identifier_is_encrypted_masked_and_their_name_normalised(): void
    {
        $supplier = $this->supplier();

        $person = SupplierPerson::create([
            'supplier_id' => $supplier->id,
            'first_name' => 'John', 'surname' => 'Smith',
            'id_type' => 'sa_id', 'id_number' => '8501015800085',
            'nationality' => 'ZA', 'role' => 'director',
            'appointed_at' => '2019-03-01',
        ]);

        $this->assertEquals('8501015800085', $person->fresh()->id_number);
        $this->assertStringNotContainsString('8501015800085',
            (string) DB::table('supplier_persons')->where('id', $person->id)->value('id_number'),
            'An ID number is readable in the database.');

        $this->assertEquals('•••••••••0085', $person->maskedIdNumber());
        $this->assertTrue($person->hasIdentifier());

        // "SMITH, John" and "John Smith" are one person, so the stored form is order-independent.
        $this->assertEquals('john smith', $person->fresh()->normalised_name);
    }

    public function test_a_resigned_person_is_no_longer_screened_but_is_not_forgotten(): void
    {
        $supplier = $this->supplier();

        SupplierPerson::create([
            'supplier_id' => $supplier->id, 'first_name' => 'Current', 'surname' => 'Director', 'role' => 'director',
        ]);
        SupplierPerson::create([
            'supplier_id' => $supplier->id, 'first_name' => 'Former', 'surname' => 'Director', 'role' => 'director',
            'resigned_at' => now()->subYear(),
        ]);

        $this->assertEquals(1, SupplierPerson::where('supplier_id', $supplier->id)->screenable()->count());
        // "Who was a director when this contract was awarded" is a question that gets asked.
        $this->assertEquals(2, SupplierPerson::where('supplier_id', $supplier->id)->count());
    }

    public function test_persons_are_tenant_scoped(): void
    {
        $supplier = $this->supplier();
        SupplierPerson::create([
            'supplier_id' => $supplier->id, 'first_name' => 'Tenant', 'surname' => 'One', 'role' => 'director',
        ]);

        $otherCompany = $this->makeCompany('Other Tenant');
        app(\App\Modules\Tenancy\Services\CurrentCompany::class)->set($otherCompany);

        $this->assertEquals(0, SupplierPerson::count(), 'Another tenant can see these people.');
    }

    // ── Documents ───────────────────────────────────────────────────────────

    public function test_a_certificate_in_another_companys_name_is_caught(): void
    {
        $supplier = $this->supplier();

        // Somebody uploads a genuine B-BBEE certificate that belongs to a different company. The AI
        // reads it correctly; the comparison is what notices whose name is on it.
        $observation = $this->engine->recordManual(
            supplier: $supplier,
            checkType: CheckType::DOCUMENT_AUTHENTICITY,
            status: VerificationStatus::VERIFIED,
            observed: ['company_name' => 'Ndlovu Logistics CC', 'registration_number' => '2019/123456/07'],
            claimed: ['company_name' => $supplier->company_name, 'registration_number' => $supplier->company_registration_number],
            note: 'Read from bbbee.pdf by AI (confidence: high).',
        );

        $this->assertEquals(VerificationStatus::MISMATCH, $observation->status);
        $this->assertContains('company_name', $observation->mismatchedFields());
        $this->assertStringContainsString('Ndlovu Logistics CC', $observation->summary);

        // And it reaches the flags, because a compliant-looking certificate nobody questioned is the
        // whole point of the fraud.
        $this->assertDatabaseHas('supplier_risk_flags', [
            'supplier_id' => $supplier->id,
            'type' => 'verification_document_authenticity',
            'status' => 'open',
        ]);
    }

    public function test_a_certificate_in_the_suppliers_own_name_passes(): void
    {
        $supplier = $this->supplier();

        $observation = $this->engine->recordManual(
            supplier: $supplier,
            checkType: CheckType::DOCUMENT_AUTHENTICITY,
            status: VerificationStatus::VERIFIED,
            // As printed on the certificate: caps, entity form spelled out, number unformatted.
            observed: ['company_name' => 'ABC HOLDINGS (PTY) LTD', 'registration_number' => '201912345607'],
            claimed: ['company_name' => $supplier->company_name, 'registration_number' => $supplier->company_registration_number],
        );

        $this->assertEquals(VerificationStatus::VERIFIED, $observation->status, $observation->summary ?? '');
        $this->assertEquals(0, SupplierRiskFlag::where('supplier_id', $supplier->id)->count());
    }

    // ── The supplier page ───────────────────────────────────────────────────

    public function test_the_supplier_page_lists_every_check_including_the_ones_never_done(): void
    {
        $supplier = $this->supplier();
        $this->withoutVite();

        $this->engine->recordManual(
            supplier: $supplier,
            checkType: CheckType::TAX_COMPLIANCE,
            status: VerificationStatus::VERIFIED,
            observed: ['compliance_status' => 'Compliant'],
        );

        $this->get("/suppliers/{$supplier->uuid}")
            ->assertOk()
            ->assertInertia(function ($page) {
                $dd = $page->toArray()['props']['dueDiligence'];
                $checks = collect($dd['checks']);

                // A screen that listed only the checks that happened would read as a complete file.
                $this->assertCount(count(CheckType::verificationChecks()), $checks);
                $this->assertEquals('verified', $checks->firstWhere('key', CheckType::TAX_COMPLIANCE)['status']);
                $this->assertEquals('never_checked', $checks->firstWhere('key', CheckType::COMPANY_REGISTRY)['status']);
                $this->assertEquals('unknown', $checks->firstWhere('key', CheckType::COMPANY_REGISTRY)['severity']);

                $this->assertEquals(BankingGate::UNVERIFIED, $dd['banking']['state']);
                $this->assertCount(1, $dd['history']);
            });
    }

    public function test_the_supplier_page_masks_identifiers(): void
    {
        $supplier = $this->supplier();
        $this->withoutVite();

        SupplierPerson::create([
            'supplier_id' => $supplier->id, 'first_name' => 'Nomsa', 'surname' => 'Dlamini',
            'role' => 'director', 'id_type' => 'sa_id', 'id_number' => '9002026000082',
        ]);

        $response = $this->get("/suppliers/{$supplier->uuid}")->assertOk();

        $this->assertStringNotContainsString('9002026000082', $response->getContent(),
            'An ID number was rendered into the page.');

        $response->assertInertia(function ($page) {
            $person = collect($page->toArray()['props']['dueDiligence']['people'])->first();

            $this->assertEquals('•••••••••0082', $person['id_number']);
            $this->assertTrue($person['has_id']);
        });
    }
}
