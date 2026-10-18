<?php

namespace Tests\Feature\ContractManagement;

use App\Core\User\Models\User;
use App\Modules\ContractManagement\Models\ContractsTemplate;
use App\Modules\ContractManagement\Templates\ContractTemplateLibrary;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The starter contract library and its installer.
 *
 * Most of what can go wrong here is silent. A clause key that does not exist in the bank raises an
 * undefined-index only when that one template is rendered; a template installed with a null company
 * is invisible under row-level security while still looking installed; and an installer that is not
 * idempotent quietly doubles the library every time somebody re-runs it. So each of those is
 * asserted rather than assumed.
 */
class ContractTemplateLibraryTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // The route sits behind `module.active:contract_management`. Without a registered, active
        // module row every request here redirects with "module is not active", and the assertions
        // below would be measuring the gate rather than the copy-on-write behaviour.
        $module = DB::table('security_modules')->where('code_name', 'contract_management')->first(['id']);

        $module === null
            ? DB::table('security_modules')->insert([
                'uuid' => (string) Str::uuid(),
                'code_name' => 'contract_management', 'name' => 'Contract Management',
                'category_id' => DB::table('security_categories')->min('id'),
                'active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ])
            : DB::table('security_modules')->where('id', $module->id)->update(['active' => 1]);

        Cache::forget('module_active_contract_management');
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        Cache::forget('module_active_contract_management');

        parent::tearDown();
    }

    public function test_every_clause_reference_resolves(): void
    {
        $bank = ContractTemplateLibrary::clauses();
        $missing = [];

        foreach (ContractTemplateLibrary::templates() as $definition) {
            foreach ($definition['clauses'] as $key) {
                if (! array_key_exists($key, $bank)) {
                    $missing[] = "{$definition['code']} → {$key}";
                }
            }
        }

        // Without this, a typo surfaces as a 500 the first time a user opens that one template.
        $this->assertSame([], $missing,
            "These templates reference clauses that are not in the bank:\n  ".implode("\n  ", $missing));
    }

    public function test_every_template_renders_a_complete_document(): void
    {
        foreach (ContractTemplateLibrary::templates() as $definition) {
            $html = ContractTemplateLibrary::render($definition);
            $code = $definition['code'];

            $this->assertStringContainsString('<h1>', $html, "{$code} has no title");
            $this->assertStringContainsString('Before use:', $html,
                "{$code} is missing the legal-review notice");
            $this->assertStringContainsString($definition['parties'][0], $html,
                "{$code} does not name its first party");
            $this->assertStringContainsString($definition['parties'][1], $html,
                "{$code} does not name its second party");
            $this->assertStringContainsString('warrants authority to sign', $html,
                "{$code} has no signature block");

            // Each clause is a list item; a document with almost none of them means the spine did
            // not resolve.
            $this->assertGreaterThanOrEqual(10, substr_count($html, '<li>'),
                "{$code} rendered only ".substr_count($html, '<li>').' clauses');
        }
    }

    public function test_codes_and_names_are_unique(): void
    {
        $codes = array_column(ContractTemplateLibrary::templates(), 'code');
        $names = array_map(
            fn ($d) => ContractTemplateLibrary::displayName($d),
            ContractTemplateLibrary::templates(),
        );

        // The installer matches an existing template by name, so a duplicate name would make one
        // template permanently overwrite the other on every run.
        $this->assertSame($codes, array_unique($codes), 'Duplicate template code');
        $this->assertSame($names, array_unique($names), 'Duplicate template name');
    }

    public function test_the_library_covers_the_major_sectors(): void
    {
        $sectors = array_unique(array_column(ContractTemplateLibrary::templates(), 'sector'));

        foreach ([
            'Cross-sector', 'Employment', 'Construction', 'Mining', 'Transport and Logistics',
            'Facilities and Security', 'Information Technology', 'Healthcare', 'Agriculture',
            'Property', 'Retail and Distribution', 'Marketing and Media', 'Financial Services',
            'Education and Training', 'Manufacturing', 'Public Sector', 'Energy and Utilities',
        ] as $expected) {
            $this->assertContains($expected, $sectors, "No templates for the {$expected} sector");
        }
    }

    public function test_install_writes_one_shared_library_row(): void
    {
        $this->artisan('contracts:templates:install', ['--sector' => 'Mining'])->assertSuccessful();

        $rows = DB::table('contracts_templates')
            ->where('name', 'Mining — Mining Contractor Agreement')
            ->get(['company_id', 'content', 'status']);

        $this->assertCount(1, $rows, 'The library should be installed once, not per tenant.');

        // A null company_id is what makes it shared. It only works because the tenant_isolation
        // policy carries a null allowance — without that this row is invisible to every tenant.
        $this->assertNull($rows[0]->company_id);
        $this->assertTrue((bool) $rows[0]->status);
        $this->assertStringContainsString('Mine Health and Safety Act', (string) $rows[0]->content);
    }

    public function test_every_tenant_sees_the_shared_library(): void
    {
        $this->artisan('contracts:templates:install', ['--sector' => 'Mining'])->assertSuccessful();

        $name = 'Mining — Mining Contractor Agreement';

        foreach (DB::table('companies')->limit(3)->pluck('id') as $companyId) {
            app(CurrentCompany::class)->set((int) $companyId);

            // Through the model, so CompanyScope is live — the point being that a tenant-scoped
            // query returns the shared row and not just their own.
            $this->assertTrue(
                ContractsTemplate::where('name', $name)->exists(),
                "Company {$companyId} cannot see the shared library template.",
            );
        }
    }

    public function test_a_tenant_editing_a_shared_template_gets_their_own_copy(): void
    {
        $this->artisan('contracts:templates:install', ['--sector' => 'Mining'])->assertSuccessful();

        $companyId = (int) DB::table('companies')->min('id');
        $name = 'Mining — Mining Contractor Agreement';

        $shared = DB::table('contracts_templates')->whereNull('company_id')->where('name', $name)
            ->first(['id', 'content']);

        app(CurrentCompany::class)->set($companyId);

        $this->actingAs($this->userFor($companyId))
            ->patch("/contracts/templates/{$shared->id}", [
                'name' => $name,
                'content' => '<p>Our negotiated mining terms.</p>',
                'status' => true,
            ]);

        // The shared row must be untouched: editing it in place would rewrite this contract for
        // every other company on the installation.
        $this->assertSame($shared->content,
            DB::table('contracts_templates')->where('id', $shared->id)->value('content'),
            'A tenant edit rewrote the shared library template.');

        $this->assertSame('<p>Our negotiated mining terms.</p>',
            DB::table('contracts_templates')->where('company_id', $companyId)->where('name', $name)
                ->value('content'),
            'The tenant did not get their own copy.');
    }

    public function test_a_tenant_cannot_delete_a_shared_template(): void
    {
        $this->artisan('contracts:templates:install', ['--sector' => 'Mining'])->assertSuccessful();

        $companyId = (int) DB::table('companies')->min('id');
        $shared = DB::table('contracts_templates')->whereNull('company_id')
            ->where('name', 'Mining — Mining Contractor Agreement')->first(['id']);

        app(CurrentCompany::class)->set($companyId);

        $this->actingAs($this->userFor($companyId))
            ->delete("/contracts/templates/{$shared->id}");

        $this->assertNull(
            DB::table('contracts_templates')->where('id', $shared->id)->value('deleted_at'),
            'A tenant deleted a template belonging to every company.',
        );
    }

    public function test_installing_twice_does_not_duplicate(): void
    {
        $args = ['--sector' => 'Mining'];

        $this->artisan('contracts:templates:install', $args)->assertSuccessful();
        $this->artisan('contracts:templates:install', $args)->assertSuccessful();

        $this->assertSame(1, DB::table('contracts_templates')
            ->where('name', 'Mining — Mining Contractor Agreement')->count());
    }

    public function test_a_library_template_that_was_deleted_does_not_come_back(): void
    {
        $args = ['--sector' => 'Mining'];
        $name = 'Mining — Mining Contractor Agreement';

        $this->artisan('contracts:templates:install', $args)->assertSuccessful();

        DB::table('contracts_templates')->whereNull('company_id')->where('name', $name)
            ->update(['deleted_at' => now()]);

        $this->artisan('contracts:templates:install', $args)->assertSuccessful();

        $this->assertSame(0, DB::table('contracts_templates')
            ->whereNull('company_id')->where('name', $name)->whereNull('deleted_at')->count());
    }

    public function test_refresh_does_not_touch_tenant_copies(): void
    {
        $args = ['--sector' => 'Mining'];
        $name = 'Mining — Mining Contractor Agreement';
        $companyId = (int) DB::table('companies')->min('id');

        $this->artisan('contracts:templates:install', $args)->assertSuccessful();

        DB::table('contracts_templates')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'name' => $name,
            'content' => '<p>Our negotiated mining terms.</p>',
            'status' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Shift the shared text so --refresh has something to do.
        DB::table('contracts_templates')->whereNull('company_id')->where('name', $name)
            ->update(['content' => '<p>stale</p>']);

        $this->artisan('contracts:templates:install', $args + ['--refresh' => true])->assertSuccessful();

        $this->assertSame('<p>Our negotiated mining terms.</p>',
            DB::table('contracts_templates')->where('company_id', $companyId)->where('name', $name)
                ->value('content'),
            'A library refresh overwrote a tenant\'s own template.');

        $this->assertStringContainsString('Mine Health and Safety Act',
            (string) DB::table('contracts_templates')->whereNull('company_id')->where('name', $name)
                ->value('content'),
            'The shared library template was not refreshed.');
    }

    public function test_dry_run_writes_nothing(): void
    {
        $before = DB::table('contracts_templates')->count();

        $this->artisan('contracts:templates:install', ['--sector' => 'Mining', '--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame($before, DB::table('contracts_templates')->count());
    }

    public function test_an_unknown_sector_fails_loudly(): void
    {
        $this->artisan('contracts:templates:install', ['--sector' => 'Nonexistent'])->assertFailed();
    }

    private function userFor(int $companyId): User
    {
        $id = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'email' => 'tpl-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'),
            'company_id' => $companyId,
            'is_platform_admin' => true, // so the Contract policy does not decide this test
            'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return User::withoutGlobalScopes()->findOrFail($id);
    }
}
