<?php

namespace Tests\Feature\Agri;

use App\Modules\Agri\Models\FarmMachine;
use App\Modules\Agri\Models\FarmStore;
use App\Modules\Agri\Models\FarmWorkOrder as WO;
use App\Modules\Agri\Models\MachineFault;
use App\Modules\Agri\Models\MachineUsageLog;
use App\Modules\Agri\Models\WorkOrderMachine;
use App\Modules\Agri\Services\FarmInventory;
use App\Modules\Agri\Services\FleetFuel;
use App\Modules\Agri\Services\MachineMaintenance;
use App\Modules\Agri\Services\MachineRisk;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 §7: booking requests and approval with the machine's availability and inspection checked;
 * pre-start checks; fuel from the farm's tanks and the indicators drawn from it; the risk ranking;
 * and the section's acceptance test — when a machine becomes unavailable, conflicting allocations are
 * prevented or flagged, the affected work orders are identified, and maintenance costs land on the asset.
 */
class MachineryFleetTest extends AgriTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->catalogue();
    }

    private function machine($farm, string $code = 'T-01', array $o = []): FarmMachine
    {
        return $this->asCompany($this->company, fn () => app(\App\Modules\Agri\Services\Machinery::class)->register($farm,
            $o + ['code' => $code, 'name' => 'Tractor', 'machine_type' => 'tractor', 'hours_reading' => 1000, 'hourly_rate' => 450, 'service_interval_hours' => 250]));
    }

    private function order($farm, string $title = 'Disc L1', int $startHours = 2, array $o = []): WO
    {
        $this->actingAs($this->manager)->post(route('agri.work-orders.store'), $o + ['farm_id' => $farm->uuid, 'category' => 'land_preparation',
            'title' => $title, 'priority' => 'normal', 'planned_start' => now()->addHours($startHours)->toDateTimeString(),
            'planned_end' => now()->addHours($startHours + 6)->toDateTimeString()])->assertSessionHasNoErrors();

        return WO::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    private function book(WO $wo, FarmMachine $m, $user, int $from, int $to, array $o = [])
    {
        return $this->actingAs($user)->post(route('agri.machinery.bookings.store'), $o + ['work_order_id' => $wo->uuid, 'machine_id' => $m->uuid,
            'booked_from' => now()->addHours($from)->toDateTimeString(), 'booked_to' => now()->addHours($to)->toDateTimeString()]);
    }

    private function to(WO $wo, $user, string $to, array $extra = [])
    {
        return $this->actingAs($user)->post(route('agri.work-orders.transition', $wo->uuid), ['to' => $to] + $extra);
    }

    public function test_7_2_a_booking_is_a_request_that_holds_the_slot_until_approved_and_an_expired_inspection_blocks_it(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $this->member($this->supervisor, $farm, 'supervisor');
        $tractor = $this->machine($farm);
        $a = $this->order($farm, 'Disc L1');
        $b = $this->order($farm, 'Disc L2');

        // The supervisor asks; the slot is held against a second request.
        $this->book($a, $tractor, $this->supervisor, 2, 8)->assertSessionHasNoErrors();
        $req = WorkOrderMachine::withoutGlobalScopes()->sole();
        $this->assertSame('requested', $req->status);
        $this->book($b, $tractor, $this->supervisor, 4, 6)->assertSessionHasErrors('machine_id');
        $this->actingAs($this->supervisor)->post(route('agri.machinery.bookings.decide', $req->uuid), ['approve' => true])->assertForbidden();
        $this->actingAs($this->manager)->post(route('agri.machinery.bookings.decide', $req->uuid), ['approve' => true])->assertSessionHasNoErrors();
        $this->assertSame('approved', $req->fresh()->status);
        $this->assertSame($this->manager->id, (int) $req->fresh()->approved_by);

        // A machine that needs a current inspection cannot be booked past its expiry.
        $sprayer = $this->machine($farm, 'S-01', ['name' => 'Boom sprayer', 'machine_type' => 'sprayer', 'inspection_required' => true,
            'inspection_expires_on' => today()->addDay()->toDateString()]);
        $this->book($b, $sprayer, $this->manager, 2, 4)->assertSessionHasNoErrors();
        $this->book($b, $sprayer, $this->manager, 50, 52)->assertSessionHasErrors('machine_id');
        // A licence lapsing is a warning to accept, not a block.
        $bakkie = $this->machine($farm, 'V-01', ['name' => 'Bakkie', 'machine_type' => 'vehicle', 'licence_expires_on' => today()->toDateString()]);
        $this->book($b, $bakkie, $this->manager, 30, 32)->assertSessionHasErrors('warnings');
        $this->book($b, $bakkie, $this->manager, 30, 32, ['accept_warnings' => true])->assertSessionHasNoErrors();

        $this->actingAs($this->manager)->get(route('agri.machinery.fleet', ['tab' => 'bookings', 'farm' => $farm->uuid]))->assertOk();
    }

    public function test_7_3_a_failed_critical_prestart_item_stops_the_machine_and_a_minor_one_does_not(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $this->member($this->supervisor, $farm, 'supervisor');
        $tractor = $this->machine($farm);
        $list = $this->asCompany($this->company, fn () => app(MachineMaintenance::class)->checklistFor($tractor));
        $items = fn (array $bad) => collect($list)->map(fn ($c) => ['item' => $c['item'], 'ok' => ! in_array($c['item'], $bad, true), 'note' => in_array($c['item'], $bad, true) ? 'Checked' : null])->all();
        $post = fn (array $bad, array $o = []) => $this->actingAs($this->supervisor)->post(route('agri.machinery.prestart', $tractor->uuid), $o + ['items' => $items($bad), 'hours_reading' => 1002]);

        // Every item must be checked; the meter cannot go back.
        $this->actingAs($this->supervisor)->post(route('agri.machinery.prestart', $tractor->uuid), ['items' => array_slice($items([]), 0, 3)])->assertSessionHasErrors('items');
        $post([], ['hours_reading' => 900])->assertSessionHasErrors('hours_reading');

        $post(['Lights and indicators'])->assertSessionHasNoErrors();
        $this->assertSame(FarmMachine::AVAILABLE, $tractor->fresh()->status);
        $this->assertEquals(1002, (float) $tractor->fresh()->hours_reading);
        $this->assertSame('minor', MachineFault::withoutGlobalScopes()->sole()->severity);

        $post(['Brakes'], ['checked_on' => today()->subDay()->toDateString()])->assertSessionHasNoErrors();
        $this->assertSame(FarmMachine::OUT_OF_SERVICE, $tractor->fresh()->status);
        $this->assertTrue(MachineFault::withoutGlobalScopes()->where('severity', 'critical')->where('stops_machine', true)->exists());

        $risk = $this->asCompany($this->company, fn () => app(MachineRisk::class)->assess($tractor->fresh()));
        $this->assertGreaterThan(0, $risk['score']);
        $this->assertSame('low', $risk['confidence'], 'Little history: low confidence, whatever the score.');
        $this->assertNotEmpty($risk['factors']);
        $this->assertStringContainsString('does not predict', $risk['basis']);
        $this->actingAs($this->manager)->get(route('agri.machinery.fleet', ['tab' => 'risk', 'farm' => $farm->uuid]))->assertOk();
        $this->actingAs($this->manager)->get(route('agri.machinery.fleet', ['tab' => 'prestart', 'farm' => $farm->uuid]))->assertOk();
    }

    public function test_7_4_fuel_from_the_farm_tank_lands_on_the_machine_and_odd_consumption_losses_and_missing_readings_are_flagged(): void
    {
        $this->openBooks();
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $tractor = $this->machine($farm);
        $diesel = $this->stockItem('DSL', 'l', 22);
        $tank = $this->asCompany($this->company, fn () => app(FarmInventory::class)->addStore($farm, ['kind' => 'fuel_tank', 'name' => 'Diesel tank']));
        $wh = $this->asCompany($this->company, fn () => FinWarehouse::withoutGlobalScopes()->findOrFail($tank->warehouse_id));
        $this->asCompany($this->company, fn () => app(StockLedger::class)->receive($diesel, 5000, 22, $wh, ['reference' => 'Opening', 'source_type' => 'OPENING',
            'idempotency_key' => 'tank-open', 'contra' => config('finance.opening_balance_account', '3100')]));

        // Fills with the meter read at the pump: about 10 l/h, then one at 20 l/h.
        $fill = fn (float $l, ?float $h, array $o = []) => $this->actingAs($this->manager)->post(route('agri.machinery.fuel', $tractor->uuid), $o + ['store_id' => $tank->uuid,
            'stock_item_id' => $diesel->id, 'litres' => $l, 'hours_reading' => $h])->assertSessionHasNoErrors();
        $fill(100, 1000);
        $fill(100, 1010);
        $fill(105, 1020);
        $fill(95, 1030);
        $fill(100, 1040);
        $fill(200, 1050);
        $fill(60, null);

        $log = MachineUsageLog::withoutGlobalScopes()->where('kind', 'fuel')->orderBy('id')->first();
        $this->assertEqualsWithDelta(2200.0, (float) $log->fuel_cost, 0.01);
        $this->assertEqualsWithDelta(4240.0, app(StockLedger::class)->available($diesel->fresh(), $tank->warehouse_id), 0.01);
        $this->assertEqualsWithDelta(1050.0, (float) $tractor->fresh()->hours_reading, 0.01);
        $onMachine = DB::table('fin_journal_lines')->whereRaw("dimensions->>'agri_machine' = ?", [(string) $tractor->id])->whereRaw("dimensions->>'agri_category' = 'fuel'")->sum('debit');
        $this->assertEqualsWithDelta(760 * 22.0, (float) $onMachine, 0.01, 'Every litre issued is a cost on the machine.');
        // A tank fill to a non-tank store is refused.
        $main = $this->asCompany($this->company, fn () => FarmStore::withoutGlobalScopes()->where('farm_id', $farm->id)->where('kind', 'main')->first());
        if ($main) {
            $this->actingAs($this->manager)->post(route('agri.machinery.fuel', $tractor->uuid), ['store_id' => $main->uuid, 'stock_item_id' => $diesel->id, 'litres' => 10])->assertSessionHasErrors('store_id');
        }

        // More than the tank holds is refused as a form error, not a crash (the stock ledger's PostingException).
        $this->actingAs($this->manager)->post(route('agri.machinery.fuel', $tractor->uuid), ['store_id' => $tank->uuid, 'stock_item_id' => $diesel->id, 'litres' => 4500,
            'hours_reading' => 1060])->assertSessionHasErrors('litres');

        // The dip shows 40 l less than the books.
        $this->asCompany($this->company, fn () => app(StockLedger::class)->count($diesel->fresh(), $wh, 4200, ['reason' => 'Monthly dip', 'idempotency_key' => 'dip-1']));

        $flags = collect($this->asCompany($this->company, fn () => app(FleetFuel::class)->indicators($farm->fresh())));
        $this->assertTrue($flags->contains(fn ($f) => $f['kind'] === 'high_consumption' && str_contains($f['detail'], '20')), 'The 20 l/h fill stands out against the ~10 l/h norm.');
        $this->assertTrue($flags->contains(fn ($f) => $f['kind'] === 'missing_reading'));
        $this->assertTrue($flags->contains(fn ($f) => $f['kind'] === 'tank_loss' && str_contains($f['detail'], '40')));
        $this->assertTrue($flags->every(fn ($f) => str_contains($f['note'], 'not proof')));

        // Trips: the odometer only moves forward.
        $this->actingAs($this->manager)->post(route('agri.machinery.trips', $tractor->uuid), ['odometer_start' => 0, 'odometer_end' => 12, 'purpose' => 'Fetch seed', 'from' => 'Yard', 'to' => 'Co-op'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.machinery.trips', $tractor->uuid), ['odometer_start' => 5, 'odometer_end' => 8, 'purpose' => 'Back'])->assertSessionHasErrors('odometer_km');
        $this->actingAs($this->manager)->get(route('agri.machinery.fleet', ['tab' => 'fuel', 'farm' => $farm->uuid]))->assertOk();
    }

    public function test_acceptance_an_unavailable_machine_flags_and_prevents_conflicting_allocations_lists_the_affected_jobs_and_carries_its_maintenance_cost(): void
    {
        $this->openBooks();
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $this->member($this->supervisor, $farm, 'supervisor');
        $tractor = $this->machine($farm);
        $a = $this->order($farm, 'Disc L1', 2);
        $b = $this->order($farm, 'Plant L1', 30);
        $this->book($a, $tractor, $this->manager, 2, 8)->assertSessionHasNoErrors();
        $this->book($b, $tractor, $this->supervisor, 30, 36)->assertSessionHasNoErrors();   // a request still holds the slot

        // The tractor breaks down.
        $this->actingAs($this->supervisor)->post(route('agri.machinery.faults.store', $tractor->uuid), ['severity' => 'major', 'description' => 'Hydraulic pump failed',
            'stops_machine' => true])->assertSessionHasNoErrors()->assertRedirect();
        $fault = MachineFault::withoutGlobalScopes()->sole();
        $this->assertSame(FarmMachine::OUT_OF_SERVICE, $tractor->fresh()->status);
        // Affected work orders identified...
        $this->assertEqualsCanonicalizing([$a->reference, $b->reference], collect($fault->affected_work_orders)->pluck('reference')->all());
        $this->assertEqualsCanonicalizing([$a->reference, $b->reference], collect($this->actingAs($this->manager)->getJson(route('agri.machinery.affected', $tractor->uuid))->json('affected'))->pluck('reference')->all());
        // ...their bookings flagged...
        $this->assertSame(2, WorkOrderMachine::withoutGlobalScopes()->whereNotNull('conflict')->count());
        // ...new allocations prevented, and the pending request cannot be approved.
        $c = $this->order($farm, 'Ridge L2', 50);
        $this->book($c, $tractor, $this->manager, 50, 52)->assertSessionHasErrors('machine_id');
        $this->actingAs($this->manager)->post(route('agri.work-orders.machines', $c->uuid), ['machine_id' => $tractor->uuid,
            'booked_from' => now()->addHours(50)->toDateTimeString(), 'booked_to' => now()->addHours(52)->toDateTimeString()])->assertSessionHasErrors('machine_id');
        $pending = WorkOrderMachine::withoutGlobalScopes()->where('status', 'requested')->sole();
        $this->actingAs($this->manager)->post(route('agri.machinery.bookings.decide', $pending->uuid), ['approve' => true])->assertSessionHasErrors('booking');

        // The repair job: parts from the workshop store, the technician's time.
        $seal = $this->stockItem('SEAL-KIT', 'ea', 1800);
        $workshop = $this->asCompany($this->company, fn () => app(FarmInventory::class)->addStore($farm, ['kind' => 'workshop', 'name' => 'Workshop']));
        $this->asCompany($this->company, fn () => app(StockLedger::class)->receive($seal, 4, 1800, FinWarehouse::withoutGlobalScopes()->findOrFail($workshop->warehouse_id),
            ['reference' => 'Opening', 'source_type' => 'OPENING', 'idempotency_key' => 'ws-open', 'contra' => config('finance.opening_balance_account', '3100')]));
        $this->actingAs($this->manager)->post(route('agri.machinery.faults.repair', $fault->uuid))->assertRedirect();
        $repair = WO::withoutGlobalScopes()->where('category', 'machinery_service')->sole();
        $this->assertSame($tractor->id, (int) $repair->machine_id);
        $this->assertSame(FarmMachine::MAINTENANCE, $tractor->fresh()->status);
        $this->assertSame('in_repair', $fault->fresh()->status);
        $this->actingAs($this->manager)->post(route('agri.work-orders.materials', $repair->uuid), ['stock_item_id' => $seal->id, 'planned_quantity' => 2])->assertSessionHasNoErrors();
        $this->to($repair, $this->admin, WO::APPROVED)->assertSessionHasNoErrors();
        $this->to($repair, $this->manager, WO::IN_PROGRESS)->assertSessionHasNoErrors();

        // Not back to work before the repair is done and verified.
        $this->actingAs($this->admin)->post(route('agri.machinery.faults.return', $fault->uuid), ['note' => 'Early'])->assertSessionHasErrors('fault');
        $this->to($repair, $this->manager, WO::VERIFICATION)->assertSessionHasNoErrors();
        $this->to($repair, $this->admin, WO::COMPLETED, ['reason' => 'Pressure tested'])->assertSessionHasNoErrors();
        $this->assertSame('awaiting_return', $fault->fresh()->status);
        $this->actingAs($this->manager)->post(route('agri.machinery.faults.return', $fault->uuid), ['note' => 'Mine'])->assertSessionHasErrors('fault');
        $this->actingAs($this->admin)->post(route('agri.machinery.faults.return', $fault->uuid), ['note' => 'Pressure tested at 180 bar'])->assertSessionHasNoErrors();
        $this->assertSame(FarmMachine::AVAILABLE, $tractor->fresh()->status);
        $this->assertSame(0, WorkOrderMachine::withoutGlobalScopes()->whereNotNull('conflict')->count(), 'Back in service: the flags clear.');
        $this->assertNotNull($fault->fresh()->down_to);

        // The repair's cost is on the asset: the parts journal carries the machine, from the workshop store.
        $parts = DB::table('fin_journal_lines')->whereRaw("dimensions->>'agri_machine' = ?", [(string) $tractor->id])->whereRaw("dimensions->>'agri_category' = 'repairs'");
        $this->assertEqualsWithDelta(3600.0, (float) (clone $parts)->sum('debit'), 0.01);
        $this->assertEqualsWithDelta(2.0, app(StockLedger::class)->available($seal->fresh(), $workshop->warehouse_id), 0.001);
        $costs = $this->asCompany($this->company, fn () => app(MachineMaintenance::class)->costs($tractor->fresh()));
        $this->assertSame(1, $costs['repair_jobs']);
        $this->assertEqualsWithDelta(3600.0, $costs['parts'], 0.01);
        $this->assertGreaterThanOrEqual(0.0, $costs['downtime_hours']);
        $this->actingAs($this->manager)->getJson(route('agri.machinery.costs', $tractor->uuid))->assertOk()->assertJsonPath('parts', 3600);

        // A warranty claim on the fault, settled.
        $this->actingAs($this->manager)->post(route('agri.machinery.claims.store', $tractor->uuid), ['fault_id' => $fault->uuid, 'amount' => 3600])->assertSessionHasErrors('fault_id');
        $tractor->update(['warranty_until' => today()->addYear()->toDateString(), 'warranty_provider' => 'Dealer']);
        $this->actingAs($this->manager)->post(route('agri.machinery.claims.store', $tractor->uuid), ['fault_id' => $fault->uuid, 'amount' => 3600])->assertSessionHasNoErrors();
        $claim = \App\Modules\Agri\Models\MachineWarrantyClaim::withoutGlobalScopes()->sole();
        $this->actingAs($this->manager)->post(route('agri.machinery.claims.settle', $claim->uuid), ['status' => 'settled', 'recovered' => 3000, 'outcome' => 'Pump replaced under warranty'])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(600.0, $this->asCompany($this->company, fn () => app(MachineMaintenance::class)->costs($tractor->fresh()))['maintenance'], 0.01);

        $this->actingAs($this->manager)->get(route('agri.machinery.fleet', ['tab' => 'maintenance', 'farm' => $farm->uuid]))->assertOk();
        $this->actingAs($this->manager)->get(route('agri.machinery.index', ['farm' => $farm->uuid]))->assertOk();
    }
}
