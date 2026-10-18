<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\Manufacturing\Models\Integration;
use App\Modules\Operations\Manufacturing\Models\IntegrationMapping;
use App\Modules\Operations\Manufacturing\Services\IntegrationHub;
use App\Modules\Operations\WarehouseManagement\Models\Carrier;
use App\Modules\Operations\WarehouseManagement\Models\FulfilmentOrder;
use App\Modules\Operations\WarehouseManagement\Models\ItemPackaging;
use App\Modules\Operations\WarehouseManagement\Models\Package;
use App\Modules\Operations\WarehouseManagement\Models\PickTask;
use App\Modules\Operations\WarehouseManagement\Models\Shipment;
use App\Modules\Operations\WarehouseManagement\Services\Allocator;
use App\Modules\Operations\WarehouseManagement\Services\EpcisMapping;
use App\Modules\Operations\WarehouseManagement\Services\FulfilmentOrders;
use App\Modules\Operations\WarehouseManagement\Services\ItemCatalogue;
use App\Modules\Operations\WarehouseManagement\Services\LocationService;
use App\Modules\Operations\WarehouseManagement\Services\Packing;
use App\Modules\Operations\WarehouseManagement\Services\Picking;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseErp;
use App\Modules\Operations\WarehouseManagement\Services\WebhookDeliveries;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/**
 * WMS-170..173: carriers, events and webhooks, the versioned API, ERP messages through
 * Manufacturing's integration hub, and the EPCIS mapping.
 */
class IntegrationsTest extends WarehouseTestCase
{
    private FinWarehouse $wh;

    private FinStockItem $item;

    private FinStockBin $a1;

    /** The status the fake webhook receiver answers with; one Http::fake for the whole test. */
    private int $receiverStatus = 200;

    /** @var list<HttpRequest> */
    private array $received = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();
        $this->wh = $this->warehouse('DC', ['is_default' => true, 'is_wms_managed' => true, 'policies' => ['scan_to_confirm' => false]]);
        $this->asCompany($this->company, function () {
            app(ItemCatalogue::class)->ensureUnits($this->company->id);
            $loc = app(LocationService::class);
            $zone = $loc->create($this->wh, ['code' => 'Z1', 'level' => 'ZONE']);
            $this->a1 = $loc->create($this->wh, ['code' => 'Z1-A1', 'parent_id' => $zone->id, 'pick_sequence' => 1]);
            $this->item = app(ItemCatalogue::class)->create(['company_id' => $this->company->id, 'code' => 'JAM', 'name' => 'Apricot jam', 'uom' => 'ea', 'standard_cost' => 20]);
        });
        Http::fake(function (HttpRequest $r) {
            $this->received[] = $r;

            return Http::response('', $this->receiverStatus);
        });
    }

    private function receive(float $qty, ?FinWarehouse $wh = null): void
    {
        $this->asCompany($this->company, fn () => app(StockLedger::class)->receive($this->item->fresh(), $qty, 20, $wh ?? $this->wh,
            ['bin_id' => $wh ? null : $this->a1->id, 'reference' => 'GRN-1']));
    }

    private function packedOrder(float $qty, string $ref = 'WEB-1'): array
    {
        return $this->asCompany($this->company, function () use ($qty, $ref) {
            [$o] = app(FulfilmentOrders::class)->create($this->wh, 'ECOMMERCE', $ref, [['item_id' => $this->item->id, 'qty' => $qty]],
                ['customer_name' => 'Shop', 'ship_to' => ['zone' => 'Gauteng']], $this->manager);
            $o = app(Allocator::class)->allocate(app(FulfilmentOrders::class)->release($o));
            app(Picking::class)->release($this->wh, collect([$o->fresh()]), 'DISCRETE', $this->manager);
            foreach (PickTask::withoutGlobalScopes()->where('order_id', $o->id)->where('status', 'OPEN')->get() as $t) {
                app(Picking::class)->confirm($t, (float) $t->qty, $this->manager);
            }
            $p = app(Packing::class)->open($o->fresh(), 'CARTON', $this->manager);
            foreach ($o->fresh()->lines as $l) {
                app(Packing::class)->add($p, $l, (float) $l->qty_picked);
            }

            return [$o->fresh(), app(Packing::class)->close($p->fresh(), ['weight_kg' => 4.2])];
        });
    }

    private function outbox(string $type): \Illuminate\Support\Collection
    {
        return DB::table('wms_outbox')->where('company_id', $this->company->id)->where('event_type', $type)->get();
    }

    // ── WMS-172 events ───────────────────────────────────────────────────────────────────────

    public function test_events_are_written_with_the_work_only_for_managed_warehouses_and_never_rewritten(): void
    {
        $this->receive(10);
        $receipt = $this->outbox('warehouse.receipt.posted')->sole();
        $this->assertSame(1, (int) $receipt->schema_version);
        $this->assertSame('JAM', json_decode($receipt->payload, true)['item']);
        $this->assertCount(1, $this->outbox('warehouse.stock.moved'));

        $plain = $this->warehouse('SHOP');
        $this->receive(3, $plain);
        $this->assertCount(1, $this->outbox('warehouse.receipt.posted'), 'a warehouse the module does not run publishes nothing');

        // Rolled-back work leaves no event.
        try {
            DB::transaction(function () {
                $this->receive(1);
                throw new \RuntimeException('undo');
            });
        } catch (\RuntimeException) {
        }
        $this->assertCount(1, $this->outbox('warehouse.receipt.posted'));

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::transaction(fn () => DB::table('wms_outbox')->where('id', $receipt->id)->update(['payload' => json_encode(['item' => 'FORGED'])]));
    }

    public function test_webhooks_are_signed_retried_dead_lettered_and_replayed(): void
    {
        $d = app(WebhookDeliveries::class);
        foreach (['http://203.0.113.10/hook' => 'https://', 'https://10.0.0.5/hook' => 'private', 'https://127.0.0.1/hook' => 'private'] as $url => $why) {
            try {
                $d->subscribe($this->company->id, ['name' => 'x', 'url' => $url, 'event_types' => ['warehouse.receipt.posted']], $this->manager);
                $this->fail("{$url} should be refused");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($why, $e->getMessage());
            }
        }
        [$hook, $secret] = $this->asCompany($this->company, fn () => $d->subscribe($this->company->id, ['name' => 'ERP', 'url' => 'https://203.0.113.10/hook',
            'event_types' => ['warehouse.receipt.posted']], $this->manager));
        $this->receive(5);

        $this->receiverStatus = 200;
        $this->assertSame(1, $d->drain()['delivered']);
        $req = collect($this->received)->last();
        $body = $req->body();
        $this->assertSame('sha256='.hash_hmac('sha256', $req->header('X-Mkhaya-Timestamp')[0].'.'.$body, $secret), $req->header('X-Mkhaya-Signature')[0]);
        $this->assertSame('warehouse.receipt.posted', json_decode($body, true)['type']);
        $this->assertSame(json_decode($body, true)['event_id'], $req->header('X-Mkhaya-Event-Id')[0]);
        // Events nobody subscribed to are marked done without a delivery.
        $this->assertSame(0, DB::table('wms_outbox')->where('company_id', $this->company->id)->whereNull('published_at')->count());

        // A receiver that keeps failing: retried with back-off, then held as a dead letter.
        $this->receive(1);
        $this->receiverStatus = 500;
        $event = $this->outbox('warehouse.receipt.posted')->last();
        for ($i = 0; $i < WebhookDeliveries::MAX_ATTEMPTS; $i++) {
            DB::table('wms_outbox')->where('id', $event->id)->update(['available_at' => now()->subSecond()]);
            $d->drain();
        }
        $dead = DB::table('wms_outbox')->where('id', $event->id)->first();
        $this->assertNotNull($dead->failed_at);
        $this->assertStringContainsString('HTTP 500', $dead->last_error);
        $this->assertSame(WebhookDeliveries::MAX_ATTEMPTS, DB::table('wms_webhook_deliveries')->where('outbox_id', $event->id)->count());

        $this->receiverStatus = 200;
        $d->replay($dead->event_id);
        $this->assertSame(1, $d->drain()['delivered']);
        $this->assertSame(0, $hook->fresh()->consecutive_failures);
    }

    public function test_the_api_feed_types_webhooks_idempotency_and_warehouse_scoped_tokens(): void
    {
        $this->receive(4);
        app(CurrentCompany::class)->set($this->company->id);
        Sanctum::actingAs($this->manager, ['wms:read']);
        $feed = $this->getJson('/api/v1/warehouse/events?after=0')->assertOk();
        $this->assertSame('warehouse.receipt.posted', collect($feed->json('data'))->firstWhere('type', 'warehouse.receipt.posted')['type']);
        $this->getJson('/api/v1/warehouse/events?after='.$feed->json('next_after'))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/warehouse/event-types')->assertOk()->assertJsonFragment(['type' => 'warehouse.shipment.dispatched', 'schema_version' => 1]);
        $this->getJson('/api/v1/warehouse/movements?warehouse=DC')->assertOk()->assertJsonPath('data.0.item', 'JAM');
        $this->getJson('/api/v1/warehouse/movements?warehouse=NOPE')->assertStatus(422);
        $this->postJson('/api/v1/warehouse/webhooks', [])->assertForbidden();

        // A real token: abilities limiting it to another warehouse.
        $plain = $this->manager->createToken('scoped', ['wms:read', 'wms:warehouse:OTHER'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->getJson('/api/v1/warehouse/movements?warehouse=DC')->assertForbidden();
        $this->withToken($plain)->getJson('/api/v1/warehouse/events?after=0')->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($plain)->getJson('/api/v1/warehouse/epcis/events?from=2026-01-01&to=2027-01-01')->assertStatus(422);
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        Sanctum::actingAs($this->manager, ['wms:webhooks']);
        $body = ['name' => 'Shop', 'url' => 'https://203.0.113.10/in', 'event_types' => ['warehouse.shipment.dispatched']];
        $first = $this->withHeader('Idempotency-Key', 'hook-create-0001')->postJson('/api/v1/warehouse/webhooks', $body)->assertCreated();
        $this->assertStringStartsWith('whsec_', $first->json('secret'));
        $again = $this->withHeader('Idempotency-Key', 'hook-create-0001')->postJson('/api/v1/warehouse/webhooks', $body)->assertCreated();
        $again->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame($first->json('id'), $again->json('id'));
        $this->assertSame(1, DB::table('wms_webhooks')->where('company_id', $this->company->id)->count());
        $this->withHeader('Idempotency-Key', 'hook-create-0001')->postJson('/api/v1/warehouse/webhooks', array_merge($body, ['name' => 'Other']))->assertStatus(422);
        $this->flushHeaders();
        $this->postJson('/api/v1/warehouse/webhooks', ['name' => 'x', 'url' => 'https://192.168.1.4/x', 'event_types' => ['warehouse.receipt.posted']])->assertStatus(422);
        $this->get('/api/v1/warehouse/openapi.yaml')->assertOk();
    }

    // ── WMS-170 carriers ─────────────────────────────────────────────────────────────────────

    public function test_a_carrier_is_quoted_booked_at_dispatch_tracked_and_delivers(): void
    {
        $this->receive(10);
        $this->manager->givePermissionTo(RoleCatalog::all());
        $this->actingAs($this->manager)->post(route('warehouse.integrations.carriers.store'), ['code' => 'RAM', 'name' => 'RAM Couriers', 'driver' => 'MANUAL',
            'tracking_url' => 'https://track.example/{tracking}', 'is_active' => true, 'rates' => [
                ['service' => 'Economy', 'zone' => null, 'max_weight_kg' => 5, 'price' => 95, 'transit_days' => 3],
                ['service' => 'Economy', 'zone' => null, 'max_weight_kg' => 30, 'price' => 180, 'transit_days' => 3],
                ['service' => 'Overnight', 'zone' => 'Gauteng', 'max_weight_kg' => 10, 'price' => 160, 'transit_days' => 1],
                ['service' => 'Overnight', 'zone' => 'Cape', 'max_weight_kg' => 10, 'price' => 260, 'transit_days' => 1],
            ]])->assertSessionHasNoErrors();
        $carrier = Carrier::withoutGlobalScopes()->where('company_id', $this->company->id)->sole();
        [$o, $pkg] = $this->packedOrder(2);

        $page = $this->get(route('warehouse.outbound.orders.show', $o->uuid))->assertOk();
        $options = collect($page->viewData('page')['props']['carrier_quote']['options']);
        $this->assertSame([['Economy', 95.0], ['Overnight', 160.0]], $options->map(fn ($x) => [$x['service'], $x['price']])->all(), 'the narrowest band, and only the zone the order goes to');

        $this->post(route('warehouse.outbound.orders.dispatch', $o->uuid), ['packages' => [$pkg->uuid], 'handover_name' => 'Driver Dube',
            'carrier_id' => $carrier->uuid, 'carrier_service' => 'Overnight', 'tracking_reference' => 'RAM123'])->assertSessionHasNoErrors();
        $s = Shipment::withoutGlobalScopes()->where('order_id', $o->id)->sole();
        $this->assertSame('RAM Couriers', $s->carrier);
        $this->assertSame('RAM123', $s->booking_reference);
        $this->assertSame(160.0, (float) $s->quoted_price);
        $this->assertSame('BOOKED', $s->tracking_status);
        $this->assertSame([$pkg->sscc], json_decode($this->outbox('warehouse.shipment.dispatched')->sole()->payload, true)['packages']);

        $this->post(route('warehouse.outbound.shipments.tracking', $s->uuid), ['status' => 'IN_TRANSIT', 'location' => 'Midrand hub'])->assertSessionHasNoErrors();
        $this->assertSame('IN_TRANSIT', $s->fresh()->tracking_status);

        app(CurrentCompany::class)->set($this->company->id);
        Sanctum::actingAs($this->manager, ['wms:tracking', 'wms:read']);
        $delivered = ['status' => 'DELIVERED', 'occurred_at' => now()->toIso8601String(), 'external_id' => 'RAM-EV-9', 'received_by' => 'Reception'];
        $this->postJson("/api/v1/warehouse/shipments/{$s->number}/tracking", $delivered)->assertCreated()->assertJsonPath('status', 'DELIVERED');
        $this->postJson("/api/v1/warehouse/shipments/{$s->number}/tracking", $delivered)->assertOk();
        $this->assertSame(3, DB::table('wms_tracking_events')->where('shipment_id', $s->id)->count());
        $this->assertSame('Reception', $s->fresh()->pod_received_by);
        $this->assertCount(1, $this->outbox('warehouse.shipment.delivered'));
        $this->assertSame('DELIVERED', FulfilmentOrder::withoutGlobalScopes()->find($o->id)->status);
    }

    // ── WMS-171 ERP ──────────────────────────────────────────────────────────────────────────

    public function test_erp_orders_and_stock_levels_go_through_the_integration_hub_and_events_are_exported_once(): void
    {
        $this->receive(10);
        $i = $this->asCompany($this->company, fn () => Integration::create(['company_id' => $this->company->id, 'name' => 'Sage', 'connector' => 'SAGE_200', 'method' => 'API',
            'auth_type' => 'API_KEY', 'settings' => ['export' => ['WMS_RECEIPTS']], 'status' => 'ACTIVE', 'validation_notes' => 'Edition and licence checked with the customer.',
            'validated_at' => now()->subDay(), 'created_by' => $this->manager->id]));
        IntegrationMapping::create(['company_id' => $this->company->id, 'integration_id' => $i->id, 'entity' => 'STOCK_ITEM', 'local_id' => $this->item->id, 'external_ref' => 'SG-JAM']);
        $hub = app(IntegrationHub::class);

        $e = $this->asCompany($this->company, fn () => $hub->ingest($i, 'WMS_ORDER', 'SO-9', ['warehouse' => 'DC', 'order_ref' => 'SO-9', 'lines' => [['product' => 'SG-JAM', 'qty' => 2]]]));
        $this->assertSame('PROCESSED', $e->status, (string) $e->last_error);
        $this->assertSame(1, DB::table('wms_orders')->where('company_id', $this->company->id)->where('external_ref', 'SO-9')->count());
        $this->assertSame(1, $hub->ingest($i, 'WMS_ORDER', 'SO-9', [])->duplicates, 'a second delivery is counted, not redone');

        $bad = $this->asCompany($this->company, fn () => $hub->ingest($i, 'WMS_ORDER', 'SO-10', ['warehouse' => 'DC', 'lines' => [['product' => 'UNKNOWN', 'qty' => 1]]]));
        $this->assertSame('FAILED', $bad->status);
        $this->assertStringContainsString('UNKNOWN', $bad->last_error);
        $this->assertNotNull($bad->next_attempt_at);

        $r = $this->asCompany($this->company, fn () => $hub->ingest($i, 'WMS_STOCK_LEVELS', 'SL-1', ['warehouse' => 'DC', 'lines' => [['product' => 'SG-JAM', 'qty' => 7], ['product' => 'ZZZ', 'qty' => 1]]]));
        $this->assertSame('PROCESSED', $r->status, (string) $r->last_error);
        $rec = DB::table('wms_erp_reconciliations')->where('integration_id', $i->id)->sole();
        $this->assertSame([1, 1, 1], [(int) $rec->compared, (int) $rec->differences, (int) $rec->unmapped]);
        $this->assertEquals(3, collect(json_decode($rec->lines, true))->firstWhere('product', 'SG-JAM')['difference']);

        $queued = app(WarehouseErp::class)->queue($i, 'WMS_RECEIPTS');
        $this->assertSame(1, $queued);
        $this->assertSame(0, app(WarehouseErp::class)->queue($i, 'WMS_RECEIPTS'), 'each event goes once');
        $out = DB::table('mfg_integration_events')->where('integration_id', $i->id)->where('event_type', 'WMS_RECEIPT')->sole();
        $this->assertSame('SG-JAM', json_decode($out->payload, true)['data']['product'], 'their reference, not ours');
    }

    // ── WMS-173 EPCIS ────────────────────────────────────────────────────────────────────────

    public function test_epcis_uses_gs1_keys_where_held_lists_the_gaps_and_claims_no_conformance(): void
    {
        $this->receive(10);
        $doc = $this->asCompany($this->company, fn () => app(EpcisMapping::class)->document(now()->subDay(), now()->addDay()));
        $this->assertContains('JAM', $doc['mkhaya:coverage']['items_without_gtin']);
        $this->assertContains('DC', $doc['mkhaya:coverage']['warehouses_without_gln']);
        $this->assertStringContainsString('Not validated', $doc['mkhaya:conformance']);

        $this->asCompany($this->company, function () {
            ItemPackaging::create(['company_id' => $this->company->id, 'stock_item_id' => $this->item->id, 'level' => 'EACH', 'uom_id' => app(ItemCatalogue::class)->baseUom($this->item)->id,
                'qty_in_base' => 1, 'gtin' => '4006381333931', 'is_active' => true]);
            $this->wh->update(['policies' => array_merge($this->wh->policies ?? [], ['gln' => '6009876543219'])]);
        });
        [$o, $pkg] = $this->packedOrder(2);
        $this->asCompany($this->company, fn () => app(\App\Modules\Operations\WarehouseManagement\Services\Dispatching::class)
            ->dispatch($o, collect([$pkg->fresh('contents')]), ['handover_name' => 'Driver'], $this->manager));

        $doc = $this->asCompany($this->company, fn () => app(EpcisMapping::class)->document(now()->subDay(), now()->addDay()));
        $events = collect($doc['epcisBody']['eventList']);
        $receipt = $events->firstWhere('bizStep', 'receiving');
        $this->assertSame('ObjectEvent', $receipt['type']);
        $this->assertSame('https://id.gs1.org/01/04006381333931', $receipt['quantityList'][0]['epcClass']);
        $this->assertSame('https://id.gs1.org/414/6009876543219', $receipt['readPoint']['id']);
        $this->assertSame('https://id.gs1.org/00/'.$pkg->sscc, $events->firstWhere('type', 'AggregationEvent')['parentID']);
        $this->assertNotNull($events->firstWhere('bizStep', 'shipping'));
        $this->assertSame([], $doc['mkhaya:coverage']['items_without_gtin']);
        $this->assertSame([], $doc['mkhaya:coverage']['warehouses_without_gln']);
    }

    // ── Screens ──────────────────────────────────────────────────────────────────────────────

    public function test_the_screens_render_and_changes_need_the_integration_permission(): void
    {
        foreach (['carriers', 'events', 'systems', 'standards'] as $page) {
            $this->actingAs($this->viewer)->get(route("warehouse.integrations.{$page}"))->assertOk();
            $this->actingAs($this->outsider)->get(route("warehouse.integrations.{$page}"))->assertForbidden();
        }
        $this->actingAs($this->viewer)->post(route('warehouse.integrations.carriers.store'), ['code' => 'X', 'name' => 'X', 'driver' => 'MANUAL'])->assertForbidden();
        $this->actingAs($this->viewer)->post(route('warehouse.integrations.webhooks.store'), [])->assertForbidden();
        $this->actingAs($this->viewer)->post(route('warehouse.integrations.systems.store'), [])->assertForbidden();

        $this->actingAs($this->manager)->post(route('warehouse.integrations.systems.store'), ['name' => 'Cin7', 'connector' => 'CIN7_CORE'])->assertRedirect();
        $this->actingAs($this->manager)->post(route('warehouse.integrations.standards.gln', $this->wh->uuid), ['gln' => '6009876543210'])->assertSessionHasErrors('refused');
        $this->actingAs($this->manager)->get(route('warehouse.integrations.standards.epcis', ['from' => now()->subDay()->toDateString(), 'to' => now()->toDateString()]))->assertOk();
    }
}
