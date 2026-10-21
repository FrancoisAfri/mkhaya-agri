<?php

namespace Tests\Feature\Tax;

use App\Modules\Finance\Accounting\Models\FinEntity;
use App\Modules\Finance\Accounting\Services\CurrentEntity;
use App\Modules\Finance\Tax\Models\TaxRegistration;
use App\Modules\Finance\Tax\Support\TaxBusinesses;
use Illuminate\Support\Facades\DB;

/**
 * Which businesses the tax screens offer.
 *
 * Reported: the Business list showed Accounting's businesses even where Accounting is not used.
 * Rule: with Accounting enabled for the company, its businesses; without it, the company itself.
 */
class TaxBusinessesTest extends TaxTestCase
{
    private FinEntity $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->second = FinEntity::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'NSEZ Logistics', 'status' => 'active']);
        DB::table('security_modules')->updateOrInsert(['code_name' => 'accounting'], ['name' => 'Accounting (GL)', 'active' => 1, 'uuid' => (string) \Illuminate\Support\Str::uuid()]);
        // The company's plan includes Accounting (the entitlement test the module gate applies).
        DB::table('company_modules')->updateOrInsert(['company_id' => $this->company->id, 'module_code' => 'accounting'],
            ['source' => 'addon', 'price_monthly' => 0, 'created_at' => now(), 'updated_at' => now()]);
        app()->forgetInstance(\App\Modules\Tenancy\Services\CompanyEntitlements::class);
    }

    public function test_with_accounting_the_list_is_accountings_businesses(): void
    {
        $names = array_column(app(TaxBusinesses::class)->options(), 'name');

        $this->assertContains('NSEZ Logistics', $names);
        $this->assertContains($this->entity->name, $names);
        $this->assertTrue(app(TaxBusinesses::class)->accountingEnabled());
    }

    public function test_without_accounting_the_list_is_the_company_itself(): void
    {
        // Not on the plan: SaaS answers from company_modules (self-hosted from the licence).
        DB::table('company_modules')->where('company_id', $this->company->id)->where('module_code', 'accounting')->delete();
        app()->forgetInstance(\App\Modules\Tenancy\Services\CompanyEntitlements::class);

        $options = app(TaxBusinesses::class)->options();

        $this->assertCount(1, $options);
        $this->assertSame($this->company->name, $options[0]['name'], 'Named as the company was registered.');
        $this->assertSame(app(CurrentEntity::class)->defaultFor($this->company->id)->id, $options[0]['id']);
        $this->assertSame($options[0]['id'], app(TaxBusinesses::class)->single());
    }

    public function test_a_business_from_another_company_is_refused(): void
    {
        $rival = \App\Modules\Tenancy\Models\Company::create(['name' => 'Rival']);
        // Theirs is written inside their organisation: the database refuses it from ours.
        $theirs = app(\App\Modules\Tenancy\Services\TenantContext::class)->run((int) $rival->id,
            fn () => FinEntity::withoutGlobalScopes()->create(['company_id' => $rival->id, 'name' => 'Rival Ltd', 'status' => 'active']));

        $this->actingAs($this->reviewer)->post(route('tax.registrations.store'), [
            'entity_id' => $theirs->id, 'tax_type_id' => $this->vat()->id, 'reference' => '4123456789',
            'effective_from' => '2026-01-01', 'filing_frequency' => 'monthly',
        ])->assertSessionHasErrors('entity_id');
        $this->assertNull(app(TaxBusinesses::class)->filter($theirs->id));
    }

    public function test_a_registration_for_another_business_stays_on_the_list(): void
    {
        // Accounting's session points at the first business; the registration is for the second.
        app(CurrentEntity::class)->set($this->entity->id);

        $this->actingAs($this->reviewer)->post(route('tax.registrations.store'), [
            'entity_id' => $this->second->id, 'tax_type_id' => $this->vat()->id, 'reference' => '4123456789',
            'effective_from' => '2026-01-01', 'filing_frequency' => 'monthly',
        ])->assertSessionHas('success');

        $this->actingAs($this->reviewer)->get(route('tax.registrations.index'))->assertOk()
            ->assertInertia(fn ($p) => $p->has('registrations', 1)->where('registrations.0.entity', 'NSEZ Logistics')->has('businesses', 2));

        // …and the filter narrows to one business without hiding the other's records elsewhere.
        $this->actingAs($this->reviewer)->get(route('tax.registrations.index', ['business' => $this->entity->id]))
            ->assertInertia(fn ($p) => $p->has('registrations', 0));
        $this->assertSame(1, TaxRegistration::count());
    }
}
