<?php

namespace Tests\Unit\Fleet;

use App\Modules\Fleet\Models\FleetDriver;
use Carbon\Carbon;
use Database\Factories\Fleet\FleetDriverFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FleetDriverEligibilityTest extends TestCase
{
    use RefreshDatabase;

    // ── isEligible ────────────────────────────────────────────────────────────

    public function test_active_driver_with_valid_licence_and_can_drive_is_eligible(): void
    {
        $driver = FleetDriverFactory::new()->create([
            'status'         => 'active',
            'can_drive'      => true,
            'licence_expiry' => now()->addYear()->toDateString(),
        ]);

        $this->assertTrue($driver->isEligible());
    }

    public function test_suspended_driver_is_not_eligible(): void
    {
        $driver = FleetDriverFactory::new()->create([
            'status'         => 'suspended',
            'can_drive'      => true,
            'licence_expiry' => now()->addYear()->toDateString(),
        ]);

        $this->assertFalse($driver->isEligible());
    }

    public function test_driver_with_can_drive_false_is_not_eligible(): void
    {
        $driver = FleetDriverFactory::new()->create([
            'status'         => 'active',
            'can_drive'      => false,
            'licence_expiry' => now()->addYear()->toDateString(),
        ]);

        $this->assertFalse($driver->isEligible());
    }

    public function test_driver_with_expired_licence_is_not_eligible(): void
    {
        $driver = FleetDriverFactory::new()->create([
            'status'         => 'active',
            'can_drive'      => true,
            'licence_expiry' => now()->subDay()->toDateString(),
        ]);

        $this->assertFalse($driver->isEligible());
    }

    // ── isLicenceExpired ──────────────────────────────────────────────────────

    public function test_future_licence_expiry_is_not_expired(): void
    {
        $driver = FleetDriverFactory::new()->create([
            'licence_expiry' => Carbon::now()->addMonths(6)->toDateString(),
        ]);

        $this->assertFalse($driver->isLicenceExpired());
    }

    public function test_past_licence_expiry_is_expired(): void
    {
        $driver = FleetDriverFactory::new()->create([
            'licence_expiry' => Carbon::now()->subDay()->toDateString(),
        ]);

        $this->assertTrue($driver->isLicenceExpired());
    }

    public function test_null_licence_expiry_is_not_considered_expired(): void
    {
        $driver = FleetDriverFactory::new()->create(['licence_expiry' => null]);

        $this->assertFalse($driver->isLicenceExpired());
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function test_active_scope_returns_only_active_drivers(): void
    {
        FleetDriverFactory::new()->create(['status' => 'active']);
        FleetDriverFactory::new()->create(['status' => 'active']);
        FleetDriverFactory::new()->create(['status' => 'suspended']);

        $this->assertCount(2, FleetDriver::query()->active()->get());
    }

    public function test_suspended_scope_returns_only_suspended_drivers(): void
    {
        FleetDriverFactory::new()->create(['status' => 'suspended']);
        FleetDriverFactory::new()->create(['status' => 'active']);

        $this->assertCount(1, FleetDriver::query()->suspended()->get());
    }
}
