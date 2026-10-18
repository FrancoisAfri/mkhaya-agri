<?php

namespace Tests\Feature\SupplierManagement;

use App\Core\License\LicenseService;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\Tenancy\Services\CompanyEntitlements;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * The module has to behave on a self-hosted box, not only on the SaaS platform.
 *
 * These are two genuinely different gates. In SaaS a tenant reaches the module through its plan or an
 * à-la-carte add-on; self-hosted there is no plan at all — a signed licence names the modules, and
 * when no public key is configured the licence is dormant and everything is permitted. Everything
 * else in this suite exercises the SaaS path, so without these the self-hosted edition was covered by
 * reading the code rather than running it.
 *
 * The licence is faked rather than signed. Generating a real Ed25519 licence in a test would be
 * testing `LicenseService`, which has its own tests; what matters here is that Supplier Management
 * asks the right question and honours the answer.
 */
class SupplierEditionTest extends SupplierTestCase
{
    public function test_self_hosted_reaches_the_module_when_the_licence_grants_it(): void
    {
        $this->beSelfHosted(allows: true);

        $this->get(route('suppliers.index'))->assertSuccessful();
        $this->get(route('suppliers.sourcing.index'))->assertSuccessful();
    }

    public function test_self_hosted_is_refused_when_the_licence_does_not_name_the_module(): void
    {
        $this->beSelfHosted(allows: false);

        $this->get(route('suppliers.index'))->assertRedirect();

        $this->assertSame(
            'This module is not included in your plan. Please upgrade or contact your provider.',
            session('error'),
        );
    }

    /**
     * A self-hosted install ignores the plan entirely.
     *
     * This is the case a SaaS-shaped assumption breaks: the company row still carries a `plan`
     * column, and if anything consulted it here, a self-hosted customer on the default `starter`
     * plan would be locked out of a module their licence pays for.
     */
    public function test_the_plan_is_irrelevant_once_self_hosted(): void
    {
        // Strip the add-on the fixture bought and put the company on a plan that excludes the module.
        DB::table('company_modules')->where('company_id', $this->companyId)->delete();
        DB::table('companies')->where('id', $this->companyId)->update(['plan' => 'starter']);

        $this->beSelfHosted(allows: true);

        $this->get(route('suppliers.index'))->assertSuccessful();
    }

    /** And the mirror image: on SaaS the licence is irrelevant. */
    public function test_the_licence_is_irrelevant_on_saas(): void
    {
        config(['edition.edition' => 'saas']);
        $this->fakeLicence(allows: false);

        // The fixture bought the à-la-carte add-on, which is what SaaS actually consults.
        $this->get(route('suppliers.index'))->assertSuccessful();
    }

    /**
     * The whole lifecycle works self-hosted, not just the front door.
     *
     * Worth its own test because the module writes through services that reach for tenancy — the
     * budget bridge, the compliance check, the notification fan-out — and a self-hosted install is
     * the configuration where a missing company is most likely to go unnoticed.
     */
    public function test_the_supplier_lifecycle_runs_end_to_end_self_hosted(): void
    {
        Notification::fake();

        $this->beSelfHosted(allows: true);

        $this->post(route('suppliers.store'), [
            'company_name' => 'Self Hosted Supplies',
            'email'        => 'sh@example.test',
        ])->assertRedirect();

        $supplier = Supplier::where('company_name', 'Self Hosted Supplies')->firstOrFail();
        $this->assertSame($this->companyId, (int) $supplier->company_id);

        $this->post(route('suppliers.activate', $supplier))->assertRedirect();
        $this->assertSame(1, (int) $supplier->fresh()->is_active);

        $this->post(route('suppliers.purchase-orders.store'), [
            'supplier_id' => $supplier->id, 'title' => 'Self-hosted order', 'amount' => 4_000,
        ])->assertRedirect();

        $this->post(route('suppliers.invoices.store'), [
            'invoice_number' => 'SH-001', 'supplier_id' => $supplier->id, 'amount' => 4_000,
        ])->assertRedirect();

        $this->assertDatabaseHas('supplier_invoices', [
            'invoice_number' => 'SH-001',
            'company_id'     => $this->companyId,
        ]);
    }

    /** The unauthenticated surfaces are edition-agnostic, and must stay reachable. */
    public function test_the_public_portal_and_registration_work_self_hosted(): void
    {
        $supplier = $this->makeSupplier();

        $this->beSelfHosted(allows: false); // even with the module unlicensed

        auth()->logout();
        app(\App\Modules\Tenancy\Services\CurrentCompany::class)->set(null);

        // Deliberate: a supplier already holding a link is a third party who did nothing wrong, and
        // cutting them off because the buyer's licence lapsed would strand their documents.
        $this->get(route('supplier.portal.show', $supplier->portal_token))->assertSuccessful();
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function beSelfHosted(bool $allows): void
    {
        config(['edition.edition' => 'self_hosted']);

        $this->fakeLicence($allows);
    }

    /**
     * Swap the licence for one with a known answer.
     *
     * Bound into the container rather than stubbed on the entitlements service, because
     * `CompanyEntitlements` resolves it through constructor injection — replacing it here is what a
     * differently-licensed box actually looks like from the module's side.
     */
    private function fakeLicence(bool $allows): void
    {
        $licence = \Mockery::mock(LicenseService::class);
        $licence->shouldReceive('allows')->andReturn($allows);
        $licence->shouldReceive('status')->andReturn([
            'valid' => true, 'enforced' => true, 'reason' => 'ok', 'payload' => ['modules' => $allows ? ['*'] : []],
        ]);

        $this->app->instance(LicenseService::class, $licence);

        // The entitlements service is resolved once and holds the licence it was built with.
        $this->app->forgetInstance(CompanyEntitlements::class);
    }
}
