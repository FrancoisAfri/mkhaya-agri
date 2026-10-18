<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Services\ExternalImportService;
use App\Modules\Finance\Accounting\Services\PartySyncService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Sage Customer Master File export: every column it carries that we have somewhere to
 * put should land, including the address that Sage splits over numbered columns.
 */
class SageCustomerImportTest extends TestCase
{
    use DatabaseTransactions;

    private const HEADERS = [
        'Name', 'Category', 'Opening Balance', 'Postal Address Line 1', 'Postal Address Line 2',
        'Postal Address Postal Code', 'Delivery Address Line 1', 'Delivery Address Line 2',
        'Credit Limit', 'Contact Name', 'Telephone Number', 'Fax Number', 'Email Address',
        'Web Address', 'VAT Reference', 'Active', 'Sales Rep', 'Default Discount Percentage',
    ];

    private function csv(array ...$rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sage') . '.csv';
        $out = fopen($path, 'w');
        fputcsv($out, self::HEADERS);
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        fclose($out);

        return new UploadedFile($path, 'Customers.csv', null, null, true);
    }

    private function row(string $name): array
    {
        return [
            $name, 'Consulting', '0.00', 'PO Box 1234', 'Sandton', '2196', '12 Rivonia Rd', 'Sandton',
            '25000.00', 'Thabo M', '011 555 0100', '011 555 0101', 'ap@' . Str::slug($name) . '.co.za',
            'www.example.co.za', '4123456789', 'True', 'N. Dlamini', '5.00',
        ];
    }

    public function test_it_maps_every_supported_column_off_a_sage_customer_export(): void
    {
        $name = 'Afrigrowth ' . Str::random(6);

        app(ExternalImportService::class)->import($this->csv($this->row($name)), 'sage', 'customers');

        $party = FinParty::where('name', $name)->firstOrFail();
        $this->assertSame('Thabo M', $party->contact_name);
        $this->assertSame('011 555 0100', $party->phone);
        $this->assertSame('011 555 0101', $party->fax);
        $this->assertSame('www.example.co.za', $party->website);
        $this->assertSame('4123456789', $party->vat_number);
        $this->assertSame('Consulting', $party->category);
        $this->assertSame('N. Dlamini', $party->sales_rep);
        $this->assertEquals(25000.00, (float) $party->credit_limit);
        $this->assertEquals(5.00, (float) $party->default_discount_percent);
        $this->assertTrue((bool) $party->is_active);

        // The two address blocks are joined out of their numbered columns, and kept apart.
        $this->assertSame('PO Box 1234, Sandton, 2196', $party->postal_address);
        $this->assertSame('12 Rivonia Rd, Sandton', $party->address);
    }

    public function test_preview_reports_the_address_columns_as_mapped_not_ignored(): void
    {
        $preview = app(ExternalImportService::class)->preview($this->csv($this->row('Preview Co')), 'sage', 'customers');

        $this->assertIsArray($preview['mapped']['postal_address']);
        $this->assertContains('Postal Address Line 1', $preview['mapped']['postal_address']);
        $this->assertNotContains('Postal Address Line 1', $preview['unmapped']);
        $this->assertNotContains('Credit Limit', $preview['unmapped']);
        // Columns we genuinely have nowhere to put are still reported as ignored.
        $this->assertContains('Opening Balance', $preview['unmapped']);
    }

    /** Switch CRM on for this test (rolled back with the transaction) so the sync path really runs. */
    private function activateCrm(): void
    {
        $row = DB::table('security_modules')->where('code_name', 'crm')->first();
        if ($row) {
            DB::table('security_modules')->where('id', $row->id)->update(['active' => 1]);
        } else {
            DB::table('security_modules')->insert([
                'uuid' => (string) Str::uuid(), 'name' => 'CRM', 'path' => 'crm', 'code_name' => 'crm',
                'category_id' => (int) DB::table('security_modules')->min('category_id'),
                'active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        app(\App\Modules\Shared\Party\ModuleAvailability::class)->flush();
    }

    public function test_imported_customers_can_be_pushed_up_into_crm(): void
    {
        $this->activateCrm();
        $sync = app(PartySyncService::class);
        if (! $sync->crmActive()) {
            $this->markTestSkipped('CRM module not active.');
        }

        $stem = 'Afrigrowth Consulting ' . Str::random(6);
        $existing = DB::table('crm_accounts')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => $stem,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // The same company as the CRM knows it, plus one it has never heard of.
        $result = app(ExternalImportService::class)->import(
            $this->csv($this->row($stem . ' (Pty) Ltd'), $this->row('Brand New ' . Str::random(6))),
            'sage',
            'customers',
        );

        $crm = $sync->pushCustomersToCrm($result['party_ids']);

        $this->assertSame(1, $crm['linked']);   // matched through the legal-form suffix
        $this->assertSame(1, $crm['created']);
        $this->assertSame($existing, FinParty::where('name', $stem . ' (Pty) Ltd')->value('crm_account_id'));
    }

    public function test_sync_from_the_crm_side_picks_up_every_unlinked_customer(): void
    {
        $this->activateCrm();
        $sync = app(PartySyncService::class);
        if (! $sync->crmActive()) {
            $this->markTestSkipped('CRM module not active.');
        }

        $name = 'Standalone ' . Str::random(6);
        app(ExternalImportService::class)->import($this->csv($this->row($name)), 'sage', 'customers');
        $before = $sync->customersNotInCrm();
        $this->assertGreaterThan(0, $before);

        // No ids passed: everything not yet in CRM comes across.
        $result = $sync->pushCustomersToCrm();

        $this->assertSame($before, $result['created'] + $result['linked']);
        $this->assertSame(0, $sync->customersNotInCrm());
        $this->assertNotNull(FinParty::where('name', $name)->value('crm_account_id'));
    }

    public function test_pushing_to_crm_is_skipped_without_the_opt_in(): void
    {
        $name = 'Untouched ' . Str::random(6);
        app(ExternalImportService::class)->import($this->csv($this->row($name)), 'sage', 'customers');

        $party = FinParty::where('name', $name)->firstOrFail();
        $this->assertNull($party->crm_account_id);
        $this->assertSame('imported', $party->source);
    }
}
