<?php

namespace Tests\Feature\Fleet;

use App\Core\User\Models\User;
use App\Modules\Fleet\Models\FleetDriver;
use Database\Factories\Fleet\FleetDriverFactory;

class FleetDriverTest extends FleetTestCase
{
    // ── CRUD ─────────────────────────────────────────────────────────────────

    public function test_manager_can_register_a_driver(): void
    {
        $user = User::factory()->create(['status' => 1]);

        $this->actingAs($this->fleetManager)
            ->post(route('fleet.drivers.store'), [
                'user_id'        => $user->id,
                'licence_number' => 'DL12345678',
                'licence_type'   => 'EB',
                'licence_expiry' => now()->addYears(3)->toDateString(),
                'status'         => 'active',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('fleet_drivers', [
            'user_id'        => $user->id,
            'licence_number' => 'DL12345678',
            'status'         => 'active',
        ]);
    }

    public function test_manager_can_suspend_a_driver(): void
    {
        $driver = FleetDriverFactory::new()->active()->create();

        $this->actingAs($this->fleetManager)
            ->patch(route('fleet.drivers.update', $driver), [
                'status'             => 'suspended',
                'restriction_reason' => 'Pending disciplinary',
                'can_drive'          => false,
                // The update endpoint validates the whole record, so the fields that are
                // required have to travel even when they are not the ones changing.
                'licence_type'       => 'EB',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('fleet_drivers', [
            'id'     => $driver->id,
            'status' => 'suspended',
        ]);
    }

    public function test_manager_can_delete_driver(): void
    {
        $driver = FleetDriverFactory::new()->create();

        $this->actingAs($this->fleetManager)
            ->delete(route('fleet.drivers.destroy', $driver))
            ->assertRedirect();

        $this->assertSoftDeleted('fleet_drivers', ['id' => $driver->id]);
    }

    // ── Business logic ────────────────────────────────────────────────────────

    public function test_eligible_driver_passes_all_checks(): void
    {
        $driver = FleetDriverFactory::new()->active()->create([
            'can_drive'      => true,
            'licence_expiry' => now()->addYear()->toDateString(),
        ]);

        $this->assertTrue($driver->isEligible());
        $this->assertFalse($driver->isLicenceExpired());
    }

    public function test_suspended_driver_is_not_eligible(): void
    {
        $driver = FleetDriverFactory::new()->suspended()->create();

        $this->assertFalse($driver->isEligible());
    }

    public function test_driver_with_expired_licence_is_not_eligible(): void
    {
        $driver = FleetDriverFactory::new()->expiredLicence()->create([
            'can_drive' => true,
            'status'    => 'active',
        ]);

        $this->assertTrue($driver->isLicenceExpired());
        $this->assertFalse($driver->isEligible());
    }

    public function test_driver_with_can_drive_false_is_not_eligible(): void
    {
        $driver = FleetDriverFactory::new()->create([
            'can_drive'      => false,
            'status'         => 'active',
            'licence_expiry' => now()->addYear()->toDateString(),
        ]);

        $this->assertFalse($driver->isEligible());
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function test_active_scope_excludes_suspended_drivers(): void
    {
        FleetDriverFactory::new()->active()->count(3)->create();
        FleetDriverFactory::new()->suspended()->count(2)->create();

        $active = FleetDriver::active()->get();

        $this->assertCount(3, $active);
    }
}
