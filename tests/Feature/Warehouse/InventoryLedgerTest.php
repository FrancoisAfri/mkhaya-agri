<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockLot;
use App\Modules\Finance\Accounting\Models\FinStockMovement;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\WarehouseManagement\Models\ReasonCode;
use App\Modules\Operations\WarehouseManagement\Models\StockAdjustment;
use App\Modules\Operations\WarehouseManagement\Services\ClientVisibility;
use App\Modules\Operations\WarehouseManagement\Services\ItemCatalogue;
use App\Modules\Operations\WarehouseManagement\Services\LocationService;
use App\Modules\Operations\WarehouseManagement\Services\StockAdjustments;
use App\Modules\Operations\WarehouseManagement\Services\StockAvailability;
use App\Modules\Operations\WarehouseManagement\Services\StockHistory;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use App\Modules\Operations\WarehouseManagement\Support\StockStates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * WMS-001 (third-party stock), WMS-020 to WMS-025: the ledger as the warehouse uses it.
 *
 * The figures are asserted against stock posted through the ledger, never against rows written by
 * hand, because the point of a single ledger is that every screen agrees with it.
 */
class InventoryLedgerTest extends WarehouseTestCase
{
    private FinWarehouse $store;

    private FinStockItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();
        $this->store = $this->warehouse('MAIN');
        $this->item = $this->asCompany($this->company, function () {
            app(ItemCatalogue::class)->ensureUnits($this->company->id);
            app(StockAdjustments::class)->ensureReasonCodes($this->company->id);

            return app(ItemCatalogue::class)->create(['company_id' => $this->company->id, 'code' => 'RICE-5', 'name' => 'Rice 5kg', 'uom' => 'ea', 'standard_cost' => 50]);
        });
    }

    private function ledger(): StockLedger
    {
        return app(StockLedger::class);
    }

    private function reason(string $code): ReasonCode
    {
        return ReasonCode::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }

    private function receive(float $qty, array $options = []): FinStockMovement
    {
        return $this->asCompany($this->company, fn () => $this->ledger()->receive($this->item->fresh(), $qty, 50, $this->store, $options));
    }

    private function client(string $name = 'Acme Retail'): int
    {
        return (int) DB::table('fin_parties')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->company->id, 'kind' => 'customer', 'name' => $name,
            'code' => 'C'.Str::upper(Str::random(6)),
            'entity_id' => $this->store->entity_id, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── WMS-001: a client's goods ────────────────────────────────────────────────────────────

    public function test_a_clients_goods_are_counted_but_never_valued(): void
    {
        $owner = $this->client();
        $clientItem = $this->asCompany($this->company, fn () => tap(app(ItemCatalogue::class)->create([
            'company_id' => $this->company->id, 'code' => 'ACME-TV', 'name' => 'Acme TV', 'uom' => 'ea',
        ]), fn ($i) => app(ItemCatalogue::class)->update($i, ['owner_party_id' => $owner])));

        $this->assertFalse($clientItem->fresh()->isValued());

        $m = $this->asCompany($this->company, fn () => $this->ledger()->receive($clientItem->fresh(), 40, 900, $this->store));

        $this->assertEquals(40, $clientItem->fresh()->qty_on_hand, 'Client stock was not counted.');
        $this->assertNull($m->journal_id, "A client's goods posted to this company's books.");
        $this->assertEquals(0, (float) $m->value);
        $this->assertEquals(0, $clientItem->fresh()->stockValue());

        // And the schema itself refuses a valued item that belongs to somebody else.
        DB::statement('SAVEPOINT owned_valued');
        try {
            DB::table('fin_stock_items')->where('id', $clientItem->id)->update(['is_valued' => true]);
            $this->fail('An owned item was marked valued.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertTrue(true);
        } finally {
            DB::statement('ROLLBACK TO SAVEPOINT owned_valued');
        }
    }

    public function test_the_owner_cannot_change_once_stock_has_moved(): void
    {
        $this->receive(5);

        $this->expectExceptionMessage('owner cannot change');

        $this->asCompany($this->company, fn () => app(ItemCatalogue::class)->update($this->item->fresh(), ['owner_party_id' => $this->client()]));
    }

    public function test_a_clients_stock_is_only_visible_to_people_granted_that_client(): void
    {
        $owner = $this->client('Beta Foods');
        $clientItem = $this->asCompany($this->company, fn () => tap(app(ItemCatalogue::class)->create([
            'company_id' => $this->company->id, 'code' => 'BETA-1', 'name' => 'Beta beans', 'uom' => 'ea',
        ]), fn ($i) => app(ItemCatalogue::class)->update($i, ['owner_party_id' => $owner])));
        $this->asCompany($this->company, fn () => $this->ledger()->receive($clientItem->fresh(), 3, 0, $this->store));
        $this->receive(2);

        $codes = fn ($user) => collect($this->actingAs($user)->get(route('warehouse.stock.index'))->assertOk()
            ->viewData('page')['props']['items']['data'])->pluck('code')->all();

        $this->assertSame(['RICE-5'], $codes($this->viewer), 'An ungranted person saw a client\'s stock.');
        $this->actingAs($this->viewer)->get(route('warehouse.stock.show', $clientItem->uuid))->assertForbidden();
        $this->actingAs($this->viewer)->get(route('warehouse.products.show', $clientItem->uuid))->assertForbidden();

        $this->asCompany($this->company, fn () => app(ClientVisibility::class)->grant($this->viewer->id, $owner));
        $this->assertSame(['BETA-1', 'RICE-5'], $codes($this->viewer));

        // The manager holds wms.clients.all and needs no grant.
        $this->assertSame(['BETA-1', 'RICE-5'], $codes($this->manager));
    }

    // ── WMS-021 states and WMS-022 availability ──────────────────────────────────────────────

    public function test_every_state_says_what_it_counts_towards(): void
    {
        $rules = collect(StockStates::table())->keyBy('state');

        $this->assertTrue($rules['AVAILABLE']['available']);
        foreach (['DAMAGED', 'QUARANTINE', 'INSPECTION', 'RECALLED', 'EXPIRED', 'IN_TRANSIT'] as $held) {
            $this->assertTrue($rules[$held]['physical'], "{$held} is not physical stock.");
            $this->assertFalse($rules[$held]['available'], "{$held} counts as available.");
            $this->assertTrue($rules[$held]['valuation'], "{$held} drops off the balance sheet before it is written off.");
        }
    }

    public function test_availability_separates_every_quantity_and_subtracts_a_reservation_once(): void
    {
        $transit = $this->asCompany($this->company, fn () => app(LocationService::class)->create($this->store, ['code' => 'TRUCK', 'kind' => 'IN_TRANSIT']));
        $lot = $this->asCompany($this->company, fn () => FinStockLot::create([
            'company_id' => $this->company->id, 'entity_id' => $this->item->entity_id, 'item_id' => $this->item->id,
            'lot_number' => 'OLD-1', 'expires_on' => now()->subDay()->toDateString(),
        ]));

        $this->receive(100);
        $this->receive(10, ['lot_id' => $lot->id]);
        $this->receive(7, ['bin_id' => $transit->id]);
        $this->asCompany($this->company, function () {
            $this->ledger()->changeStatus($this->item->fresh(), $this->store, 5, 'AVAILABLE', 'DAMAGED', ['reason' => 'Crushed']);
            $this->ledger()->reserve($this->item->fresh(), $this->store, 30, 'SALES_ORDER', 1);
            $this->ledger()->reserve($this->item->fresh(), $this->store, 10, 'TRANSFER', 2);
        });

        $a = $this->asCompany($this->company, fn () => app(StockAvailability::class)->forItem($this->item->fresh()))['totals'];

        $this->assertEquals(117, $a['physical']);
        $this->assertEquals(7, $a['in_transit']);
        $this->assertEquals(5, $a['held']);
        $this->assertEquals(10, $a['expired']);
        $this->assertEquals(95, $a['available_on_hand'], 'Damaged, expired or in-transit stock counted as available.');
        $this->assertEquals(40, $a['reserved']);
        $this->assertEquals(30, $a['allocated_not_picked']);
        $this->assertEquals(55, $a['available_to_promise'], 'A reservation was subtracted twice, or not at all.');
    }

    // ── WMS-020 and WMS-024: adjustments ─────────────────────────────────────────────────────

    public function test_a_small_adjustment_posts_at_once_with_its_reason_on_the_movement(): void
    {
        $this->receive(20);
        $adj = $this->asCompany($this->company, fn () => app(StockAdjustments::class)->request([
            'warehouse_id' => $this->store->id, 'item_id' => $this->item->id, 'qty' => -2,
            'reason_code_id' => $this->reason('DAMAGED')->id, 'notes' => 'Forklift tine',
        ], $this->manager));

        $this->assertSame(StockAdjustment::POSTED, $adj->status);
        $m = FinStockMovement::withoutGlobalScopes()->findOrFail($adj->movement_id);
        $this->assertSame('DAMAGED', $m->reason_code);
        $this->assertSame('WMS_ADJUSTMENT', $m->source_type);
        $this->assertNotNull($m->occurred_at);
        $this->assertEquals(18, $this->item->fresh()->qty_on_hand);
    }

    public function test_a_risky_adjustment_waits_and_nobody_approves_their_own(): void
    {
        $this->receive(20);
        $adj = $this->asCompany($this->company, fn () => app(StockAdjustments::class)->request([
            'warehouse_id' => $this->store->id, 'item_id' => $this->item->id, 'qty' => -3,
            'reason_code_id' => $this->reason('THEFT')->id,
        ], $this->supervisor));

        $this->assertSame(StockAdjustment::PENDING, $adj->status);
        $this->assertEquals(20, $this->item->fresh()->qty_on_hand, 'A pending adjustment moved stock.');

        try {
            $this->asCompany($this->company, fn () => app(StockAdjustments::class)->approve($adj, $this->supervisor));
            $this->fail('Somebody approved their own adjustment.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('somebody other', $e->getMessage());
        }

        // The schema refuses it too, whatever a future screen does.
        DB::statement('SAVEPOINT self_approve');
        try {
            DB::table('wms_stock_adjustments')->where('id', $adj->id)->update(['decided_by' => $this->supervisor->id, 'status' => 'POSTED']);
            $this->fail('The database accepted a self-approval.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertTrue(true);
        } finally {
            DB::statement('ROLLBACK TO SAVEPOINT self_approve');
        }

        $this->actingAs($this->manager)->post(route('warehouse.stock.adjustments.decide', $adj->uuid), ['decision' => 'approve', 'note' => 'CCTV reviewed'])
            ->assertSessionHasNoErrors();

        $adj->refresh();
        $this->assertSame(StockAdjustment::POSTED, $adj->status);
        $m = FinStockMovement::withoutGlobalScopes()->findOrFail($adj->movement_id);
        $this->assertSame($this->manager->id, $m->approved_by);
        $this->assertSame($adj->number, $m->approval_reference);
        $this->assertEquals(17, $this->item->fresh()->qty_on_hand);

        // Approving twice does not post twice.
        $this->actingAs($this->manager)->post(route('warehouse.stock.adjustments.decide', $adj->uuid), ['decision' => 'approve'])
            ->assertSessionHasErrors('refused');
        $this->assertEquals(17, $this->item->fresh()->qty_on_hand);
    }

    public function test_a_backdated_adjustment_needs_approval_and_a_closed_period_is_refused(): void
    {
        $this->receive(20);
        $adj = $this->asCompany($this->company, fn () => app(StockAdjustments::class)->request([
            'warehouse_id' => $this->store->id, 'item_id' => $this->item->id, 'qty' => -1,
            'reason_code_id' => $this->reason('COUNT')->id, 'occurred_at' => now()->subDays(10)->startOfMonth()->max(now()->subDays(10))->toDateTimeString(),
        ], $this->manager));
        $this->assertSame(StockAdjustment::PENDING, $adj->status);
        $this->assertStringContainsString('days back', implode(' ', $adj->approval_reasons));

        $closed = FinFiscalPeriod::create([
            'company_id' => $this->company->id, 'fiscal_year' => 2020, 'period_no' => 1,
            'start_date' => '2020-01-01', 'end_date' => '2020-01-31', 'status' => 'hard_close',
        ]);
        $this->expectExceptionMessage('closed in Finance');
        $this->asCompany($this->company, fn () => app(StockAdjustments::class)->request([
            'warehouse_id' => $this->store->id, 'item_id' => $this->item->id, 'qty' => -1,
            'reason_code_id' => $this->reason('COUNT')->id, 'occurred_at' => '2020-01-15 10:00:00',
        ], $this->manager));
    }

    public function test_a_reason_only_moves_stock_the_way_it_says(): void
    {
        $this->receive(5);

        $this->expectExceptionMessage('can only add');

        $this->asCompany($this->company, fn () => app(StockAdjustments::class)->request([
            'warehouse_id' => $this->store->id, 'item_id' => $this->item->id, 'qty' => -1,
            'reason_code_id' => $this->reason('FOUND')->id,
        ], $this->manager));
    }

    public function test_more_than_is_there_cannot_be_adjusted_out(): void
    {
        $this->receive(5);

        $this->expectExceptionMessage('cannot be removed');

        $this->asCompany($this->company, fn () => app(StockAdjustments::class)->request([
            'warehouse_id' => $this->store->id, 'item_id' => $this->item->id, 'qty' => -6,
            'reason_code_id' => $this->reason('COUNT')->id,
        ], $this->manager));
    }

    public function test_a_retried_posting_with_the_same_key_moves_stock_once(): void
    {
        $a = $this->receive(4, ['idempotency_key' => 'grn-77-line-1']);
        $b = $this->receive(4, ['idempotency_key' => 'grn-77-line-1']);

        $this->assertSame($a->id, $b->id);
        $this->assertEquals(4, $this->item->fresh()->qty_on_hand);
    }

    // ── WMS-025: history ─────────────────────────────────────────────────────────────────────

    public function test_the_history_runs_to_the_balance_and_points_back_to_its_documents(): void
    {
        $this->receive(20);
        $adj = $this->asCompany($this->company, fn () => app(StockAdjustments::class)->request([
            'warehouse_id' => $this->store->id, 'item_id' => $this->item->id, 'qty' => -2,
            'reason_code_id' => $this->reason('DAMAGED')->id,
        ], $this->manager));
        // A second delivery that turns out to be the wrong item, corrected by reversal.
        $wrong = $this->receive(5);
        $this->asCompany($this->company, fn () => $this->ledger()->reverse($wrong->fresh(), 'Wrong item delivered'));

        $this->actingAs($this->manager);
        $page = $this->asCompany($this->company, fn () => app(StockHistory::class)->movements(['item_id' => $this->item->id]));
        $rows = collect($page->items());

        $this->assertEquals(18, $rows->first()['running_balance'], 'The newest row does not end on the balance.');
        $this->assertEquals((float) DB::table('fin_stock_balances')->where('item_id', $this->item->id)->sum('qty'), $rows->first()['running_balance']);
        $adjRow = $rows->firstWhere('reason_code', 'DAMAGED');
        $this->assertSame($adj->number, $adjRow['source']['label']);
        $this->assertStringContainsString('/warehouse/stock/adjustments/', (string) $adjRow['source']['url']);
        $this->assertNotNull($rows->firstWhere('id', $wrong->uuid)['reversed_by']);
        $this->assertNotNull($rows->firstWhere('type', 'reversal')['reversal_of']);

        $recon = app(StockHistory::class)->reconcile($this->item->id);
        $this->assertEquals(0, $recon[0]['difference']);
    }

    public function test_every_inventory_screen_renders(): void
    {
        $this->receive(8);
        $this->actingAs($this->manager);

        $this->get(route('warehouse.stock.index'))->assertOk()->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Stock/Index')->where('items.data.0.physical', 8));
        $this->get(route('warehouse.stock.show', $this->item->uuid))->assertOk()->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Stock/Show')->where('availability.totals.available_to_promise', 8));
        $this->get(route('warehouse.stock.movements', ['item' => $this->item->uuid]))->assertOk()->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Stock/Movements')->has('movements.data', 1));
        $this->get(route('warehouse.stock.states'))->assertOk()->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Stock/States'));
        $this->get(route('warehouse.stock.adjustments.index'))->assertOk()->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Stock/Adjustments')->has('reasons', count(ReasonCode::DEFAULTS)));
        $this->get(route('warehouse.stock.reasons.index'))->assertOk()->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Stock/ReasonCodes'));
        $this->get(route('warehouse.stock.clients.index'))->assertOk()->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Stock/Clients'));
        $this->get(route('warehouse.hub.inventory'))->assertOk();

        foreach (['Index', 'Show', 'Movements', 'States', 'Adjustments', 'ReasonCodes', 'Clients'] as $page) {
            $this->assertFileExists(resource_path("js/Modules/Warehouse/Stock/{$page}.vue"));
        }
    }

    public function test_raising_and_approving_adjustments_are_separate_permissions(): void
    {
        $this->receive(5);
        $this->actingAs($this->viewer)->post(route('warehouse.stock.adjustments.store'), [
            'item' => $this->item->uuid, 'warehouse' => $this->store->uuid, 'stock_status' => 'AVAILABLE', 'qty' => -1,
            'reason' => $this->reason('COUNT')->uuid,
        ])->assertForbidden();

        $clerk = $this->person('clerk', [RoleCatalog::VIEW, RoleCatalog::ADJUST_STOCK]);
        $this->actingAs($clerk)->post(route('warehouse.stock.adjustments.store'), [
            'item' => $this->item->uuid, 'warehouse' => $this->store->uuid, 'stock_status' => 'AVAILABLE', 'qty' => -1,
            'reason' => $this->reason('THEFT')->uuid,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $adj = StockAdjustment::withoutGlobalScopes()->latest('id')->firstOrFail();

        $other = $this->person('clerk2', [RoleCatalog::VIEW, RoleCatalog::ADJUST_STOCK]);
        $this->actingAs($other)->post(route('warehouse.stock.adjustments.decide', $adj->uuid), ['decision' => 'approve'])->assertForbidden();
    }
}
