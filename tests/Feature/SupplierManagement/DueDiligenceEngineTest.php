<?php

namespace Tests\Feature\SupplierManagement;

use App\Modules\SupplierManagement\DueDiligence\Contracts\VerificationProvider;
use App\Modules\SupplierManagement\DueDiligence\Data\ProviderHealth;
use App\Modules\SupplierManagement\DueDiligence\Data\VerificationRequest;
use App\Modules\SupplierManagement\DueDiligence\Data\VerificationResult;
use App\Modules\SupplierManagement\DueDiligence\Services\EvidenceLedger;
use App\Modules\SupplierManagement\DueDiligence\Services\ProviderRegistry;
use App\Modules\SupplierManagement\DueDiligence\Services\VerificationEngine;
use App\Modules\SupplierManagement\DueDiligence\Support\CheckType;
use App\Modules\SupplierManagement\DueDiligence\Support\ProviderCatalogue;
use App\Modules\SupplierManagement\DueDiligence\Support\VerificationStatus;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierVerification;
use App\Modules\SupplierManagement\Models\TenantVerificationProvider;
use Illuminate\Support\Facades\DB;

/**
 * The Phase 0 engine: does a check leave the right trail, and does it ever lie?
 *
 * The tests that matter most here are the negative ones. A due-diligence system fails dangerously
 * rather than loudly — it does not crash, it quietly reports "nothing found" for a check it never
 * ran, and a supplier with a judgment against them onboards on a clean-looking file. So: an
 * unconfigured check must record UNAVAILABLE and never pass a gate, a provider that throws must not
 * take the request down with it, and a provider claiming success about a company whose name does not
 * match the supplier must be overruled.
 */
class DueDiligenceEngineTest extends SupplierTestCase
{
    private VerificationEngine $engine;

    private ProviderRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        ProviderCatalogue::install();

        $this->registry = app(ProviderRegistry::class);
        $this->engine = app(VerificationEngine::class);
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
            'is_active' => 1,
        ], $attributes));
    }

    /** A provider that answers however the test tells it to. */
    private function stub(string $code, callable $answer, array $capabilities = [CheckType::COMPANY_REGISTRY]): void
    {
        $this->registry->fake($code, new class($code, $answer, $capabilities) implements VerificationProvider
        {
            public function __construct(
                private string $code,
                private $answer,
                private array $capabilities,
            ) {}

            public function code(): string
            {
                return $this->code;
            }

            public function capabilities(): array
            {
                return $this->capabilities;
            }

            public function supports(string $checkType): bool
            {
                return in_array($checkType, $this->capabilities, true);
            }

            public function verify(VerificationRequest $request): VerificationResult
            {
                return ($this->answer)($request);
            }

            public function health(): ProviderHealth
            {
                return ProviderHealth::ok();
            }
        });
    }

    /** Enable a catalogue provider for this tenant so the registry will resolve it. */
    private function enable(string $code, array $checks = [CheckType::COMPANY_REGISTRY]): TenantVerificationProvider
    {
        return TenantVerificationProvider::create([
            'provider_code' => $code,
            'is_enabled' => true,
            'credentials' => ['api_key' => 'test-key'],
            'enabled_checks' => $checks,
            'settings' => ['checks' => array_fill_keys($checks, ['endpoint' => 'https://example.test/{registration_number}'])],
        ]);
    }

    // ── The dangerous default ────────────────────────────────────────────────

    public function test_a_check_with_no_provider_records_unavailable_and_does_not_pass(): void
    {
        $supplier = $this->supplier();

        $observation = $this->engine->verify($supplier, CheckType::TAX_COMPLIANCE);

        $this->assertEquals(VerificationStatus::UNAVAILABLE, $observation->status);
        $this->assertFalse($observation->passed(), 'An unrun check must never satisfy a gate.');
        $this->assertEquals('none', $observation->provider_code);
        $this->assertStringContainsString('No source is configured', $observation->summary);

        // Recorded, not skipped: the gap has to be visible on the Passport.
        $this->assertDatabaseHas('supplier_verifications', [
            'supplier_id' => $supplier->id,
            'check_type' => CheckType::TAX_COMPLIANCE,
            'status' => VerificationStatus::UNAVAILABLE,
        ]);
    }

    public function test_a_provider_that_throws_is_recorded_as_an_error_and_marks_the_connector_failing(): void
    {
        $supplier = $this->supplier();
        $config = $this->enable('cipc');
        $this->stub('cipc', fn () => throw new \RuntimeException('connection refused'));

        $observation = $this->engine->verify($supplier, CheckType::COMPANY_REGISTRY);

        $this->assertEquals(VerificationStatus::ERROR, $observation->status);
        $this->assertStringContainsString('connection refused', $observation->summary);
        $this->assertNotNull($config->fresh()->last_error_at, 'A throwing provider should show as failing in the registry.');
    }

    // ── The comparison gets the last word ───────────────────────────────────

    public function test_a_provider_claiming_success_on_a_different_company_is_overruled_as_a_mismatch(): void
    {
        $supplier = $this->supplier();
        $this->enable('cipc');
        $this->stub('cipc', fn () => VerificationResult::verified([
            'company_name' => 'Completely Different Trading',
            'registration_number' => '2019/123456/07',
        ]));

        $observation = $this->engine->verify($supplier, CheckType::COMPANY_REGISTRY);

        $this->assertEquals(VerificationStatus::MISMATCH, $observation->status);
        $this->assertContains('company_name', $observation->mismatchedFields());
        $this->assertStringContainsString('Completely Different Trading', $observation->summary);
    }

    public function test_entity_form_and_registration_formatting_do_not_count_as_mismatches(): void
    {
        $supplier = $this->supplier();
        $this->enable('cipc');

        // The registry writes the name in caps without punctuation and the number unformatted. Same
        // company; a raw string comparison would call it fraud.
        $this->stub('cipc', fn () => VerificationResult::verified([
            'company_name' => 'ABC HOLDINGS PROPRIETARY LIMITED',
            'registration_number' => '201912345607',
        ]));

        $observation = $this->engine->verify($supplier, CheckType::COMPANY_REGISTRY);

        $this->assertEquals(VerificationStatus::VERIFIED, $observation->status, $observation->summary ?? '');
    }

    public function test_a_near_miss_goes_to_a_person_rather_than_passing_or_failing(): void
    {
        $supplier = $this->supplier();
        $this->enable('cipc');
        $this->stub('cipc', fn () => VerificationResult::verified([
            'company_name' => 'ABC Holdings Group',
            'registration_number' => '2019/123456/07',
        ]));

        $observation = $this->engine->verify($supplier, CheckType::COMPANY_REGISTRY);

        $this->assertEquals(VerificationStatus::MANUAL_REVIEW, $observation->status);
        $this->assertFalse($observation->passed());
    }

    public function test_fields_the_source_volunteers_are_informational_not_mismatches(): void
    {
        $supplier = $this->supplier();
        $this->enable('cipc');
        $this->stub('cipc', fn () => VerificationResult::verified([
            'company_name' => 'ABC Holdings (Pty) Ltd',
            'registration_number' => '2019/123456/07',
            'company_status' => 'In business',       // never claimed by the supplier
        ]));

        $observation = $this->engine->verify($supplier, CheckType::COMPANY_REGISTRY);

        $this->assertEquals(VerificationStatus::VERIFIED, $observation->status);
        $this->assertEquals('informational', $observation->field_matches['company_status']['kind']);
    }

    // ── The observation log ─────────────────────────────────────────────────

    public function test_re_running_a_check_appends_and_the_newest_answer_is_the_current_one(): void
    {
        $supplier = $this->supplier();
        $this->enable('cipc');

        $this->stub('cipc', fn () => VerificationResult::verified([
            'company_name' => 'ABC Holdings (Pty) Ltd', 'registration_number' => '2019/123456/07',
        ]));
        $first = $this->engine->verify($supplier, CheckType::COMPANY_REGISTRY);

        // The registry now shows a different name — the company was renamed, or worse.
        $this->stub('cipc', fn () => VerificationResult::verified([
            'company_name' => 'XYZ Trading', 'registration_number' => '2019/123456/07',
        ]));
        $second = $this->engine->verify($supplier, CheckType::COMPANY_REGISTRY);

        $this->assertEquals(2, SupplierVerification::where('supplier_id', $supplier->id)->count(),
            'Observations must append — history is what makes monitoring possible.');
        $this->assertTrue($first->fresh()->exists, 'The earlier observation must survive.');

        $current = SupplierVerification::currentFor($supplier->id);
        $this->assertEquals($second->id, $current[CheckType::COMPANY_REGISTRY]->id);
        $this->assertEquals(VerificationStatus::MISMATCH, $current[CheckType::COMPANY_REGISTRY]->status);
    }

    public function test_a_verified_answer_carries_a_freshness_horizon_and_a_failed_one_does_not(): void
    {
        $supplier = $this->supplier();
        $this->enable('cipc');

        $this->stub('cipc', fn () => VerificationResult::verified([
            'company_name' => 'ABC Holdings (Pty) Ltd', 'registration_number' => '2019/123456/07',
        ]));
        $passed = $this->engine->verify($supplier, CheckType::COMPANY_REGISTRY);

        $this->assertNotNull($passed->valid_until);
        $this->assertEquals(
            now()->addDays(CheckType::validDays(CheckType::COMPANY_REGISTRY))->toDateString(),
            $passed->valid_until->toDateString()
        );
        $this->assertFalse($passed->isStale());

        $this->stub('cipc', fn () => VerificationResult::notFound('No such company.'));
        $failed = $this->engine->verify($supplier, CheckType::COMPANY_REGISTRY);

        $this->assertNull($failed->valid_until, 'A failure must not expire into a pass.');
    }

    // ── Manual checks ───────────────────────────────────────────────────────

    public function test_a_person_can_record_a_check_and_it_is_attributed_to_them(): void
    {
        $supplier = $this->supplier();

        $observation = $this->engine->recordManual(
            supplier: $supplier,
            checkType: CheckType::TAX_COMPLIANCE,
            status: VerificationStatus::VERIFIED,
            observed: ['tax_reference_number' => '9012345678', 'compliance_status' => 'Compliant'],
            note: 'Verified on SARS eFiling with the PIN the supplier provided.',
            reference: 'PIN-ABC123',
        );

        $this->assertEquals(VerificationStatus::VERIFIED, $observation->status);
        $this->assertEquals('manual', $observation->provider_code);
        $this->assertEquals($this->user->id, $observation->checked_by);
        $this->assertEquals('PIN-ABC123', $observation->reference);
        $this->assertStringContainsString('SARS eFiling', $observation->note);
        // A manual pass still expires: it was a statement about a moment.
        $this->assertNotNull($observation->valid_until);
    }

    public function test_a_manual_check_is_still_subject_to_the_comparison(): void
    {
        $supplier = $this->supplier();

        // The person types in what the registry showed, and it is not this supplier.
        $observation = $this->engine->recordManual(
            supplier: $supplier,
            checkType: CheckType::COMPANY_REGISTRY,
            status: VerificationStatus::VERIFIED,
            observed: ['company_name' => 'Something Else Entirely', 'registration_number' => '2019/123456/07'],
        );

        $this->assertEquals(VerificationStatus::MISMATCH, $observation->status,
            'A person cannot mark a mismatch as verified by mislabelling it.');
    }

    // ── Quotas ──────────────────────────────────────────────────────────────

    public function test_an_exhausted_monthly_allowance_stops_the_call_instead_of_spending_money(): void
    {
        $supplier = $this->supplier();
        $config = $this->enable('credit_bureau', [CheckType::COMPANY_REGISTRY]);
        $config->forceFill([
            'monthly_quota' => 2,
            'calls_this_month' => 2,
            'quota_month' => now()->format('Y-m'),
        ])->save();

        $called = false;
        $this->stub('credit_bureau', function () use (&$called) {
            $called = true;

            return VerificationResult::verified(['company_name' => 'ABC Holdings (Pty) Ltd']);
        });

        $observation = $this->engine->verify($supplier, CheckType::COMPANY_REGISTRY, providerCode: 'credit_bureau');

        $this->assertFalse($called, 'The provider must not be called once the allowance is spent.');
        $this->assertEquals(VerificationStatus::UNAVAILABLE, $observation->status);
        $this->assertStringContainsString('allowance', $observation->summary);
    }

    public function test_the_call_counter_rolls_over_with_the_month(): void
    {
        $config = $this->enable('cipc');
        $config->forceFill([
            'monthly_quota' => 5,
            'calls_this_month' => 5,
            'quota_month' => now()->subMonth()->format('Y-m'),
        ])->save();

        $this->assertFalse($config->fresh()->quotaExhausted(), 'Last month\'s calls must not block this month.');
        $this->assertEquals(0, $config->fresh()->currentMonthCalls());
    }

    // ── Evidence ────────────────────────────────────────────────────────────

    public function test_every_check_writes_a_chained_evidence_entry(): void
    {
        $supplier = $this->supplier();
        $this->enable('cipc');
        $this->stub('cipc', fn () => VerificationResult::verified([
            'company_name' => 'ABC Holdings (Pty) Ltd', 'registration_number' => '2019/123456/07',
        ], raw: ['source' => 'stub', 'payload' => ['status' => 'In business']]));

        $this->engine->verify($supplier, CheckType::COMPANY_REGISTRY);
        $this->engine->recordManual($supplier, CheckType::TAX_COMPLIANCE, VerificationStatus::VERIFIED);

        $entries = DB::table('dd_evidence_entries')->where('company_id', $this->companyId)->orderBy('sequence')->get();

        $this->assertCount(2, $entries);
        $this->assertEquals([1, 2], $entries->pluck('sequence')->map(fn ($s) => (int) $s)->all());
        $this->assertNull($entries[0]->previous_hash, 'The first entry starts the chain.');
        $this->assertEquals($entries[0]->hash, $entries[1]->previous_hash, 'Each entry chains off the one before it.');
        $this->assertEquals('verification.recorded', $entries[0]->action);

        $verdict = app(EvidenceLedger::class)->verify($this->companyId);
        $this->assertTrue($verdict['ok'], $verdict['reason'] ?? '');
        $this->assertEquals(2, $verdict['checked']);
    }

    public function test_the_ledger_rejects_edits_and_notices_tampering(): void
    {
        $supplier = $this->supplier();
        $this->engine->recordManual($supplier, CheckType::TAX_COMPLIANCE, VerificationStatus::VERIFIED);

        // The database refuses to let history be edited at all.
        try {
            DB::table('dd_evidence_entries')->where('company_id', $this->companyId)->update(['summary' => 'nothing to see']);
            $this->fail('The append-only trigger should have refused this update.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }

    public function test_a_tampered_entry_breaks_the_chain_at_a_reported_position(): void
    {
        $supplier = $this->supplier();
        $this->engine->recordManual($supplier, CheckType::TAX_COMPLIANCE, VerificationStatus::VERIFIED);
        $this->engine->recordManual($supplier, CheckType::TAX_VAT_VENDOR, VerificationStatus::VERIFIED);

        // Someone with database access drops the trigger and rewrites an entry — the chain is what
        // catches that, and it is the only thing that can.
        DB::statement('ALTER TABLE dd_evidence_entries DISABLE TRIGGER dd_evidence_append_only');
        DB::table('dd_evidence_entries')->where('company_id', $this->companyId)->where('sequence', 1)
            ->update(['status' => VerificationStatus::VERIFIED, 'summary' => 'quietly rewritten']);
        DB::statement('ALTER TABLE dd_evidence_entries ENABLE TRIGGER dd_evidence_append_only');

        $verdict = app(EvidenceLedger::class)->verify($this->companyId);

        $this->assertFalse($verdict['ok']);
        $this->assertEquals(1, $verdict['broken_at']);
        $this->assertStringContainsString('hash', (string) $verdict['reason']);
    }

    // ── Catalogue & registry ────────────────────────────────────────────────

    public function test_the_catalogue_installs_idempotently_and_manual_needs_no_credentials(): void
    {
        $before = DB::table('verification_providers')->count();
        ProviderCatalogue::install();

        $this->assertEquals($before, DB::table('verification_providers')->count(), 'Re-installing must not duplicate.');

        $manual = \App\Modules\SupplierManagement\Models\DueDiligenceProvider::where('code', 'manual')->first();
        $this->assertFalse($manual->requires_credentials);
        $this->assertTrue($manual->supports(CheckType::TAX_COMPLIANCE));
    }

    public function test_automatic_resolution_finds_configured_sources_and_never_falls_back_to_a_person(): void
    {
        $this->enable('cipc');
        $this->stub('cipc', fn () => VerificationResult::verified([]));

        $this->assertEquals('cipc', $this->registry->forCheck(CheckType::COMPANY_REGISTRY)?->code());
        $this->assertTrue($this->registry->hasDataSourceFor(CheckType::COMPANY_REGISTRY));

        // Nothing is configured for the CSD. Resolving to the manual provider here would let a
        // scheduled run record "awaiting review" for a check nobody performed.
        $this->assertNull($this->registry->forCheck(CheckType::GOVERNMENT_CSD));
        $this->assertFalse($this->registry->hasDataSourceFor(CheckType::GOVERNMENT_CSD));
        $this->assertEquals('manual', $this->registry->manual()->code(), 'A person is still offered explicitly.');
    }

    public function test_a_disabled_provider_is_not_used(): void
    {
        $config = $this->enable('cipc');
        $config->update(['is_enabled' => false]);
        $this->registry->forget('cipc');

        $this->assertNull($this->registry->forCheck(CheckType::COMPANY_REGISTRY));
    }

    public function test_credentials_are_encrypted_at_rest_and_only_key_names_are_exposed(): void
    {
        $config = $this->enable('cipc');

        $raw = DB::table('company_verification_providers')->where('id', $config->id)->value('credentials');

        $this->assertStringNotContainsString('test-key', (string) $raw, 'Credentials must not be readable in the database.');
        $this->assertEquals(['api_key'], $config->credentialKeys());
    }

    public function test_an_unknown_check_type_is_refused_rather_than_silently_recorded(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->engine->verify($this->supplier(), 'company.astrology');
    }
}
