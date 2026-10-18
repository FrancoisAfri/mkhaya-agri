<?php

namespace Tests\Feature\ProcurementGovernance;

use App\Modules\Procurement\Governance\Services\RecordService;
use App\Modules\Procurement\Governance\Support\Integration\ChainLinker;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MakesCompanies;
use Tests\TestCase;

/**
 * §11: the chain across modules — request → requisition → order → invoice, and award → supplier →
 * contract.
 *
 * These links are what make the 360° record and the spend report say anything at all about
 * contracts and actual spend. Nothing writes them at award time, because the documents do not exist
 * yet; they are reconciled afterwards. The failure this guards against is quiet — the tabs simply
 * stay empty and the "awards with no contract" check flags every award forever.
 */
class ChainLinkerTest extends TestCase
{
    use DatabaseTransactions, MakesCompanies;

    private int $companyId;

    private int $recordId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        Cache::flush();
        app(CurrentCompany::class)->set($this->companyId);

        // The test database carries a leaner module registry than a real install, and the linker
        // correctly declines to touch a module that is not registered. Register the neighbours so
        // these tests exercise the linking itself rather than the (separately tested) gate.
        foreach (['supplier_management', 'contract_management', 'procurement_requests', 'accounting'] as $code) {
            $this->ensureModule($code);
        }

        Cache::flush();

        $this->recordId = (int) app(RecordService::class)->open($this->companyId, [
            'title' => 'Network refresh',
            'estimated_value' => 900_000,
            'stage' => 'SOURCING',
        ])->id;
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        Cache::flush();

        parent::tearDown();
    }

    private function linker(): ChainLinker
    {
        return app(ChainLinker::class);
    }

    /** Register a neighbouring module as installed and active. Rolled back with the transaction. */
    private function ensureModule(string $code): void
    {
        $existing = DB::table('security_modules')->where('code_name', $code)->first();

        if ($existing) {
            DB::table('security_modules')->where('id', $existing->id)->update(['active' => 1]);

            return;
        }

        DB::table('security_modules')->insert([
            'uuid' => (string) Str::uuid(),
            'code_name' => $code,
            'name' => Str::headline($code),
            'path' => '/'.str_replace('_', '-', $code),
            'active' => 1,
            'category_id' => DB::table('security_categories')->min('id'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function links(string $role): Collection
    {
        return DB::table('procgov_record_links')
            ->where('record_id', $this->recordId)
            ->where('link_role', $role)
            ->get();
    }

    private function withModuleOff(string $code, callable $body): void
    {
        $previous = DB::table('security_modules')->where('code_name', $code)->value('active');
        DB::table('security_modules')->where('code_name', $code)->update(['active' => 0]);
        Cache::flush();

        try {
            $body();
        } finally {
            DB::table('security_modules')->where('code_name', $code)->update(['active' => $previous ?? 1]);
            Cache::flush();
        }
    }

    private function supplier(string $name = 'Acme Networks'): int
    {
        return DB::table('suppliers')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'supplier_number' => 'SUP-'.Str::random(5),
            'company_name' => $name,
            'portal_token' => Str::random(48),
            'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function awardTo(int $supplierId): void
    {
        $reviewId = DB::table('procgov_tender_reviews')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'reference' => 'TR-CHAIN-'.Str::random(4),
            'title' => 'Network refresh',
            'methodology' => 'WEIGHTED',
            'status' => 'AWARDED',
            'currency' => 'ZAR',
            'record_id' => $this->recordId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('procgov_bidders')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'review_id' => $reviewId,
            'supplier_id' => $supplierId,
            'supplier_name' => 'Acme Networks',
            'bid_amount' => 850_000,
            'currency' => 'ZAR',
            'is_disqualified' => false,
            'status' => 'AWARDED',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function requisition(?int $supplierId = null): int
    {
        $requisitionId = DB::table('procurement_requisitions')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'requisition_number' => 'REQ-'.Str::random(5),
            'title' => 'Network refresh',
            'procurement_method' => 'OPEN_TENDER',
            'supplier_id' => $supplierId,
            'status' => 'approved',
            'estimated_total' => 900_000,
            'currency' => 'ZAR',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('procgov_tender_reviews')
            ->where('record_id', $this->recordId)
            ->update(['requisition_id' => $requisitionId]);

        return $requisitionId;
    }

    private function purchaseOrder(int $requisitionId, ?int $contractId = null, ?int $supplierId = null): int
    {
        return DB::table('purchase_orders')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'po_number' => 'PO-'.Str::random(5),
            // NOT NULL on this table — an order always belongs to a supplier.
            'supplier_id' => $supplierId ?? $this->supplier('PO supplier'),
            'title' => 'Switches and installation',
            'amount' => 850_000,
            'currency' => 'ZAR',
            'status' => 'approved',
            'procurement_requisition_id' => $requisitionId,
            'contract_id' => $contractId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function contract(int $supplierId): int
    {
        return DB::table('contracts')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'contract_number' => 'CON-'.Str::random(5),
            'title' => 'Network refresh agreement',
            'contract_value' => 850_000,
            'contract_status' => 'active',
            'supplier_id' => $supplierId,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── The chain ───────────────────────────────────────────────────────────────────────────────

    public function test_the_awarded_supplier_is_linked_to_the_record(): void
    {
        $supplierId = $this->supplier();
        $this->awardTo($supplierId);

        $this->linker()->sync($this->companyId, $this->recordId);

        $link = $this->links('supplier')->first();

        $this->assertNotNull($link, 'The awarded supplier was not linked.');
        $this->assertSame($supplierId, (int) $link->entity_id);
        $this->assertSame('Acme Networks', $link->label);
        $this->assertSame('supplier_management', $link->source_module);
    }

    public function test_orders_contracts_and_invoices_are_reached_through_the_requisition(): void
    {
        $supplierId = $this->supplier();
        $this->awardTo($supplierId);

        $contractId = $this->contract($supplierId);
        $requisitionId = $this->requisition($supplierId);
        $orderId = $this->purchaseOrder($requisitionId, $contractId);

        DB::table('supplier_invoices')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'invoice_number' => 'INV-'.Str::random(5),
            'supplier_id' => $supplierId,
            'purchase_order_id' => $orderId,
            'amount' => 425_000,
            'currency' => 'ZAR',
            'status' => 'approved',
            'payment_status' => 'paid',
            'invoice_date' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $written = $this->linker()->sync($this->companyId, $this->recordId);

        $this->assertSame(1, $this->links('purchase_order')->count());
        $this->assertSame(1, $this->links('contract')->count());
        $this->assertSame(1, $this->links('invoice')->count());
        $this->assertArrayHasKey('request', $written);

        // The expiring-contract sweep reads end_date from the link, so it must be carried across.
        $meta = json_decode($this->links('contract')->first()->meta, true);
        $this->assertNotNull($meta['end_date'] ?? null);
    }

    public function test_reconciliation_is_idempotent(): void
    {
        $supplierId = $this->supplier();
        $this->awardTo($supplierId);
        $requisitionId = $this->requisition($supplierId);
        $this->purchaseOrder($requisitionId);

        $this->linker()->sync($this->companyId, $this->recordId);
        $this->linker()->sync($this->companyId, $this->recordId);
        $this->linker()->sync($this->companyId, $this->recordId);

        // Running nightly must not accumulate duplicates on the 360° view.
        $this->assertSame(1, $this->links('supplier')->count());
        $this->assertSame(1, $this->links('purchase_order')->count());
    }

    public function test_a_contract_not_raised_through_an_order_is_linked_by_hand_not_guessed(): void
    {
        $supplierId = $this->supplier();
        $this->awardTo($supplierId);
        $contractId = $this->contract($supplierId);

        // Same supplier, same period — but no purchase order joins them, so inference would be a
        // guess. A wrong contract on a procurement record is worse than a missing one.
        $this->linker()->sync($this->companyId, $this->recordId);
        $this->assertSame(0, $this->links('contract')->count());

        $this->assertTrue($this->linker()->linkContractManually($this->companyId, $this->recordId, $contractId));
        $this->assertSame(1, $this->links('contract')->count());
    }

    public function test_a_requisition_without_a_record_gets_one_so_the_chain_starts_at_the_request(): void
    {
        $requisitionId = DB::table('procurement_requisitions')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'requisition_number' => 'REQ-'.Str::random(5),
            'title' => 'Laptops for finance',
            'procurement_method' => 'RFQ',
            'status' => 'approved',
            'estimated_total' => 180_000,
            'currency' => 'ZAR',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $record = $this->linker()->openFromRequisition($this->companyId, $requisitionId);

        $this->assertNotNull($record);
        $this->assertSame('REQUEST', $record->stage);
        $this->assertEqualsWithDelta(180_000.0, (float) $record->estimated_value, 0.01);

        // Called twice it must not open a second spine for the same procurement.
        $again = $this->linker()->openFromRequisition($this->companyId, $requisitionId);
        $this->assertSame((int) $record->id, (int) $again->id);
    }

    // ── Standalone ──────────────────────────────────────────────────────────────────────────────

    public function test_linking_is_a_no_op_when_the_neighbouring_modules_are_absent(): void
    {
        $supplierId = $this->supplier();
        $this->awardTo($supplierId);
        $requisitionId = $this->requisition($supplierId);
        $this->purchaseOrder($requisitionId, $this->contract($supplierId));

        $this->withModuleOff('supplier_management', function () {
            $this->withModuleOff('contract_management', function () {
                $this->withModuleOff('procurement_requests', function () {
                    $written = $this->linker()->sync($this->companyId, $this->recordId);

                    // No links, and crucially no exception — the record is simply thinner.
                    $this->assertSame([], $written);
                    $this->assertSame(0, $this->links('supplier')->count());
                    $this->assertSame(0, $this->links('contract')->count());
                    $this->assertSame(0, $this->links('purchase_order')->count());
                });
            });
        });
    }

    public function test_opening_from_a_requisition_is_refused_without_the_procurement_module(): void
    {
        $this->withModuleOff('procurement_requests', function () {
            $this->assertNull($this->linker()->openFromRequisition($this->companyId, 1));
        });
    }

    // ── Tenancy ─────────────────────────────────────────────────────────────────────────────────

    public function test_another_tenants_documents_are_never_linked(): void
    {
        $other = $this->otherCompanyId($this->companyId);

        // A supplier belonging to another tenant, awarded on this tenant's review by a bad id.
        $foreign = DB::table('suppliers')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => (int) $other,
            'supplier_number' => 'SUP-'.Str::random(5),
            'company_name' => 'Other Tenant Supplier',
            'portal_token' => Str::random(48),
            'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->awardTo($foreign);

        $this->linker()->sync($this->companyId, $this->recordId);

        $this->assertSame(0, $this->links('supplier')->count());
    }
}
