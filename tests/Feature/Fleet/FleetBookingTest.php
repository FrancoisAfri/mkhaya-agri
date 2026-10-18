<?php

namespace Tests\Feature\Fleet;

use App\Modules\Fleet\Models\FleetBooking;
use App\Modules\Fleet\Models\FleetBookingApprovalConfig;
use Database\Factories\Fleet\FleetBookingFactory;
use Database\Factories\Fleet\FleetDriverFactory;
use Database\Factories\Fleet\FleetVehicleFactory;

class FleetBookingTest extends FleetTestCase
{
    // ── Create booking ────────────────────────────────────────────────────────

    public function test_driver_can_submit_a_booking_request(): void
    {
        $vehicle = FleetVehicleFactory::new()->available()->create();
        $driver  = FleetDriverFactory::new()->active()->create(['user_id' => $this->fleetDriver->id]);

        $response = $this->actingAs($this->fleetDriver)->post(route('fleet.bookings.store'), [
            'vehicle_id'      => $vehicle->id,
            'driver_id'       => $driver->id,
            'purpose'         => 'Client site visit in Sandton',
            'destination'     => 'Sandton',
            'departure_at'    => now()->addDays(2)->format('Y-m-d H:i:s'),
            'expected_return_at' => now()->addDays(4)->format('Y-m-d H:i:s'),
            'usage_type'      => 'business',
            'is_local_trip'   => '0',
            'passenger_count' => 2,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('fleet_bookings', [
            'vehicle_id'     => $vehicle->id,
            'workflow_state' => 'pending',
        ]);
    }

    public function test_booking_is_rejected_when_vehicle_is_already_booked(): void
    {
        $vehicle = FleetVehicleFactory::new()->available()->create();
        $driver  = FleetDriverFactory::new()->active()->create(['user_id' => $this->fleetDriver->id]);

        // First booking
        FleetBookingFactory::new()->approved()->create([
            'vehicle_id' => $vehicle->id,
            'departure_at' => now()->addDay(),
            'expected_return_at' => now()->addDays(5),
        ]);

        // Overlapping booking attempt
        $response = $this->actingAs($this->fleetDriver)->post(route('fleet.bookings.store'), [
            'vehicle_id'  => $vehicle->id,
            'driver_id'   => $driver->id,
            'purpose'     => 'Another trip',
            'destination' => 'Cape Town',
            'departure_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
            'expected_return_at' => now()->addDays(6)->format('Y-m-d H:i:s'),
            'usage_type'  => 'business',
            'is_local_trip' => '0',
        ]);

        $response->assertSessionHasErrors('vehicle_id');
    }

    // ── Approval workflow ─────────────────────────────────────────────────────

    public function test_manager_can_approve_pending_booking(): void
    {
        $booking = FleetBookingFactory::new()->pending()->create();

        $this->actingAs($this->fleetManager)
            ->patch(route('fleet.bookings.approve', $booking))
            ->assertRedirect();

        $this->assertDatabaseHas('fleet_bookings', [
            'id'             => $booking->id,
            'workflow_state' => 'approved',
        ]);
    }

    public function test_manager_can_bulk_approve_bookings(): void
    {
        $bookings = FleetBookingFactory::new()->pending()->count(3)->create();

        $this->actingAs($this->fleetManager)
            ->post(route('fleet.bookings.bulk-approve'), [
                'booking_ids' => $bookings->pluck('id')->toArray(),
            ])
            ->assertRedirect();

        foreach ($bookings as $booking) {
            $this->assertDatabaseHas('fleet_bookings', [
                'id'             => $booking->id,
                'workflow_state' => 'approved',
            ]);
        }
    }

    // ── Collection / Return ───────────────────────────────────────────────────

    public function test_manager_can_collect_an_approved_booking(): void
    {
        $booking = FleetBookingFactory::new()->approved()->create();

        $this->actingAs($this->fleetManager)
            ->post(route('fleet.bookings.collect', $booking), [
                'start_odometer'    => 45000,
                'collection_location'=> 'Head Office',
            ])
            ->assertRedirect();

        // `collected` is the state in this lifecycle — the keys are with the driver, and the
        // vehicle is not "in use" until security checks it out. The odometer is logged at return,
        // not at collection.
        $this->assertDatabaseHas('fleet_bookings', [
            'id'             => $booking->id,
            'workflow_state' => 'collected',
            'start_odometer' => 45000,
        ]);
    }

    public function test_manager_can_check_in_a_booking_and_record_km(): void
    {
        $booking = FleetBookingFactory::new()->inUse()->create([
            'start_odometer' => 50000,
        ]);

        $this->actingAs($this->fleetManager)
            ->patch(route('fleet.bookings.check-in', $booking), [
                'end_odometer'    => 50750,
                'fuel_level'      => 'half',
                'condition_notes' => 'Good condition',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('fleet_bookings', [
            'id'             => $booking->id,
            'workflow_state' => 'returned',
            'end_odometer'   => 50750,
            'km_travelled'   => 750,
        ]);
    }

    // ── Cancellation ──────────────────────────────────────────────────────────

    public function test_pending_booking_can_be_cancelled(): void
    {
        $booking = FleetBookingFactory::new()->pending()->create([
            'requester_id' => $this->fleetDriver->id,
        ]);

        $this->actingAs($this->fleetDriver)
            ->patch(route('fleet.bookings.cancel', $booking), [
                'cancellation_reason' => 'Trip no longer needed',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('fleet_bookings', [
            'id'             => $booking->id,
            'workflow_state' => 'cancelled',
        ]);
    }

    public function test_returned_booking_cannot_be_cancelled(): void
    {
        $booking = FleetBookingFactory::new()->returned()->create();

        $this->actingAs($this->fleetManager)
            ->patch(route('fleet.bookings.cancel', $booking), [
                'cancellation_reason' => 'Too late',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        // The trip happened. Cancelling it afterwards would rewrite a completed record that
        // already has odometer readings and fuel logged against it.
        $this->assertDatabaseHas('fleet_bookings', [
            'id' => $booking->id, 'workflow_state' => 'returned',
        ]);
    }

    // ── Tollgate & Incident ───────────────────────────────────────────────────

    public function test_tollgate_can_be_logged_on_active_booking(): void
    {
        $booking = FleetBookingFactory::new()->inUse()->create();

        $this->actingAs($this->fleetManager)
            ->post(route('fleet.bookings.tollgates.store', $booking), [
                'amount'       => 32.50,
                'toll_location'=> 'N1 Bakkies Toll',
                'toll_at'      => now()->toDateTimeString(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('fleet_booking_tollgates', [
            'booking_id'   => $booking->id,
            'amount'       => 32.50,
            'toll_location'=> 'N1 Bakkies Toll',
        ]);
    }

    public function test_incident_can_be_reported_on_active_booking(): void
    {
        $booking = FleetBookingFactory::new()->inUse()->create();

        $this->actingAs($this->fleetManager)
            ->post(route('fleet.bookings.incidents.store', $booking), [
                'incident_type' => 'accident',
                'severity'      => 'medium',
                'description'   => 'Minor collision at intersection',
                'incident_at'   => now()->toDateTimeString(),
                'location'      => 'Corner Main & 5th Ave',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('fleet_booking_incidents', [
            'booking_id'    => $booking->id,
            'incident_type' => 'accident',
            'severity'      => 'medium',
        ]);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function test_active_scope_excludes_returned_and_cancelled(): void
    {
        FleetBookingFactory::new()->pending()->count(2)->create();
        FleetBookingFactory::new()->approved()->count(1)->create();
        FleetBookingFactory::new()->returned()->count(3)->create();
        FleetBookingFactory::new()->cancelled()->count(1)->create();

        $active = FleetBooking::active()->get();

        $this->assertCount(3, $active);
    }
}
