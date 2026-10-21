<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockMovement;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\Manufacturing\Models\Item as MfgItem;
use App\Modules\Operations\Manufacturing\Models\Uom;
use App\Modules\Operations\WarehouseManagement\Models\ItemEvent;
use App\Modules\Operations\WarehouseManagement\Models\ItemProfile;
use App\Modules\Operations\WarehouseManagement\Services\BarcodeRegistry;
use App\Modules\Operations\WarehouseManagement\Services\ItemCatalogue;
use App\Modules\Operations\WarehouseManagement\Services\ItemLifecycle;
use App\Modules\Operations\WarehouseManagement\Services\UnitQuantity;
use App\Modules\Operations\WarehouseManagement\Support\Gs1Parser;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * WMS-010 to WMS-013: the product master, units and conversions, barcodes and lifecycle.
 *
 * A warehouse item is Finance's stock item: these tests create through the warehouse and then
 * read Finance's and Manufacturing's tables, because "no duplicate product record" is only true if
 * there is nowhere else for the item to be.
 */
class ProductMasterTest extends WarehouseTestCase
{
    private const EAN = '4006381333931';

    private const CARTON_GTIN = '14006381333938';

    private FinStockItem $item;

    private FinWarehouse $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();
        $this->store = $this->warehouse('MAIN');
        $this->item = $this->asCompany($this->company, function () {
            app(ItemCatalogue::class)->ensureUnits($this->company->id);

            return app(ItemCatalogue::class)->create(['company_id' => $this->company->id, 'code' => 'SOAP-1', 'name' => 'Bath soap', 'uom' => 'ea']);
        });
    }

    private function uom(string $code): Uom
    {
        return Uom::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }

    private function carton(float $qty = 12): void
    {
        $this->asCompany($this->company, fn () => app(ItemCatalogue::class)->savePackaging($this->item, [
            'level' => 'CARTON', 'uom_id' => $this->uom('ctn')->id, 'qty_in_base' => $qty, 'gtin' => self::CARTON_GTIN,
        ]));
    }

    // ── WMS-010 product master ───────────────────────────────────────────────────────────────

    public function test_an_item_created_by_the_warehouse_is_the_one_finance_and_manufacturing_see(): void
    {
        $this->actingAs($this->manager)->post(route('warehouse.products.store'), [
            'code' => 'TEA-40', 'name' => 'Rooibos 40s', 'uom' => 'ea', 'brand' => 'Freshpak',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $item = FinStockItem::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'TEA-40')->firstOrFail();
        $this->assertSame(1, FinStockItem::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'TEA-40')->count());
        $this->assertNotNull($item->inventory_account_id, 'The item was created around Finance, without its accounts.');
        $this->assertSame('TRADED', MfgItem::withoutGlobalScopes()->where('stock_item_id', $item->id)->value('kind'));
        $this->assertSame('Freshpak', ItemProfile::withoutGlobalScopes()->where('stock_item_id', $item->id)->value('brand'));
        $this->assertSame('ACTIVE', $item->status);
    }

    public function test_the_overview_saves_finance_manufacturing_and_warehouse_fields_together(): void
    {
        $this->actingAs($this->manager)->patch(route('warehouse.products.update', $this->item->uuid), [
            'name' => 'Bath soap 100g', 'reorder_level' => 50, 'safety_stock' => 20, 'lead_time_days' => 7,
            'weight_kg' => 0.11, 'dimensions' => ['length' => 9, 'width' => 6, 'height' => 3], 'shelf_life_days' => 730,
            'min_remaining_shelf_life_days' => 180, 'is_lot_tracked' => true, 'temperature_min_c' => 5, 'temperature_max_c' => 30,
        ])->assertSessionHasNoErrors();

        $this->assertSame('Bath soap 100g', $this->item->fresh()->name);
        $this->assertEquals(50, $this->item->fresh()->reorder_level);
        $mfg = MfgItem::withoutGlobalScopes()->where('stock_item_id', $this->item->id)->first();
        $this->assertEquals(0.11, $mfg->weight_kg);
        $this->assertTrue($mfg->is_lot_tracked);
        $this->assertEquals(180, ItemProfile::withoutGlobalScopes()->where('stock_item_id', $this->item->id)->value('min_remaining_shelf_life_days'));
        $this->assertTrue(ItemEvent::withoutGlobalScopes()->where('stock_item_id', $this->item->id)->where('action', 'UPDATED')->exists());
    }

    public function test_lot_control_cannot_change_while_the_item_is_in_stock(): void
    {
        $this->asCompany($this->company, fn () => app(StockLedger::class)->receive($this->item->fresh(), 10, 5, $this->store));

        $this->actingAs($this->manager)->patch(route('warehouse.products.update', $this->item->uuid), ['is_lot_tracked' => true])
            ->assertSessionHasErrors('refused');
        $this->assertFalse((bool) MfgItem::withoutGlobalScopes()->where('stock_item_id', $this->item->id)->value('is_lot_tracked'));
    }

    // ── WMS-011 units and conversions ────────────────────────────────────────────────────────

    public function test_a_packaging_level_is_the_conversion_every_transaction_uses(): void
    {
        $this->carton(12);

        $n = $this->asCompany($this->company, fn () => app(UnitQuantity::class)->normalise($this->item->fresh(), 3, $this->uom('ctn')->id));

        $this->assertEquals(36, $n['base_qty']);
        $this->assertSame(3.0, $n['txn_qty']);
    }

    public function test_a_unit_with_no_conversion_is_refused_rather_than_treated_as_one(): void
    {
        $this->expectExceptionMessage('no conversion from case');

        $this->asCompany($this->company, fn () => app(UnitQuantity::class)->normalise($this->item->fresh(), 3, $this->uom('case')->id));
    }

    public function test_a_packaging_level_cannot_contradict_a_fixed_unit(): void
    {
        $kgItem = $this->asCompany($this->company, fn () => app(ItemCatalogue::class)->create(['company_id' => $this->company->id, 'code' => 'FLOUR', 'name' => 'Flour', 'uom' => 'kg']));

        $this->expectExceptionMessage('One g is always 0.001 kg');

        $this->asCompany($this->company, fn () => app(ItemCatalogue::class)->savePackaging($kgItem, [
            'level' => 'EACH', 'uom_id' => $this->uom('g')->id, 'qty_in_base' => 2,
        ]));
    }

    public function test_a_movement_keeps_the_unit_it_was_made_in_and_that_unit_then_cannot_be_resized(): void
    {
        $this->carton(12);
        $movement = $this->asCompany($this->company, function () {
            $n = app(UnitQuantity::class)->normalise($this->item->fresh(), 2, $this->uom('ctn')->id);

            return app(StockLedger::class)->receive($this->item->fresh(), $n['base_qty'], 5, $this->store, [
                'txn_uom_id' => $n['txn_uom_id'], 'txn_qty' => $n['txn_qty'], 'reason_code' => 'PO_RECEIPT',
            ]);
        });

        $this->assertEquals(24, $movement->qty);
        $this->assertEquals(2, $movement->txn_qty);
        $this->assertSame($this->uom('ctn')->id, $movement->txn_uom_id);

        // The record is as protected as the quantity.
        DB::statement('SAVEPOINT meta_edit');
        try {
            DB::table('fin_stock_movements')->where('id', $movement->id)->update(['txn_qty' => 3]);
            $this->fail('A posted movement\'s transaction unit was edited.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('reverse it instead', $e->getMessage());
        } finally {
            DB::statement('ROLLBACK TO SAVEPOINT meta_edit');
        }

        $this->expectExceptionMessage('Add a new packaging unit');
        $this->carton(10);
    }

    // ── WMS-012 barcodes ─────────────────────────────────────────────────────────────────────

    public function test_a_barcode_with_a_wrong_check_digit_is_refused(): void
    {
        $this->expectExceptionMessage('wrong check digit');

        $this->asCompany($this->company, fn () => app(BarcodeRegistry::class)->register($this->item, ['barcode' => '4006381333932']));
    }

    public function test_a_duplicate_barcode_needs_a_reason_and_somebody_allowed_to_approve_it(): void
    {
        $other = $this->asCompany($this->company, fn () => app(ItemCatalogue::class)->create(['company_id' => $this->company->id, 'code' => 'SOAP-2', 'name' => 'Soap, other', 'uom' => 'ea']));
        $this->asCompany($this->company, fn () => app(BarcodeRegistry::class)->register($this->item, ['barcode' => self::EAN]));

        $this->actingAs($this->manager)->post(route('warehouse.products.barcodes.store', $other->uuid), ['barcode' => self::EAN])
            ->assertSessionHasErrors('refused');

        $clerk = $this->person('clerk', [RoleCatalog::VIEW, RoleCatalog::MANAGE_ITEMS]);
        $this->actingAs($clerk)->post(route('warehouse.products.barcodes.store', $other->uuid), ['barcode' => self::EAN, 'duplicate_reason' => 'Supplier reuses EAN'])
            ->assertSessionHasErrors('refused');

        $this->actingAs($this->manager)->post(route('warehouse.products.barcodes.store', $other->uuid), ['barcode' => self::EAN, 'duplicate_reason' => 'Supplier reuses EAN'])
            ->assertSessionHasNoErrors();

        // Now the bare code is ambiguous and must be refused, not resolved to the first match.
        try {
            $this->asCompany($this->company, fn () => app(BarcodeRegistry::class)->resolve(self::EAN));
            $this->fail('An ambiguous scan resolved to an item.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('could be any of', $e->getMessage());
        }
    }

    public function test_a_duplicate_is_told_apart_by_the_supplier_on_the_delivery(): void
    {
        $supplier = DB::table('suppliers')->insertGetId(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'company_id' => $this->company->id, 'company_name' => 'Soap Co', 'created_at' => now(), 'updated_at' => now()]);
        $other = $this->asCompany($this->company, fn () => app(ItemCatalogue::class)->create(['company_id' => $this->company->id, 'code' => 'SOAP-2', 'name' => 'Soap, other', 'uom' => 'ea']));
        $this->actingAs($this->manager);
        $this->asCompany($this->company, function () use ($other, $supplier) {
            app(BarcodeRegistry::class)->register($this->item, ['barcode' => self::EAN]);
            app(BarcodeRegistry::class)->register($other, ['barcode' => self::EAN, 'supplier_id' => $supplier], 'Soap Co reuses it', true);
        });

        $r = $this->asCompany($this->company, fn () => app(BarcodeRegistry::class)->resolve(self::EAN, $supplier));

        $this->assertSame('SOAP-2', $r['item']->code);
    }

    public function test_a_gs1_carton_scan_yields_the_item_quantity_batch_serial_and_expiry(): void
    {
        $this->carton(12);

        $raw = '01'.self::CARTON_GTIN.'17270630'.'10LOT-77'."\x1D".'21SN0042';
        $r = $this->asCompany($this->company, fn () => app(BarcodeRegistry::class)->resolve($raw));

        $this->assertSame('SOAP-1', $r['item']->code);
        $this->assertSame('packaging_gtin', $r['matched_by']);
        $this->assertEquals(12, $r['qty_in_base']);
        $this->assertSame('LOT-77', $r['batch']);
        $this->assertSame('SN0042', $r['serial']);
        $this->assertSame('2027-06-30', $r['expiry']);
    }

    public function test_the_human_readable_gs1_form_and_day_zero_dates_are_understood(): void
    {
        $p = (new Gs1Parser)->parse('(01)'.self::CARTON_GTIN.'(15)260200(10)B1(3102)001250');

        $this->assertSame(self::CARTON_GTIN, $p['gtin']);
        $this->assertSame('2026-02-28', $p['best_before'], 'Day 00 means the last day of the month.');
        $this->assertEqualsWithDelta(12.5, $p['net_weight_kg'], 1e-9);
    }

    public function test_an_invalid_gs1_scan_is_refused_with_the_reason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('wrong check digit');

        (new Gs1Parser)->parse('0114006381333939'.'10X');
    }

    public function test_a_pallet_sscc_is_explained_rather_than_looked_up_as_a_product(): void
    {
        $this->expectExceptionMessage("pallet's SSCC");

        $this->asCompany($this->company, fn () => app(BarcodeRegistry::class)->resolve('(00)009501101530000038'));
    }

    public function test_internal_barcodes_are_valid_ean13_and_never_repeat(): void
    {
        $this->actingAs($this->manager);
        $a = $this->asCompany($this->company, fn () => app(BarcodeRegistry::class)->generateInternal($this->item));
        $b = $this->asCompany($this->company, fn () => app(BarcodeRegistry::class)->generateInternal($this->item));

        $this->assertMatchesRegularExpression('/^20\d{11}$/', $a->barcode);
        $this->assertTrue(Gs1Parser::checkDigitValid($a->barcode));
        $this->assertNotSame($a->barcode, $b->barcode);
        $this->assertSame('SOAP-1', $this->asCompany($this->company, fn () => app(BarcodeRegistry::class)->resolve($b->barcode))['item']->code);
    }

    public function test_a_supplier_code_and_the_sku_also_resolve(): void
    {
        $this->asCompany($this->company, fn () => app(ItemCatalogue::class)->addIdentifier($this->item, ['kind' => 'SUPPLIER', 'code' => 'SC-998']));

        $this->assertSame('identifier', $this->asCompany($this->company, fn () => app(BarcodeRegistry::class)->resolve('SC-998'))['matched_by']);
        $this->assertSame('sku', $this->asCompany($this->company, fn () => app(BarcodeRegistry::class)->resolve('SOAP-1'))['matched_by']);
    }

    // ── WMS-013 lifecycle ────────────────────────────────────────────────────────────────────

    public function test_a_discontinued_item_takes_no_new_stock_but_what_is_left_can_still_go(): void
    {
        $ledger = app(StockLedger::class);
        $this->asCompany($this->company, fn () => $ledger->receive($this->item->fresh(), 10, 5, $this->store));
        $this->asCompany($this->company, fn () => app(ItemLifecycle::class)->change($this->item->fresh(), 'DISCONTINUED', 'Range rationalised'));

        try {
            $this->asCompany($this->company, fn () => $ledger->receive($this->item->fresh(), 5, 5, $this->store));
            $this->fail('A discontinued item accepted a receipt.');
        } catch (PostingException $e) {
            $this->assertStringContainsString('discontinued', $e->getMessage());
        }

        $this->asCompany($this->company, fn () => $ledger->scrap($this->item->fresh(), 2, $this->store, ['reason' => 'Damaged', 'source_type' => 'test', 'source_id' => 1]));
        $this->assertEquals(8, $this->item->fresh()->qty_on_hand, 'The refused receipt left a balance behind.');
    }

    public function test_a_recall_quarantines_the_stock_and_nothing_can_leave_or_be_released(): void
    {
        $ledger = app(StockLedger::class);
        $this->asCompany($this->company, fn () => $ledger->receive($this->item->fresh(), 10, 5, $this->store));

        $this->actingAs($this->manager)->post(route('warehouse.products.status', $this->item->uuid), [
            'status' => 'RECALLED', 'reason' => 'Supplier recall notice RC-12', 'quarantine_stock' => true,
        ])->assertSessionHasNoErrors();

        $this->assertEquals(10, (float) FinStockPosition::withoutGlobalScopes()->where('item_id', $this->item->id)->where('status', 'QUARANTINE')->sum('qty'));
        $this->assertSame('RECALLED', $this->item->fresh()->status);

        foreach ([
            fn () => $ledger->transfer($this->item->fresh(), $this->store, $this->warehouse('OTHER'), 1, ['status' => 'QUARANTINE']),
            fn () => $ledger->changeStatus($this->item->fresh(), $this->store, 1, 'QUARANTINE', 'AVAILABLE', ['reason' => 'release']),
        ] as $attempt) {
            try {
                $this->asCompany($this->company, $attempt);
                $this->fail('A recalled item moved.');
            } catch (PostingException $e) {
                $this->assertStringContainsString('recalled', $e->getMessage());
            }
        }

        // History is kept, and a correction is still possible.
        $receipt = FinStockMovement::withoutGlobalScopes()->where('item_id', $this->item->id)->where('type', 'receipt')->firstOrFail();
        $this->assertNotNull($receipt);
        $this->assertTrue(ItemEvent::withoutGlobalScopes()->where('stock_item_id', $this->item->id)->where('action', 'STATUS')->exists());
    }

    public function test_changing_status_is_its_own_permission(): void
    {
        $clerk = $this->person('clerk', [RoleCatalog::VIEW, RoleCatalog::MANAGE_ITEMS]);

        $this->actingAs($clerk)->post(route('warehouse.products.status', $this->item->uuid), ['status' => 'BLOCKED', 'reason' => 'x'])
            ->assertForbidden();
        $this->assertSame('ACTIVE', $this->item->fresh()->status);
    }

    // ── Screens ──────────────────────────────────────────────────────────────────────────────

    public function test_every_product_screen_renders(): void
    {
        $this->carton(12);
        $this->asCompany($this->company, fn () => app(BarcodeRegistry::class)->register($this->item, ['barcode' => self::EAN]));
        $this->actingAs($this->manager);

        $this->get(route('warehouse.products.index', ['q' => self::EAN]))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Products/Index')->has('items.data', 1)->where('items.data.0.code', 'SOAP-1'));
        $this->get(route('warehouse.products.show', $this->item->uuid))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Products/Show')->has('packaging', 1)->has('barcodes', 1));
        $this->get(route('warehouse.products.scan', ['code' => '(01)'.self::CARTON_GTIN.'(10)L9']))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Products/Scan')->where('result.item.code', 'SOAP-1')->where('result.batch', 'L9'));
        $this->get(route('warehouse.products.labels', ['items' => [$this->item->uuid]]))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Modules/Warehouse/Products/Labels')->has('labels', 1)->where('labels.0.barcode', self::EAN));
        $this->get(route('warehouse.hub.inventory'))->assertOk();

        foreach (['Products/Index', 'Products/Show', 'Products/Scan', 'Products/Labels'] as $page) {
            $this->assertFileExists(resource_path("js/Modules/Warehouse/{$page}.vue"));
        }
    }
}
