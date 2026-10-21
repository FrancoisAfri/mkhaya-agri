<?php

namespace Tests\Feature\Agri;

use App\Modules\Agri\Models\CropPlan;
use App\Modules\HR\Hr\Attendance\Models\ClockDevice as FarmClockDevice;
use App\Modules\Agri\Models\FarmStore;
use App\Modules\Agri\Models\GradingStandard;
use App\Modules\Agri\Models\HarvestRecord;
use App\Modules\Agri\Models\PackhouseOperation;
use App\Modules\Agri\Models\ProduceLot;
use App\Modules\Agri\Models\StorageReading;
use App\Modules\Agri\Services\FarmInventory;
use App\Modules\Agri\Services\PostHarvest;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder;
use App\Modules\Operations\Manufacturing\Models\Recall;
use App\Modules\Operations\WarehouseManagement\Models\FulfilmentOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Phase 2 §8: harvest intake into a store; grading and packing with lot genealogy, packaging and
 * quality checks; storage conditions with corrective actions and sensor readings; and the section's
 * acceptance test — from a customer delivery back to the field and harvest lot, and from a field lot
 * forward to every packed lot and destination — with dispatch, delivery and a recall on the way.
 *
 * The packhouse runs on the host modules: packing is a Manufacturing production run, a load is a
 * Warehouse fulfilment order and shipment, a return is a Warehouse customer return, and a recall is
 * a Manufacturing recall. These tests drive them from the farm's screens and check the host records.
 */
class PackhouseTest extends AgriTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->catalogue();
        $this->openBooks();
    }

    private function planted($farm, $field, string $code = 'AP-1'): CropPlan
    {
        $this->actingAs($this->manager)->post(route('agri.crops.plans.store'), ['farm_id' => $farm->uuid, 'field_id' => $field->uuid, 'season_id' => $this->seasonId(),
            'crop_type_id' => $this->cropTypeId(), 'code' => $code, 'planned_area' => 10, 'area_unit' => 'ha', 'planned_planting_on' => today()->subMonths(5)->toDateString(),
            'planned_harvest_on' => today()->toDateString(), 'expected_yield' => 40, 'yield_unit' => 't'])->assertSessionHasNoErrors();
        $plan = CropPlan::withoutGlobalScopes()->where('code', $code)->firstOrFail();
        $this->actingAs($this->admin)->post(route('agri.crops.plans.transition', $plan->uuid), ['action' => 'approve'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.crops.plans.transition', $plan->uuid), ['action' => 'plant'])->assertSessionHasNoErrors();

        return $plan->fresh();
    }

    private function store($farm, string $kind, string $name): FarmStore
    {
        return $this->asCompany($this->company, fn () => app(FarmInventory::class)->addStore($farm, ['kind' => $kind, 'name' => $name]));
    }

    /** 1 000 kg harvested into the cold room at R4/kg, 50 kg rejected. */
    private function intake($farm, CropPlan $plan, FarmStore $cold, $bulk): HarvestRecord
    {
        $this->actingAs($this->manager)->post(route('agri.crops.harvests.store', $plan->uuid), ['harvested_on' => today()->toDateString(), 'quantity' => 1000, 'unit' => 'kg',
            'grade' => 'Field run', 'destination' => 'store', 'stock_item_id' => $bulk->id, 'unit_value' => 4, 'rejected_quantity' => 50, 'store_id' => $cold->uuid,
            'storage_location' => 'Bay 3', 'measuring_method' => 'weighbridge', 'inspection_outcome' => 'passed', 'container_ref' => 'Bins 101-120'])->assertSessionHasNoErrors();

        return HarvestRecord::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    private function pack(ProduceLot $from, $carton, $box, FarmStore $cold, float $in, float $out, array $checks = [], ?int $standard = null)
    {
        return $this->actingAs($this->manager)->post(route('agri.packhouse.operations.store'), ['farm_id' => $from->farm->uuid, 'kind' => 'packing', 'standard_id' => $standard,
            'inputs' => [['lot_id' => $from->uuid, 'quantity' => $in]],
            'outputs' => [['product' => 'Apples 12.5 kg carton', 'grade' => 'Class 1', 'quantity' => $out, 'unit' => 'ctn', 'stock_item_id' => $carton->id,
                'store_id' => $cold->uuid, 'location' => 'Rack A', 'best_before' => today()->addWeeks(6)->toDateString()]],
            'packaging' => [['stock_item_id' => $box->id, 'quantity' => $out]], 'loss_reason' => 'Sorted out: bruising', 'workers' => 6, 'labour_minutes' => 600,
            'quality_checks' => $checks]);
    }

    public function test_8_1_and_8_2_intake_puts_the_sound_produce_in_its_store_and_packing_makes_linked_lots_at_full_cost(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $plan = $this->planted($farm, $this->field($farm, 'O1', 10));
        $cold = $this->store($farm, 'cold_room', 'Cold room 1');
        $bulk = $this->stockItem('APL-BULK', 'kg', 4);
        $carton = $this->stockItem('APL-C1', 'ctn', 60);
        $box = $this->stockItem('CTN-12', 'ea', 8);
        $this->stockOnFarm($farm, $box, 500, 8);

        $h = $this->intake($farm, $plan, $cold, $bulk);
        $this->assertSame('weighbridge', $h->measuring_method);
        $this->assertSame($cold->id, (int) $h->store_id);
        $ledger = app(StockLedger::class);
        $this->assertEqualsWithDelta(950.0, $ledger->available($bulk->fresh(), $cold->warehouse_id), 0.001, 'Rejects do not go into the store.');
        $lot = ProduceLot::withoutGlobalScopes()->where('kind', 'harvest')->sole();
        $this->assertSame($h->lot_code, $lot->code);
        $this->assertEqualsWithDelta(950.0, $lot->remaining, 0.001);
        $this->assertSame('Bay 3', $lot->location);

        // A standard with a firmness range; packing 900 kg into 70 cartons.
        $this->actingAs($this->manager)->post(route('agri.packhouse.standards.store'), ['name' => 'Apples export', 'grades' => [['code' => 'Class 1'], ['code' => 'Class 2']],
            'checks' => [['check' => 'Firmness', 'unit' => 'kg/cm²', 'min' => 6, 'max' => 9]]])->assertSessionHasNoErrors();
        $std = GradingStandard::withoutGlobalScopes()->sole();
        $this->pack($lot, $carton, $box, $cold, 900, 70, [['check' => 'Firmness', 'value' => 7.2]], $std->id)->assertSessionHasNoErrors();
        $op = PackhouseOperation::withoutGlobalScopes()->sole();
        $this->assertTrue($op->quality_passed);
        // The packing is a Manufacturing production run at the farm's packhouse, with its lots in the stock ledger.
        $run = ProductionOrder::withoutGlobalScopes()->with(['outputs', 'components', 'factory'])->findOrFail($op->production_order_id);
        $this->assertSame([ProductionOrder::RUN, ProductionOrder::CLOSED, 'PH-NORTH'], [$run->order_type, $run->status, $run->factory->code]);
        $this->assertEqualsCanonicalizing(['COMPONENT', 'PACKAGING'], $run->components->pluck('line_kind')->all());
        $this->assertSame($lot->stock_lot_id, (int) DB::table('fin_stock_movements')->where('source_type', 'PRODUCTION_ORDER')->where('source_id', $run->id)
            ->where('type', '!=', 'mfg_receipt')->where('item_id', $bulk->id)->value('lot_id'), 'The field lot is issued by its stock lot.');
        $this->assertEqualsWithDelta(3600.0, $op->input_value, 0.01);
        $this->assertEqualsWithDelta(3600.0 + 560.0, $op->output_value, 0.01, 'Field produce plus the cartons it went into.');
        $packed = ProduceLot::withoutGlobalScopes()->where('kind', 'packed')->sole();
        $this->assertSame((int) $run->outputs->sole()->lot_id, (int) $packed->stock_lot_id);
        $this->assertSame($packed->code, DB::table('fin_stock_lots')->where('id', $packed->stock_lot_id)->value('lot_number'));
        // Manufacturing's own traceability walks from the carton lot back to the field lot.
        $up = $this->asCompany($this->company, fn () => app(\App\Modules\Operations\Manufacturing\Services\Traceability::class)
            ->backward(\App\Modules\Finance\Accounting\Models\FinStockLot::withoutGlobalScopes()->findOrFail($packed->stock_lot_id)));
        $this->assertContains((int) $lot->stock_lot_id, collect($up['nodes'] ?? $up['lots'] ?? [])->pluck('id')->map(fn ($x) => (int) $x)->all(), json_encode(array_keys($up)));
        $this->assertEqualsWithDelta(4160 / 70, $packed->unit_cost, 0.001);
        $this->assertEqualsWithDelta(70.0, $ledger->available($carton->fresh(), $cold->warehouse_id), 0.001);
        $this->assertEqualsWithDelta(50.0, $ledger->available($bulk->fresh(), $cold->warehouse_id), 0.001);
        $this->assertEqualsWithDelta(50.0, $lot->fresh()->remaining, 0.001);
        $this->assertSame([$lot->id], DB::table('produce_lot_links')->where('child_lot_id', $packed->id)->pluck('parent_lot_id')->map(fn ($x) => (int) $x)->all());
        // Work in progress clears: what went in came out.
        $wip = DB::table('fin_journal_lines as l')->join('fin_accounts as a', 'a.id', '=', 'l.account_id')->where('a.code', config('finance.wip_account', '1310'))
            ->where('l.company_id', $this->company->id)->selectRaw('SUM(l.debit - l.credit) as net')->value('net');
        $this->assertEqualsWithDelta(0.0, (float) $wip, 0.01);

        // A grade not in the standard is refused; a failed check quarantines what was made.
        $this->pack($lot->fresh(), $carton, $box, $cold, 10, 1, [], $std->id)->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.packhouse.operations.store'), ['farm_id' => $farm->uuid, 'kind' => 'grading', 'standard_id' => $std->id,
            'inputs' => [['lot_id' => $lot->uuid, 'quantity' => 5]], 'outputs' => [['product' => 'Apples', 'grade' => 'Extra', 'quantity' => 5, 'unit' => 'kg',
                'stock_item_id' => $bulk->id, 'store_id' => $cold->uuid]]])->assertSessionHasErrors('outputs');
        $this->pack($lot->fresh(), $carton, $box, $cold, 20, 1, [['check' => 'Firmness', 'value' => 4.1]], $std->id)->assertSessionHasNoErrors();
        $this->assertSame('quarantined', ProduceLot::withoutGlobalScopes()->where('kind', 'packed')->latest('id')->first()->status);
        // More than is left cannot go in.
        $this->pack($lot->fresh(), $carton, $box, $cold, 500, 40)->assertSessionHasErrors('inputs');

        $this->actingAs($this->manager)->get(route('agri.packhouse', ['tab' => 'lots', 'farm' => $farm->uuid]))->assertOk();
        $this->actingAs($this->manager)->get(route('agri.packhouse', ['tab' => 'operations', 'farm' => $farm->uuid]))->assertOk();
        $this->actingAs($this->manager)->get(route('agri.packhouse', ['tab' => 'standards', 'farm' => $farm->uuid]))->assertOk();
    }

    public function test_8_3_a_reading_out_of_range_is_flagged_and_waits_for_a_corrective_action_and_sensors_post_through_a_gateway(): void
    {
        Notification::fake();
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $cold = $this->store($farm, 'cold_room', 'Cold room 1');
        $this->actingAs($this->manager)->post(route('agri.packhouse.stores.range', $cold->uuid), ['temperature_min' => 0, 'temperature_max' => 4, 'humidity_min' => 85,
            'humidity_max' => 95, 'sensor_ref' => 'CR1-T'])->assertSessionHasNoErrors();

        $this->actingAs($this->manager)->post(route('agri.packhouse.stores.readings', $cold->uuid), ['temperature' => 2.5, 'humidity' => 90])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.packhouse.stores.readings', $cold->uuid), ['temperature' => 7.8, 'humidity' => 90])->assertSessionHasNoErrors();
        $bad = StorageReading::withoutGlobalScopes()->where('out_of_range', true)->sole();
        $this->assertStringContainsString('above 4', $bad->deviation);
        Notification::assertSentTo($this->manager, \App\Modules\Agri\Notifications\FarmWorkNotice::class, fn ($n) => str_contains($n->subject, 'out of range'));
        $this->actingAs($this->manager)->post(route('agri.packhouse.readings.correct', $bad->uuid), ['action' => ''])->assertSessionHasErrors('action');
        $this->actingAs($this->manager)->post(route('agri.packhouse.readings.correct', $bad->uuid), ['action' => 'Door left open; closed and compressor reset'])->assertSessionHasNoErrors();
        $this->assertSame($this->manager->id, (int) $bad->fresh()->acted_by);

        // A sensor gateway posts with its own secret; the same reading twice is kept once.
        [$device, $secret] = $this->asCompany($this->company, fn () => app(\App\Modules\HR\Hr\Attendance\Services\ClockDevices::class)->register($farm, 'Cold store gateway', 'sensor_gateway', $this->manager));
        $body = ['readings' => [['sensor' => 'CR1-T', 'temperature' => 3.1, 'humidity' => 88, 'client_id' => 'g1-0001'], ['sensor' => 'NOPE', 'temperature' => 1]]];
        $this->postJson(route('api.agri.devices.readings', $device->uuid), $body)->assertUnauthorized();
        $r = $this->withToken($secret)->postJson(route('api.agri.devices.readings', $device->uuid), $body)->assertOk()->json('results');
        $this->assertTrue($r[0]['ok']);
        $this->assertFalse($r[1]['ok']);
        $this->withToken($secret)->postJson(route('api.agri.devices.readings', $device->uuid), $body)->assertOk();
        $this->assertSame(1, StorageReading::withoutGlobalScopes()->where('source', 'sensor')->count());

        $this->actingAs($this->manager)->get(route('agri.packhouse', ['tab' => 'storage', 'farm' => $farm->uuid]))->assertOk();
    }

    public function test_acceptance_a_delivery_traces_back_to_its_field_and_harvest_and_a_field_lot_forward_to_every_packed_lot_and_destination(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $this->member($this->supervisor, $farm, 'supervisor');
        $field = $this->field($farm, 'O1', 10);
        $plan = $this->planted($farm, $field);
        $cold = $this->store($farm, 'cold_room', 'Cold room 1');
        $bulk = $this->stockItem('APL-BULK', 'kg', 4);
        $carton = $this->stockItem('APL-C1', 'ctn', 60);
        $box = $this->stockItem('CTN-12', 'ea', 8);
        $this->stockOnFarm($farm, $box, 500, 8);
        $h = $this->intake($farm, $plan, $cold, $bulk);
        $field_lot = ProduceLot::withoutGlobalScopes()->where('kind', 'harvest')->sole();
        $this->pack($field_lot, $carton, $box, $cold, 450, 35)->assertSessionHasNoErrors();
        $this->pack($field_lot->fresh(), $carton, $box, $cold, 450, 36)->assertSessionHasNoErrors();
        [$p1, $p2] = ProduceLot::withoutGlobalScopes()->where('kind', 'packed')->orderBy('id')->get()->all();
        $p2->update(['best_before' => today()->addWeeks(2)->toDateString()]);   // expires first, picked first

        // The order, its pick list (first to expire first), the pick and an independent count.
        // The order is an Accounting sales order charged to the farm (a walk-in buyer becomes a customer).
        $this->actingAs($this->manager);
        $order = $this->asCompany($this->company, fn () => app(\App\Modules\Agri\Services\ProduceDispatches::class)->order($farm, ['buyer' => 'FreshMark DC',
            'deliver_on' => today()->addDay()->toDateString(), 'delivery_address' => 'Centurion DC, dock 4'],
            [['product' => 'Apples 12.5 kg carton', 'grade' => 'Class 1', 'quantity' => 50, 'unit' => 'ctn', 'price' => 180]], $this->manager))->load('lines');
        $this->assertSame('FreshMark DC', $order->party->name);
        $picks = $this->asCompany($this->company, fn () => app(\App\Modules\Agri\Services\ProduceDispatches::class)->pickList($order))[0]['picks'];
        $this->assertSame([$p2->code, $p1->code], array_column($picks, 'lot'));
        $this->assertEquals([36, 14], array_column($picks, 'quantity'));
        $line = $order->lines->first();
        $this->actingAs($this->manager)->post(route('agri.packhouse.orders.pick', $order->uuid), ['items' => [['order_line_id' => $line->id, 'lot_id' => $p2->id, 'quantity' => 36],
            ['order_line_id' => $line->id, 'lot_id' => $p1->id, 'quantity' => 14]], 'vehicle' => 'CA 123-456', 'driver' => 'T. Mokoena'])->assertSessionHasNoErrors();
        // The load is the warehouse's fulfilment order, allocated to the lots picked and on a pallet.
        $load = FulfilmentOrder::query()->with('packages.contents')->sole();
        $this->assertSame([$order->id, 'CA 123-456'], [(int) $load->source_id, $load->carrier]);
        $contents = $load->packages->sole()->contents;
        $this->assertEqualsCanonicalizing([$p1->stock_lot_id, $p2->stock_lot_id], $contents->pluck('lot_id')->map(fn ($x) => (int) $x)->all());
        $counts = $contents->mapWithKeys(fn ($c) => [$c->id => (float) $c->qty])->all();
        $this->actingAs($this->manager)->post(route('agri.packhouse.dispatches.verify', $load->uuid), ['counts' => $counts])->assertSessionHasErrors('dispatch');   // the picker
        $wrong = $counts;
        $wrong[array_key_first($wrong)] -= 1;
        $this->actingAs($this->admin)->post(route('agri.packhouse.dispatches.verify', $load->uuid), ['counts' => $wrong])->assertSessionHasErrors('counts');
        $this->actingAs($this->admin)->post(route('agri.packhouse.dispatches.verify', $load->uuid), ['counts' => $counts, 'seal_no' => 'S-9981'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.packhouse.dispatches.dispatch', $load->uuid))->assertSessionHasNoErrors();
        $shipment = $load->shipments()->sole();
        $this->assertSame(['S-9981', 'T. Mokoena'], [$shipment->tracking_reference, $shipment->driver_name]);
        $this->assertNotNull($shipment->delivery_note_id, 'The shipment wrote Accounting\'s delivery note.');
        $this->assertEqualsWithDelta(21.0, app(StockLedger::class)->available($carton->fresh(), $cold->warehouse_id), 0.001);
        $this->assertSame('dispatched', $p2->fresh()->status);

        // Delivered: 2 cartons of p1 rejected and brought back, the rest accepted.
        $i1 = $contents->firstWhere('lot_id', $p1->stock_lot_id);
        $i2 = $contents->firstWhere('lot_id', $p2->stock_lot_id);
        $this->actingAs($this->manager)->post(route('agri.packhouse.dispatches.deliver', $load->uuid), ['results' => [$i2->id => ['accepted' => 36],
            $i1->id => ['accepted' => 12, 'rejected' => 2]], 'received_by' => 'DC receiving'])->assertSessionHasErrors('results');   // no reason given
        $this->actingAs($this->manager)->post(route('agri.packhouse.dispatches.deliver', $load->uuid), ['results' => [$i2->id => ['accepted' => 36],
            $i1->id => ['accepted' => 12, 'rejected' => 2, 'rejection_reason' => 'Bruised', 'rejected_to' => 'returned']], 'received_by' => 'DC receiving'])->assertSessionHasNoErrors();
        $this->assertSame(['DELIVERED', 'DC receiving'], [$shipment->fresh()->status, $shipment->fresh()->pod_received_by]);
        $invoice = DB::table('fin_invoices')->where('id', DB::table('fin_delivery_notes')->where('id', $shipment->delivery_note_id)->value('invoice_id'))->first();
        $sale = DB::table('fin_journal_lines')->where('journal_id', $invoice->journal_id)->sum('credit');
        $this->assertEqualsWithDelta(48 * 180.0, (float) $sale, 0.01, 'The sale is what the customer accepted.');
        $this->assertEqualsWithDelta(21.0, app(StockLedger::class)->available($carton->fresh(), $cold->warehouse_id), 0.001, 'The rejected cartons are back but not available...');
        $this->assertEqualsWithDelta(2.0, (float) DB::table('fin_stock_positions')->where('item_id', $carton->id)->where('lot_id', $p1->stock_lot_id)
            ->where('status', 'QUARANTINE')->sum('qty'), 0.001, '...they are a customer return, held in their own lot for inspection.');
        $this->assertSame(1, DB::table('wms_customer_returns')->where('order_id', $load->id)->count());
        $this->assertSame('invoiced', $order->fresh()->status, 'Delivered, and what was accepted is invoiced: the Accounting order is settled.');

        // Backward, from the delivered lot: the field, the harvest lot, the crop.
        $post = app(PostHarvest::class);
        $back = $this->asCompany($this->company, fn () => $post->backward($p2->fresh()));
        $this->assertSame($field_lot->code, $back['harvests'][0]['lot']);
        $this->assertSame('O1', $back['harvests'][0]['field']);
        $this->assertSame($plan->code, $back['harvests'][0]['crop_plan']);
        $this->assertCount(1, $back['operations']);
        // Forward, from the field lot: both packed lots, and the customer they went to.
        $fwd = $this->asCompany($this->company, fn () => $post->forward($field_lot->fresh()));
        $this->assertEqualsCanonicalizing([$field_lot->code, $p1->code, $p2->code], array_column($fwd['lots'], 'code'));
        $this->assertSame('FreshMark DC', $fwd['deliveries'][0]['buyer']);
        $this->assertSame('Centurion DC, dock 4', $fwd['deliveries'][0]['address']);
        $this->actingAs($this->manager)->getJson(route('agri.packhouse.lots.trace', $p2->uuid))->assertOk()->assertJsonPath('backward.harvests.0.field', 'O1');
        $this->actingAs($this->manager)->get(route('agri.packhouse', ['tab' => 'trace', 'farm' => $farm->uuid, 'lot' => $p2->code]))->assertOk();

        // A recall on the field lot: what is still here is quarantined, the customer is listed.
        $this->actingAs($this->supervisor)->post(route('agri.packhouse.lots.recall', $field_lot->uuid), ['reason' => 'x', 'severity' => 'high'])->assertForbidden();
        $this->actingAs($this->manager)->post(route('agri.packhouse.lots.recall', $field_lot->uuid), ['reason' => 'Residue above MRL on block O1', 'severity' => 'high'])->assertRedirect();
        // A Manufacturing recall: it traced the field lot to both packed lots, saw what was shipped, and holds what is still here.
        $recall = Recall::withoutGlobalScopes()->with('lots')->sole();
        $this->assertSame('CLASS_I', $recall->severity);
        $this->assertEqualsCanonicalizing([$field_lot->stock_lot_id, $p1->stock_lot_id, $p2->stock_lot_id], $recall->lots->pluck('lot_id')->map(fn ($x) => (int) $x)->all());
        $this->assertEqualsWithDelta(14.0, (float) $recall->lots->firstWhere('lot_id', $p1->stock_lot_id)->dispatched_at_open, 0.001);
        $this->assertSame('recalled', $p1->fresh()->status);
        $this->assertSame('recalled', $field_lot->fresh()->status);
        $held = DB::table('fin_stock_positions')->where('item_id', $carton->id)->where('warehouse_id', $cold->warehouse_id)->where('status', 'ON_HOLD')->sum('qty');
        $this->assertEqualsWithDelta(21.0, (float) $held, 0.001, 'Every usable carton still on hand is held in the stock ledger.');
        // Recalled stock is disposed of on the recall, not written off from the farm.
        $this->actingAs($this->manager)->post(route('agri.packhouse.lots.write-off', $p1->uuid), ['reason' => 'Recall: destroyed'])->assertSessionHasErrors('lot');
        $this->asCompany($this->company, fn () => app(\App\Modules\Operations\Manufacturing\Services\Recalls::class)->dispose($recall,
            $recall->lots->firstWhere('lot_id', $p1->stock_lot_id), 21, 'Destroyed under recall', $this->manager));
        $this->assertEqualsWithDelta(2.0, $p1->fresh()->remaining, 0.001, 'The farm lot follows the stock: only the returned cartons, in quarantine, are left.');
        $this->assertSame('quarantined', $p1->fresh()->status);
        $this->actingAs($this->manager)->get(route('agri.packhouse', ['tab' => 'recalls', 'farm' => $farm->uuid]))->assertOk();
        $this->actingAs($this->manager)->get(route('agri.packhouse', ['tab' => 'dispatch', 'farm' => $farm->uuid]))->assertOk();
    }
}
