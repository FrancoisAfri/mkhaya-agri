<?php

namespace App\Modules\Fleet\Services;

use App\Modules\Fleet\Models\FleetBooking;
use App\Modules\Fleet\Models\FleetDriver;
use App\Modules\Fleet\Models\FleetVehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Mkhaya Move shuttle booking core. Auto-allocates an available vehicle and an
 * eligible driver for a requested trip and raises a Fleet booking in the normal
 * pending workflow. This is the in-process engine behind the Mkhaya Move API, so
 * callers (e.g. the Conferences travel desk) never touch Fleet internals.
 */
class MkhayaMoveShuttleService
{
    private const ACTIVE_STATES = ['pending', 'approved', 'collected', 'security_out', 'security_in'];

    /**
     * @param array{purpose?:string,destination:string,departure_at:string,expected_return_at:string,passenger_count?:int,requester_id?:int,notes?:string,reference?:string} $payload
     * @return array{reference:string,booking_id:int,status:string,vehicle:array,driver:array}
     */
    public function book(array $payload): array
    {
        foreach (['destination', 'departure_at', 'expected_return_at'] as $required) {
            if (blank($payload[$required] ?? null)) {
                throw new RuntimeException("Missing required field: {$required}.");
            }
        }

        return DB::transaction(function () use ($payload) {
            $vehicle = $this->allocateVehicle($payload['departure_at'], $payload['expected_return_at']);
            $driver = $this->allocateDriver($payload['departure_at'], $payload['expected_return_at']);

            $booking = FleetBooking::create([
                'reference'          => $payload['reference'] ?? 'MM-'.strtoupper(Str::random(10)),
                'vehicle_id'         => $vehicle->id,
                'driver_id'          => $driver->id,
                'requester_id'       => $payload['requester_id'] ?? null,
                'purpose'            => $payload['purpose'] ?? 'Shuttle transfer',
                'destination'        => $payload['destination'],
                'departure_at'       => $payload['departure_at'],
                'expected_return_at' => $payload['expected_return_at'],
                'usage_type'         => 'shuttle',
                'passenger_count'    => $payload['passenger_count'] ?? 1,
                'notes'              => $payload['notes'] ?? null,
                'workflow_state'     => 'pending',
                'created_by'         => $payload['requester_id'] ?? null,
            ]);

            return [
                'reference'  => $booking->reference,
                'booking_id' => $booking->id,
                'status'     => $booking->workflow_state,
                'vehicle'    => ['id' => $vehicle->id, 'registration' => $vehicle->registration_number],
                'driver'     => ['id' => $driver->id, 'licence' => $driver->licence_number],
            ];
        });
    }

    private function allocateVehicle(string $from, string $to): FleetVehicle
    {
        $busy = FleetBooking::whereIn('workflow_state', self::ACTIVE_STATES)
            ->where('departure_at', '<', $to)
            ->where('expected_return_at', '>', $from)
            ->pluck('vehicle_id')->filter()->all();

        $vehicle = FleetVehicle::where('status', 'available')
            ->whereNotIn('id', $busy)->orderBy('id')->first();

        if (! $vehicle) {
            throw new RuntimeException('No vehicle is available for the requested time.');
        }

        return $vehicle;
    }

    private function allocateDriver(string $from, string $to): FleetDriver
    {
        $busy = FleetBooking::whereIn('workflow_state', self::ACTIVE_STATES)
            ->where('departure_at', '<', $to)
            ->where('expected_return_at', '>', $from)
            ->pluck('driver_id')->filter()->all();

        $driver = FleetDriver::where('status', 'active')->where('can_drive', true)
            ->whereNotIn('id', $busy)->orderBy('id')->get()
            ->first(fn (FleetDriver $d) => $d->isEligible());

        if (! $driver) {
            throw new RuntimeException('No eligible driver is available for the requested time.');
        }

        return $driver;
    }
}
