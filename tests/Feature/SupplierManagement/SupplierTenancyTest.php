<?php

namespace Tests\Feature\SupplierManagement;

use App\Modules\SupplierManagement\Models\PurchaseOrder;
use App\Modules\SupplierManagement\Models\RfxEvent;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierInvoice;
use App\Modules\SupplierManagement\Models\SupplierOnboarding;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * One tenant must not be able to see, name, or act on another tenant's suppliers.
 *
 * Two separate mechanisms are supposed to guarantee that — the `BelongsToCompany` global scope in
 * Eloquent, and row-level security in Postgres — and they fail in different circumstances, so both
 * are checked here rather than assumed from the presence of the trait.
 *
 * The awkward cases get their own tests: `exists:suppliers,id` in a validation rule is a raw query
 * that never sees the Eloquent scope, and several controller actions take a bare integer id and call
 * `findOrFail` on it. Those are the two shapes a cross-tenant read usually takes in a Laravel app.
 */
class SupplierTenancyTest extends SupplierTestCase
{
    private int $otherCompanyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->otherCompanyId = $this->makeCompany('Rival Tenant');
    }

    public function test_a_supplier_is_stamped_with_the_creating_tenant(): void
    {
        $supplier = $this->makeSupplier();

        $this->assertSame($this->companyId, (int) $supplier->company_id);
    }

    public function test_suppliers_belonging_to_another_tenant_are_invisible(): void
    {
        $mine = $this->makeSupplier(['company_name' => 'Mine']);
        $theirs = $this->asOtherTenant(fn () => $this->makeSupplier(['company_name' => 'Theirs']));

        $visible = Supplier::pluck('id')->all();

        $this->assertContains($mine->id, $visible);
        $this->assertNotContains($theirs->id, $visible);
    }

    public function test_every_record_type_in_the_module_is_scoped_to_its_tenant(): void
    {
        $theirSupplier = $this->asOtherTenant(function () {
            $supplier = $this->makeSupplier(['company_name' => 'Rival Co']);

            RfxEvent::create(['rfx_number' => 'RFX-X', 'title' => 'Rival tender', 'type' => 'rfq', 'status' => 'draft']);
            PurchaseOrder::create(['po_number' => 'PO-X', 'supplier_id' => $supplier->id, 'title' => 'Rival PO', 'amount' => 1000]);
            SupplierInvoice::create(['invoice_number' => 'INV-X', 'supplier_id' => $supplier->id, 'amount' => 1000]);
            SupplierOnboarding::create(['company_name' => 'Rival prospect']);

            return $supplier;
        });

        $this->assertSame(0, Supplier::where('id', $theirSupplier->id)->count());
        $this->assertSame(0, RfxEvent::where('rfx_number', 'RFX-X')->count());
        $this->assertSame(0, PurchaseOrder::where('po_number', 'PO-X')->count());
        $this->assertSame(0, SupplierInvoice::where('invoice_number', 'INV-X')->count());
        $this->assertSame(0, SupplierOnboarding::where('company_name', 'Rival prospect')->count());
    }

    public function test_the_supplier_list_screen_does_not_return_another_tenants_rows(): void
    {
        $this->makeSupplier(['company_name' => 'Visible Supplier']);
        $theirs = $this->asOtherTenant(fn () => $this->makeSupplier(['company_name' => 'Hidden Supplier']));

        $response = $this->get(route('suppliers.index'));
        $response->assertSuccessful();

        $this->assertStringNotContainsString('Hidden Supplier', $response->getContent());
        $this->assertStringNotContainsString((string) $theirs->uuid, $response->getContent());
    }

    public function test_another_tenants_supplier_cannot_be_opened_by_id(): void
    {
        $theirs = $this->asOtherTenant(fn () => $this->makeSupplier(['company_name' => 'Not Yours']));

        $this->get(route('suppliers.show', $theirs))->assertNotFound();
        $this->patch(route('suppliers.update', $theirs), ['company_name' => 'Hijacked'])->assertNotFound();
        $this->delete(route('suppliers.destroy', $theirs))->assertNotFound();

        $this->assertSame('Not Yours', $this->asOtherTenant(fn () => Supplier::find($theirs->id)->company_name));
    }

    /**
     * A supplier id in a request body is now looked up again, everywhere.
     *
     * `exists:suppliers,id` passes for any tenant's supplier — it is a raw query and the global scope
     * never sees it — so the only thing that turns a foreign id into a 404 rather than a
     * cross-tenant write is reading the record back through Eloquent. The purchase-order path always
     * did that; the award, bid-capture and invoice paths did not, and each of those was a
     * cross-tenant *write*: one company's tender awarded to another company's supplier, one
     * company's bid list gaining a bidder it cannot see, one company's payables gaining an invoice
     * addressed to a stranger.
     */
    public function test_a_supplier_id_from_another_tenant_is_refused_everywhere(): void
    {
        $mine = $this->makeSupplier();
        $theirs = $this->asOtherTenant(fn () => $this->makeSupplier(['company_name' => 'Rival Bidder']));

        $rfx = RfxEvent::create([
            'rfx_number' => 'RFX-'.uniqid(), 'title' => 'Open tender', 'type' => 'rfq', 'status' => 'published',
        ]);
        $rfx->invitations()->create(['supplier_id' => $mine->id, 'status' => 'invited', 'invited_at' => now()]);

        $this->post(route('suppliers.sourcing.responses', $rfx->id), ['supplier_id' => $theirs->id, 'amount' => 1])
            ->assertNotFound();
        $this->assertSame(0, $rfx->responses()->count());

        $this->post(route('suppliers.sourcing.award', $rfx->id), ['supplier_id' => $theirs->id])
            ->assertNotFound();
        $this->assertNull($rfx->fresh()->awarded_supplier_id);

        $this->post(route('suppliers.invoices.store'), [
            'invoice_number' => 'XT-001', 'supplier_id' => $theirs->id, 'amount' => 12_000,
        ])->assertNotFound();
        $this->assertSame(0, SupplierInvoice::withoutGlobalScopes()->where('invoice_number', 'XT-001')->count());
    }

    /**
     * The purchase-order path is the one that gets this right, and shows the fix.
     *
     * It re-reads the supplier through Eloquent before using the id, so the global scope applies and
     * a foreign supplier throws a 404 instead of being written. The same two lines in the award,
     * response and invoice actions would close the case above.
     */
    public function test_the_purchase_order_path_rejects_a_foreign_supplier_id(): void
    {
        $theirs = $this->asOtherTenant(fn () => $this->makeSupplier(['company_name' => 'Rival Supplier']));

        $this->post(route('suppliers.purchase-orders.store'), [
            'supplier_id' => $theirs->id, 'title' => 'Cross-tenant order', 'amount' => 99_000,
        ])->assertNotFound();

        $this->assertSame(0, PurchaseOrder::withoutGlobalScopes()->where('supplier_id', $theirs->id)->count());
    }

    /**
     * Actions that take a bare `int $id` and call `findOrFail` skip route-model binding.
     *
     * Binding is where Laravel would normally apply the scope for you. These actions do their own
     * lookup, so the protection has to come from the model's global scope — which is what this
     * checks, on the KYC and onboarding actions that use that shape.
     */
    public function test_raw_id_controller_actions_still_refuse_another_tenants_records(): void
    {
        $theirs = $this->asOtherTenant(fn () => SupplierOnboarding::create(['company_name' => 'Rival prospect']));

        $this->get(route('suppliers.onboarding.edit', $theirs->id))->assertNotFound();
        $this->patch(route('suppliers.onboarding.update', $theirs->id), ['company_name' => 'Stolen'])->assertNotFound();
        $this->delete(route('suppliers.onboarding.destroy', $theirs->id))->assertNotFound();
        $this->post(route('suppliers.onboarding.send-mail', $theirs->id))->assertNotFound();

        $this->assertSame(
            'Rival prospect',
            $this->asOtherTenant(fn () => SupplierOnboarding::find($theirs->id)->company_name),
        );
    }

    public function test_a_soft_deleted_supplier_cannot_be_restored_across_tenants(): void
    {
        $theirs = $this->asOtherTenant(function () {
            $supplier = $this->makeSupplier(['company_name' => 'Rival Deleted']);
            $supplier->delete();

            return $supplier;
        });

        $this->post(route('suppliers.restore', $theirs->id))->assertNotFound();
        $this->delete(route('suppliers.force-delete', $theirs->id))->assertNotFound();

        $this->assertNotNull($this->asOtherTenant(fn () => Supplier::withTrashed()->find($theirs->id)));
    }

    public function test_the_module_is_unreachable_for_a_tenant_that_has_not_bought_it(): void
    {
        // A second company with no add-on and the default `standard` plan, which does not list
        // supplier_management. This is the shape of every newly created tenant.
        $outsiderCompany = $this->makeCompany('Unentitled Tenant');
        $outsider = $this->makeUser($outsiderCompany);

        DB::table('company_modules')
            ->where('company_id', $outsiderCompany)
            ->where('module_code', 'supplier_management')
            ->delete();

        app(CurrentCompany::class)->set($outsiderCompany);

        $this->actingAs($outsider)
            ->get(route('suppliers.index'))
            ->assertRedirect();

        $this->assertSame(
            'This module is not included in your plan. Please upgrade or contact your provider.',
            session('error'),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Run a closure as the other tenant, then put the original context back.
     *
     * Restoring matters more than it looks: `CurrentCompany` is a singleton for the life of the
     * request, so a helper that leaves it pointing at the rival company turns every later assertion
     * in the test into a check of the wrong tenant — which passes.
     */
    private function asOtherTenant(callable $callback): mixed
    {
        $current = app(CurrentCompany::class);

        $current->set($this->otherCompanyId);

        try {
            return $callback();
        } finally {
            $current->set($this->companyId);
        }
    }
}
