<?php

namespace Tests\Feature\Fleet;

use App\Modules\Fleet\Models\FleetMaintenance;
use Database\Factories\Fleet\FleetMaintenanceFactory;
use Database\Factories\Fleet\FleetVehicleFactory;

class FleetMaintenanceTest extends FleetTestCase
{
    // ── CRUD ─────────────────────────────────────────────────────────────────

    public function test_manager_can_schedule_maintenance(): void
    {
        $vehicle = FleetVehicleFactory::new()->create();

        $this->actingAs($this->fleetManager)
            ->post(route('fleet.maintenance.store'), [
                'vehicle_id'       => $vehicle->id,
                'maintenance_type' => 'scheduled',
                'category'         => 'oil_change',
                'description'      => '10 000 km oil and filter service',
                'scheduled_date'   => now()->addDays(14)->toDateString(),
                'status'           => 'scheduled',
                'priority'         => 'normal',
                'service_provider' => 'Toyota Service Centre',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('fleet_maintenance', [
            'vehicle_id'       => $vehicle->id,
            'category'         => 'oil_change',
            'status'           => 'scheduled',
        ]);
    }

    public function test_manager_can_mark_maintenance_as_completed(): void
    {
        $job = FleetMaintenanceFactory::new()->create();

        $this->actingAs($this->fleetManager)
            ->patch(route('fleet.maintenance.update', $job), [
                'status'           => 'completed',
                'completed_date'   => now()->toDateString(),
                'cost'             => 3200.00,
                'labour_cost'      => 1200.00,
                // Required by the update rules even when unchanged.
                'vehicle_id'       => $job->vehicle_id,
                'maintenance_type' => 'scheduled',
                'description'      => '10 000 km oil and filter service',
                'priority'         => 'normal',
                'parts_cost'     => 2000.00,
                'invoice_number' => 'INV-2026-001',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('fleet_maintenance', [
            'id'      => $job->id,
            'status'  => 'completed',
            'cost'    => 3200.00,
        ]);
    }

    public function test_manager_can_delete_maintenance_job(): void
    {
        $job = FleetMaintenanceFactory::new()->create();

        $this->actingAs($this->fleetManager)
            ->delete(route('fleet.maintenance.destroy', $job))
            ->assertRedirect();

        $this->assertSoftDeleted('fleet_maintenance', ['id' => $job->id]);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function test_overdue_scope_only_returns_past_scheduled_jobs(): void
    {
        FleetMaintenanceFactory::new()->overdue()->count(3)->create();
        FleetMaintenanceFactory::new()->create(['scheduled_date' => now()->addWeek()->toDateString()]);
        FleetMaintenanceFactory::new()->completed()->count(2)->create();

        $overdue = FleetMaintenance::overdue()->get();

        $this->assertCount(3, $overdue);
        $overdue->each(fn($m) => $this->assertEquals('scheduled', $m->status));
    }

    public function test_completed_scope_only_returns_completed_jobs(): void
    {
        FleetMaintenanceFactory::new()->completed()->count(4)->create();
        FleetMaintenanceFactory::new()->create();

        $completed = FleetMaintenance::completed()->get();

        $this->assertCount(4, $completed);
    }

    public function test_emergency_scope_filters_by_priority(): void
    {
        FleetMaintenanceFactory::new()->emergency()->count(2)->create();
        FleetMaintenanceFactory::new()->count(5)->create();

        $emergency = FleetMaintenance::emergency()->get();

        $this->assertCount(2, $emergency);
    }

    // ── Total cost accessor ───────────────────────────────────────────────────

    public function test_total_cost_accessor_sums_labour_and_parts(): void
    {
        $job = FleetMaintenanceFactory::new()->completed()->create([
            'labour_cost' => 1500.00,
            'parts_cost'  => 3500.00,
            'cost'        => 5000.00,
        ]);

        $this->assertEquals(5000.00, (float) $job->total_cost);
    }
}
