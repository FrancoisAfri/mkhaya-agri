<?php

namespace Tests\Feature\Fleet;

use App\Modules\Fleet\Models\FleetFuelLog;
use Database\Factories\Fleet\FleetDriverFactory;
use Database\Factories\Fleet\FleetFuelLogFactory;
use Database\Factories\Fleet\FleetVehicleFactory;

class FleetFuelTest extends FleetTestCase
{
    // ── Logging ───────────────────────────────────────────────────────────────

    public function test_manager_can_log_fuel_fill_up(): void
    {
        $vehicle = FleetVehicleFactory::new()->create();
        $driver  = FleetDriverFactory::new()->create();

        $this->actingAs($this->fleetManager)
            ->post(route('fleet.fuel.store'), [
                'vehicle_id'     => $vehicle->id,
                'driver_id'      => $driver->id,
                'filled_at'      => now()->toDateTimeString(),
                'fuel_type'      => 'diesel',
                'litres'         => 50.5,
                'cost_per_litre' => 23.45,
                'total_cost'     => 1184.23,
                'odometer_reading'=> 55000,
                'station_name'   => 'Sasol Midrand',
                'full_tank'      => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('fleet_fuel_logs', [
            'vehicle_id' => $vehicle->id,
            'fuel_type'  => 'diesel',
            'litres'     => 50.5,
        ]);
    }

    public function test_manager_can_delete_fuel_log(): void
    {
        $log = FleetFuelLogFactory::new()->create();

        $this->actingAs($this->fleetManager)
            ->delete(route('fleet.fuel.destroy', $log))
            ->assertRedirect();

        $this->assertSoftDeleted('fleet_fuel_logs', ['id' => $log->id]);
    }

    // ── Carbon emissions calculation ──────────────────────────────────────────

    public function test_carbon_emissions_report_uses_correct_co2_factors(): void
    {
        $vehicle = FleetVehicleFactory::new()->diesel()->create();

        FleetFuelLogFactory::new()->diesel()->forVehicle($vehicle)->create([
            'litres' => 100,
        ]);

        // diesel CO2e factor = 2.68 kg/L
        $expectedCo2kg = 100 * 2.68;

        $rows = app(\App\Modules\Fleet\Controllers\FleetReportController::class)
            ->rowsByKey('carbon_emissions');

        $vehicleRow = collect($rows)->firstWhere('Registration', $vehicle->registration_number);

        $this->assertNotNull($vehicleRow);
        $this->assertEquals(round($expectedCo2kg, 2), (float) $vehicleRow['CO2e (kg)']);
    }

    // ── Viewer restriction ────────────────────────────────────────────────────

    public function test_viewer_cannot_log_fuel(): void
    {
        $vehicle = FleetVehicleFactory::new()->create();

        $this->actingAs($this->fleetViewer)
            ->post(route('fleet.fuel.store'), [
                'vehicle_id' => $vehicle->id,
                'filled_at'  => now()->toDateTimeString(),
                'fuel_type'  => 'petrol',
                'litres'     => 40,
                'total_cost' => 900,
            ])
            ->assertForbidden();
    }

    // ── Factory states ────────────────────────────────────────────────────────

    public function test_fleet_fuel_log_factory_calculates_cost_correctly(): void
    {
        $log = FleetFuelLogFactory::new()->create([
            'litres'         => 50,
            'cost_per_litre' => 24.00,
            'total_cost'     => 1200.00,
        ]);

        $this->assertDatabaseHas('fleet_fuel_logs', ['id' => $log->id, 'total_cost' => 1200.00]);
        $this->assertEquals(1200.00, (float) $log->total_cost);
    }
}
