<?php

namespace Tests\Feature\Fleet;

use Database\Factories\Fleet\FleetDocumentFactory;
use Database\Factories\Fleet\FleetVehicleFactory;
use App\Modules\Fleet\Models\FleetDocument;
use App\Modules\Fleet\Models\FleetVehicle;

class FleetVehicleTest extends FleetTestCase
{
    // ── CRUD ─────────────────────────────────────────────────────────────────

    public function test_manager_can_register_a_vehicle(): void
    {
        $response = $this->actingAs($this->fleetManager)->post(route('fleet.vehicles.store'), [
            'registration_number' => 'CA-123-GP',
            'make'                => 'Toyota',
            'model'               => 'Hilux',
            'year'                => 2022,
            'vehicle_type'        => 'bakkie',
            'fuel_type'           => 'diesel',
            'status'              => 'available',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('fleet_vehicles', [
            'registration_number' => 'CA-123-GP',
            'make'                => 'Toyota',
        ]);
    }

    public function test_equipment_needs_no_number_plate_but_a_vehicle_does(): void
    {
        $this->actingAs($this->fleetManager)->post(route('fleet.vehicles.store'), [
            'asset_class' => 'equipment', 'name' => 'John Deere 6120M', 'fleet_number' => 'T-01',
            'vehicle_type' => 'tractor', 'fuel_type' => 'diesel', 'hours_reading' => 1200, 'status' => 'available',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('fleet_vehicles', ['fleet_number' => 'T-01', 'asset_class' => 'equipment', 'registration_number' => null, 'name' => 'John Deere 6120M']);

        $this->actingAs($this->fleetManager)->post(route('fleet.vehicles.store'), [
            'asset_class' => 'vehicle', 'make' => 'Toyota', 'vehicle_type' => 'bakkie', 'status' => 'available',
        ])->assertSessionHasErrors('registration_number');

        $this->actingAs($this->fleetManager)->get(route('fleet.vehicles.index', ['asset_class' => 'equipment']))->assertOk()
            ->assertInertia(fn ($page) => $page->where('vehicles.data.0.fleet_number', 'T-01')->where('vehicles.data.0.asset_class', 'equipment')->has('vehicles.data', 1));
    }

    public function test_duplicate_registration_number_is_rejected(): void
    {
        FleetVehicleFactory::new()->create(['registration_number' => 'DUP-001-GP']);

        $response = $this->actingAs($this->fleetManager)->post(route('fleet.vehicles.store'), [
            'registration_number' => 'DUP-001-GP',
            'make'                => 'Ford',
            'model'               => 'Ranger',
            'vehicle_type'        => 'bakkie',
            'fuel_type'           => 'diesel',
        ]);

        $response->assertSessionHasErrors('registration_number');
    }

    public function test_manager_can_update_vehicle_details(): void
    {
        $vehicle = FleetVehicleFactory::new()->create();

        $this->actingAs($this->fleetManager)
            ->patch(route('fleet.vehicles.update', $vehicle), [
                'registration_number' => $vehicle->registration_number,
                'make'                => 'Nissan',
                'model'               => 'Navara',
                'vehicle_type'        => 'bakkie',
                'fuel_type'           => 'diesel',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('fleet_vehicles', ['id' => $vehicle->id, 'make' => 'Nissan']);
    }

    public function test_manager_can_delete_vehicle(): void
    {
        $vehicle = FleetVehicleFactory::new()->create();

        $this->actingAs($this->fleetManager)
            ->delete(route('fleet.vehicles.destroy', $vehicle))
            ->assertRedirect();

        $this->assertSoftDeleted('fleet_vehicles', ['id' => $vehicle->id]);
    }

    // ── Status transitions ────────────────────────────────────────────────────

    public function test_vehicle_status_changes_to_maintenance(): void
    {
        $vehicle = FleetVehicleFactory::new()->available()->create();

        $this->actingAs($this->fleetManager)
            ->patch(route('fleet.vehicles.change-status', $vehicle), [
                'status' => 'maintenance',
                'reason' => 'Scheduled service',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('fleet_vehicles', ['id' => $vehicle->id, 'status' => 'maintenance']);
        $this->assertDatabaseHas('fleet_vehicle_status_changes', [
            'vehicle_id' => $vehicle->id,
            'to_status'  => 'maintenance',
        ]);
    }

    public function test_disposal_records_reason_and_sets_disposed_status(): void
    {
        $vehicle = FleetVehicleFactory::new()->available()->create();

        $this->actingAs($this->fleetManager)
            ->post(route('fleet.vehicles.dispose', $vehicle), [
                'disposal_type'   => 'sold',
                'disposal_date'   => now()->toDateString(),
                'disposal_reason' => 'End of fleet life cycle',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('fleet_vehicles', [
            'id'             => $vehicle->id,
            'status'         => 'disposed',
            'disposal_type'  => 'sold',
        ]);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function test_available_scope_returns_only_available_vehicles(): void
    {
        FleetVehicleFactory::new()->available()->count(3)->create();
        FleetVehicleFactory::new()->booked()->count(2)->create();
        FleetVehicleFactory::new()->inMaintenance()->count(1)->create();

        $available = FleetVehicle::available()->get();

        $this->assertCount(3, $available);
        $available->each(fn($v) => $this->assertEquals('available', $v->status));
    }

    // ── Document expiry ───────────────────────────────────────────────────────

    public function test_expiring_soon_scope_returns_documents_within_threshold(): void
    {
        $vehicle = FleetVehicleFactory::new()->create();

        FleetDocumentFactory::new()->expiringSoon(20)->create(['vehicle_id' => $vehicle->id]);
        FleetDocumentFactory::new()->expiringSoon(45)->create(['vehicle_id' => $vehicle->id]);
        FleetDocumentFactory::new()->create([
            'vehicle_id'  => $vehicle->id,
            'expiry_date' => now()->addDays(120)->toDateString(),
        ]);

        $expiring = FleetDocument::expiringSoon(30)->get();

        $this->assertCount(1, $expiring);
    }

    // ── Authorization ─────────────────────────────────────────────────────────

    public function test_viewer_cannot_register_vehicle(): void
    {
        $this->actingAs($this->fleetViewer)
            ->post(route('fleet.vehicles.store'), [
                'registration_number' => 'VW-999-ZN',
                'make'                => 'VW',
                'model'               => 'Polo',
                'vehicle_type'        => 'sedan',
                'fuel_type'           => 'petrol',
            ])
            ->assertForbidden();
    }

    public function test_unauthenticated_user_cannot_access_vehicles(): void
    {
        $this->get(route('fleet.vehicles.index'))->assertRedirect(route('login'));
    }
}
