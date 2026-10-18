<?php

namespace Tests\Feature\SupplierManagement;

use App\Modules\SupplierManagement\Models\SupplierDocumentType;
use App\Modules\SupplierManagement\Support\SupplierDocumentLibrary;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The statutory documents a South African supplier is asked for.
 *
 * None of these can be verified through an API. CIPC data is reachable through a paid aggregator and
 * SARS tax status through a PIN, but the Department of Employment and Labour issues a Letter of Good
 * Standing through a portal and publishes no interface — so for the statutory set, verification is
 * the document and its expiry date, or it is nothing.
 *
 * Which makes the install itself the feature, and these the things that must hold: it is per tenant,
 * because a null-company row would install cleanly and show nobody anything; it is idempotent on a
 * code rather than a name, because a tenant who renames one must not get a second copy; and it
 * adopts the types that were already there instead of standing a duplicate beside them.
 */
class ComplianceDocumentsTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        app(CurrentCompany::class)->set($this->companyId);

        SupplierDocumentType::query()->forceDelete();
    }

    private function install(array $options = []): void
    {
        $this->artisan('suppliers:documents:install', $options + ['--company' => $this->companyId]);
    }

    // ── It installs ─────────────────────────────────────────────────────────

    public function test_the_labour_letter_of_good_standing_is_one_of_them(): void
    {
        // The document the whole of this exists for: required on most sites, lapses annually, and
        // obtainable only through a portal.
        $this->install();

        $coida = SupplierDocumentType::where('code', 'coida_good_standing')->first();

        $this->assertNotNull($coida);
        $this->assertTrue((bool) $coida->compulsory);
        $this->assertSame(12, $coida->default_validity_months);
        $this->assertStringContainsString('CompEasy', (string) $coida->guidance);
    }

    public function test_every_library_document_lands(): void
    {
        $this->install();

        foreach (SupplierDocumentLibrary::definitions() as $definition) {
            $this->assertNotNull(
                SupplierDocumentType::where('code', $definition['code'])->first(),
                "{$definition['code']} was not installed",
            );
        }
    }

    public function test_it_installs_against_the_tenant_and_not_as_a_shared_row(): void
    {
        /*
         * The row-level policy here admits a row only when its company matches the session, so a
         * null-company row installs cleanly, shows nothing to anybody, and reads as a bug in the
         * screen rather than in the install.
         */
        $this->install();

        $this->assertSame(
            0,
            DB::table('supplier_document_types')->whereNull('company_id')->whereNotNull('code')->count(),
            'a library document with no company would be invisible to every tenant',
        );
        $this->assertGreaterThan(0, SupplierDocumentType::where('company_id', $this->companyId)->count());
    }

    // ── It does not duplicate ───────────────────────────────────────────────

    public function test_running_it_twice_changes_nothing(): void
    {
        $this->install();
        $first = SupplierDocumentType::count();

        $this->install();

        $this->assertSame($first, SupplierDocumentType::count());
    }

    public function test_a_renamed_document_is_not_duplicated(): void
    {
        /*
         * Matching on name would give a tenant who renamed "Letter of Good Standing" to "COIDA
         * letter" a second copy on the next run — and then two entries a supplier could upload
         * against, with no way to tell which one the expiry sweep is watching.
         */
        $this->install();
        SupplierDocumentType::where('code', 'coida_good_standing')
            ->update(['document_type_name' => 'COIDA letter']);

        $this->install();

        $this->assertSame(1, SupplierDocumentType::where('code', 'coida_good_standing')->count());
        $this->assertSame('COIDA letter',
            SupplierDocumentType::where('code', 'coida_good_standing')->value('document_type_name'),
            'the install must not rename what a tenant chose to call it');
    }

    public function test_a_type_installed_before_codes_existed_is_adopted_not_duplicated(): void
    {
        // Older installations have a "Tax Clearance Certificate" with no code. A second one beside
        // it would leave nobody sure which to upload against.
        SupplierDocumentType::create([
            'uuid' => (string) Str::uuid(),
            'document_type_name' => 'Tax Clearance Certificate',
            'compulsory' => true, 'active' => true,
        ]);

        $this->install();

        $matches = SupplierDocumentType::where('code', 'tax_clearance')->get();

        $this->assertCount(1, $matches);
        $this->assertSame('Tax Clearance Certificate', $matches->first()->document_type_name);
        $this->assertSame(12, $matches->first()->default_validity_months, 'adoption brings the library metadata');
    }

    public function test_a_refresh_updates_the_guidance_without_undoing_a_tenants_decisions(): void
    {
        /*
         * The name and the compulsory flag are a tenant's to set. Overwriting them on every refresh
         * would undo their work each time the library gains a document.
         */
        $this->install();
        SupplierDocumentType::where('code', 'public_liability')->update([
            'document_type_name' => 'Insurance schedule',
            'compulsory' => true,
            'guidance' => 'stale',
        ]);

        $this->install(['--refresh' => true]);

        $type = SupplierDocumentType::where('code', 'public_liability')->first();

        $this->assertSame('Insurance schedule', $type->document_type_name);
        $this->assertTrue((bool) $type->compulsory);
        $this->assertNotSame('stale', $type->guidance, 'the guidance is ours to keep current');
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->install(['--dry-run' => true]);

        $this->assertSame(0, SupplierDocumentType::count());
    }

    // ── What the types carry ────────────────────────────────────────────────

    public function test_a_document_that_does_not_expire_offers_no_validity(): void
    {
        /*
         * A registration certificate does not lapse — the company either exists or it does not.
         * Giving it a validity would have the expiry sweep chasing a renewal that is not a thing.
         */
        $this->install();

        $this->assertNull(SupplierDocumentType::where('code', 'cipc_registration')->value('default_validity_months'));
        $this->assertNull(SupplierDocumentType::where('code', 'vat_certificate')->value('default_validity_months'));
    }

    public function test_only_what_a_buyer_truly_cannot_proceed_without_is_compulsory(): void
    {
        /*
         * Marking everything compulsory produces a directory in which every supplier is permanently
         * non-compliant, and a warning that is always on is a warning nobody reads.
         */
        $this->install();

        $compulsory = SupplierDocumentType::where('compulsory', true)->pluck('code')->all();
        $total = count(SupplierDocumentLibrary::definitions());

        $this->assertContains('coida_good_standing', $compulsory);
        $this->assertLessThan($total, count($compulsory), 'not everything can be mandatory');
        $this->assertNotContains('vat_certificate', $compulsory, 'a supplier under the threshold has none');
    }

    public function test_every_document_says_who_issues_it_and_how_to_get_one(): void
    {
        // The useful half when there is nothing to call.
        $this->install();

        foreach (SupplierDocumentType::whereNotNull('code')->get() as $type) {
            $this->assertNotEmpty($type->authority, "{$type->code} does not say who issues it");
            $this->assertNotEmpty($type->guidance, "{$type->code} does not say how to get one");
        }
    }
}
