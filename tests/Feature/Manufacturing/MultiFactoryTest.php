<?php

namespace Tests\Feature\Manufacturing;

use App\Core\Role\Models\Role;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockMovement;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\FactoryAssignment;
use App\Modules\Operations\Manufacturing\Models\FactoryPolicy;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\Transfer;
use App\Modules\Operations\Manufacturing\Services\FactoryAccess;
use App\Modules\Operations\Manufacturing\Services\MultiFactory;
use App\Modules\Operations\Manufacturing\Services\ProductionOrderLifecycle;
use App\Modules\Operations\Manufacturing\Services\ProductionOrders;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;

/**
 * MFG-024: products shared or confined by explicit policy; stock moved between factories through
 * request, approval at the source, shipment held in transit and receipt at the destination; one
 * factory's shortage matched to another's surplus; consolidated reporting only for people who see
 * every factory.
 */
class MultiFactoryTest extends ManufacturingTestCase
{
    private Factory $plt;

    private Factory $oth;

    private FinWarehouse $pltStore;

    private FinWarehouse $othStore;

    private FinStockItem $steel;

    private Item $frame;

    private $boss;

    private $sender;

    private $receiver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();
        $all = Role::firstOrCreate(['name' => 'mfg test group ops', 'guard_name' => 'web']);
        $all->syncPermissions(RoleCatalog::all());
        $site = Role::firstOrCreate(['name' => 'mfg test site lead', 'guard_name' => 'web']);
        $site->syncPermissions(array_values(array_diff(RoleCatalog::all(), [RoleCatalog::ALL_FACTORIES])));
        $this->boss = $this->makeUser('group@example.test', $all);
        $this->sender = $this->makeUser('plt-lead@example.test', $site);
        $this->receiver = $this->makeUser('oth-lead@example.test', $site);
        foreach ([$this->boss, $this->sender, $this->receiver] as $u) {
            $this->grantModuleTier($u);
        }

        $this->pltStore = $this->warehouse('PLTW');
        $this->othStore = $this->warehouse('OTHW');
        $this->plt = $this->factory('PLT');
        $this->oth = $this->factory('OTH');
        $this->asCompany($this->company, function () {
            $this->plt->update(['default_warehouse_id' => $this->pltStore->id]);
            $this->oth->update(['default_warehouse_id' => $this->othStore->id]);
            FactoryAssignment::create(['company_id' => $this->company->id, 'factory_id' => $this->plt->id, 'user_id' => $this->sender->id, 'granted_by' => $this->boss->id]);
            FactoryAssignment::create(['company_id' => $this->company->id, 'factory_id' => $this->oth->id, 'user_id' => $this->receiver->id, 'granted_by' => $this->boss->id]);
            foreach ([$this->plt, $this->oth] as $f) {
                FactoryPolicy::for($f->id, $this->company->id)->update(['require_material_for_approval' => false, 'require_routing' => false]);
            }
        });
        app(FactoryAccess::class)->flush();

        $this->steel = $this->stockItem('STEEL', 'kg', 10);
        $this->frame = $this->asCompany($this->company, fn () => Item::create(['company_id' => $this->company->id,
            'stock_item_id' => $this->stockItem('FRAME', 'ea', 0)->id, 'kind' => 'FINISHED', 'default_uom_id' => $this->uom('ea')->id, 'is_active' => true]));
        $this->approvedBom($this->frame, [[$this->steel, 2, 0, 'kg']]);
        $this->asCompany($this->company, fn () => app(StockLedger::class)->receive($this->steel->fresh(), 100, 10, $this->pltStore, ['reference' => 'GRN']));
    }

    private function order(Factory $f, FinWarehouse $w)
    {
        return $this->asCompany($this->company, function () use ($f, $w) {
            $o = app(ProductionOrders::class)->create(['item' => $this->frame, 'quantity' => 10, 'due_date' => now()->addDays(4)->toDateString(),
                'factory' => $f, 'warehouse' => $w], $this->boss);
            app(ProductionOrderLifecycle::class)->plan($o, $this->boss);

            return $o->fresh();
        });
    }

    public function test_a_product_confined_to_some_factories_is_made_only_there(): void
    {
        $this->actingAs($this->boss)->post(route('manufacturing.factories.network.products', $this->frame->uuid), ['scope' => 'LISTED', 'factories' => [$this->plt->id]])
            ->assertSessionHas('success');
        try {
            $this->order($this->oth, $this->othStore);
            $this->fail('The product was made at a factory its policy does not list.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('not made at OTH', $e->getMessage());
        }
        $this->assertNotNull($this->order($this->plt, $this->pltStore));

        $this->actingAs($this->sender)->post(route('manufacturing.factories.network.products', $this->frame->uuid), ['scope' => 'LISTED', 'factories' => []])
            ->assertSessionHas('error');
        $page = $this->actingAs($this->boss)->get(route('manufacturing.factories.network'))->assertOk()->viewData('page');
        $this->assertSame([$this->plt->id], $page['props']['restricted'][0]['factories']);
    }

    public function test_stock_moves_between_factories_held_in_transit_and_released_on_receipt(): void
    {
        $this->order($this->oth, $this->othStore);   // OTH needs 20 kg and has none
        $balances = $this->asCompany($this->company, fn () => app(MultiFactory::class)->balances());
        $this->assertSame(['item' => 'STEEL', 'stock_item_id' => $this->steel->id, 'from' => 'PLT', 'from_id' => $this->plt->id,
            'to' => 'OTH', 'to_id' => $this->oth->id, 'qty' => 20.0], $balances['suggestions'][0]);

        $this->actingAs($this->receiver)->post(route('manufacturing.factories.transfers.store'), [
            'from_factory_id' => $this->plt->id, 'to_factory_id' => $this->oth->id, 'lines' => [['stock_item_id' => $this->steel->id, 'qty' => 20]],
        ])->assertRedirect();
        $t = $this->asCompany($this->company, fn () => Transfer::query()->firstOrFail());
        $this->assertStringStartsWith('IFT-', $t->number);

        $this->actingAs($this->receiver)->post(route('manufacturing.factories.transfers.act', [$t->uuid, 'approve']))->assertSessionHas('error');
        $this->actingAs($this->sender)->post(route('manufacturing.factories.transfers.act', [$t->uuid, 'approve']))->assertSessionHas('success');
        $this->actingAs($this->sender)->post(route('manufacturing.factories.transfers.act', [$t->uuid, 'ship']))->assertSessionHas('success');

        $steel = $this->steel->fresh();
        $this->assertEquals(80.0, $this->asCompany($this->company, fn () => app(StockLedger::class)->available($steel, $this->pltStore->id)));
        $this->assertEquals(0.0, $this->asCompany($this->company, fn () => app(StockLedger::class)->available($steel, $this->othStore->id)), 'In transit is not usable.');
        $moved = FinStockMovement::withoutGlobalScopes()->where('source_type', MultiFactory::SOURCE_TYPE)->where('source_id', $t->id)->get();
        $this->assertTrue($moved->isNotEmpty());
        $this->assertTrue($moved->every(fn ($m) => $m->journal_id === null), 'Warehouse to warehouse: no journal.');

        $line = $t->lines()->first();
        $this->actingAs($this->receiver)->post(route('manufacturing.factories.transfers.act', [$t->uuid, 'receive']), ['qty' => [$line->id => 18]])->assertSessionHas('error');
        $this->actingAs($this->receiver)->post(route('manufacturing.factories.transfers.act', [$t->uuid, 'receive']), ['qty' => [$line->id => 18], 'discrepancy' => 'Two kilograms damaged in transit'])
            ->assertSessionHas('success');
        $this->assertEquals(18.0, $this->asCompany($this->company, fn () => app(StockLedger::class)->available($steel, $this->othStore->id)));
        $held = (float) FinStockPosition::withoutGlobalScopes()->where('item_id', $steel->id)->where('warehouse_id', $this->othStore->id)->where('status', 'ON_HOLD')->sum('qty');
        $this->assertEquals(2.0, $held, 'The shortfall stays held for investigation.');
        $this->assertStringContainsString('STEEL 2', $t->fresh()->discrepancy);
        $this->assertEquals(100.0, (float) $steel->fresh()->qty_on_hand, 'Nothing left the company.');
    }

    public function test_people_see_only_their_factories_and_the_consolidated_view_is_for_the_group(): void
    {
        $t = $this->asCompany($this->company, fn () => app(MultiFactory::class)->request(['from' => $this->plt, 'to' => $this->oth,
            'lines' => [['stock_item_id' => $this->steel->id, 'qty' => 5]]], $this->boss));
        $third = $this->factory('THD');
        $this->asCompany($this->company, fn () => $third->update(['default_warehouse_id' => $this->warehouse('THDW')->id]));
        $private = $this->asCompany($this->company, fn () => app(MultiFactory::class)->request(['from' => $this->plt, 'to' => $third,
            'lines' => [['stock_item_id' => $this->steel->id, 'qty' => 5]]], $this->boss));

        $this->actingAs($this->receiver)->get(route('manufacturing.factories.transfers.show', $t->uuid))->assertOk();
        $this->actingAs($this->receiver)->get(route('manufacturing.factories.transfers.show', $private->uuid))->assertForbidden();
        $list = $this->actingAs($this->receiver)->get(route('manufacturing.factories.transfers.index'))->assertOk()->viewData('page');
        $this->assertSame([$t->number], array_column($list['props']['transfers'], 'number'));

        $group = $this->actingAs($this->boss)->get(route('manufacturing.reports.executive'))->assertOk()->viewData('page');
        $this->assertEqualsCanonicalizing(['PLT', 'OTH', 'THD'], array_column($group['props']['data']['tables']['by_factory'], 'factory'));
        $site = $this->actingAs($this->receiver)->get(route('manufacturing.reports.executive'))->assertOk()->viewData('page');
        $this->assertSame([], $site['props']['data']['tables']['by_factory']);

        $cards = array_column($this->actingAs($this->boss)->get('/manufacturing/factories')->assertOk()->viewData('page')['props']['cards'], 'path');
        $this->assertEqualsCanonicalizing(['/manufacturing/factories/transfers', '/manufacturing/factories/network'], $cards);
    }
}
