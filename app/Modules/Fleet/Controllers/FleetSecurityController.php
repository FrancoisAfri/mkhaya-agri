<?php

namespace App\Modules\Fleet\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Fleet\Models\FleetBooking;
use App\Modules\Fleet\Models\FleetBookingInspection;
use App\Modules\Fleet\Models\FleetVehicle;
use App\Core\User\Trait\SafeNotifies;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class FleetSecurityController extends Controller
{
    use SafeNotifies;

    public function index(): Response
    {
        $pending = FleetBooking::whereIn('workflow_state', ['collected', 'security_out'])
            ->with([
                'vehicle:id,registration_number,fleet_number,make,model',
                'driver.user.person:id,user_id,first_name,surname',
                'requester.person:id,user_id,first_name,surname',
            ])
            ->orderBy('departure_at')
            ->get()
            ->map(fn ($b) => [
                'id'             => $b->id,
                'reference'      => $b->reference,
                'workflow_state' => $b->workflow_state,
                'vehicle'        => $b->vehicle ? "{$b->vehicle->registration_number} – {$b->vehicle->make} {$b->vehicle->model}" : '—',
                'vehicle_id'     => $b->vehicle_id,
                'driver'         => $this->personName($b->driver?->user),
                'requester'      => $this->personName($b->requester),
                'purpose'        => $b->purpose,
                'destination'    => $b->destination,
                'departure_at'   => $b->departure_at?->toDateTimeString(),
                'expected_return_at' => $b->expected_return_at?->toDateTimeString(),
                'is_local_trip'  => $b->is_local_trip,
                'start_odometer' => $b->start_odometer,
                'inspections'    => $b->inspections()->select('id', 'inspection_type', 'inspected_at', 'odometer_reading')->get(),
            ]);

        return Inertia::render('Modules/Fleet/SecurityCheck/Index', [
            'bookings' => $pending,
        ]);
    }

    public function storeInspection(Request $request, FleetBooking $booking): RedirectResponse
    {
        $isPreTrip = $request->input('inspection_type') === 'pre_trip';

        $data = $request->validate([
            'inspection_type'   => 'required|in:pre_trip,post_trip',
            'odometer_reading'  => $isPreTrip ? 'nullable|numeric|min:0' : 'required|numeric|min:0',
            'fuel_level'        => 'nullable|string|max:20',
            'condition_overall' => 'nullable|string|max:30',
            'lights_ok'         => 'boolean',
            'tyres_ok'          => 'boolean',
            'brakes_ok'         => 'boolean',
            'wipers_ok'         => 'boolean',
            'fluids_ok'         => 'boolean',
            'first_aid_ok'      => 'boolean',
            'fire_ext_ok'       => 'boolean',
            'spare_tyre_ok'     => 'boolean',
            'interior_ok'       => 'boolean',
            'notes'             => 'nullable|string|max:1000',
            'damage_markers'    => 'nullable|array',
            'signature'         => 'required|string',  // base64 canvas — driver must sign
            'photos'            => 'nullable|array',
        ]);

        // ── Pre-trip: block check-out if 2+ critical items are missing ────────
        if ($data['inspection_type'] === 'pre_trip') {
            $criticalLabels = [
                'spare_tyre_ok' => 'Spare wheel in the vehicle',
                'fire_ext_ok'   => 'Fire extinguisher',
                'lights_ok'     => 'Lights working',
                'tyres_ok'      => 'Tyre condition good',
                'first_aid_ok'  => 'First aid kit',
            ];
            $failed = [];
            foreach ($criticalLabels as $field => $label) {
                if (empty($data[$field])) {
                    $failed[] = $label;
                }
            }
            if (count($failed) >= 2) {
                $list = implode(', ', $failed);
                return back()
                    ->withInput()
                    ->with('error', "Car cannot check out — the following critical items are missing or not OK: {$list}.");
            }
        }

        // ── Post-trip: odometer must not be less than check-out odometer ──────
        if ($data['inspection_type'] === 'post_trip' && $booking->start_odometer !== null) {
            if ($data['odometer_reading'] < $booking->start_odometer) {
                return back()
                    ->withInput()
                    ->withErrors(['odometer_reading' => "Check-in odometer ({$data['odometer_reading']} km) cannot be less than check-out odometer ({$booking->start_odometer} km)."]);
            }
        }

        $odometerReading = $isPreTrip
            ? ($booking->start_odometer ?? $booking->vehicle?->odometer_reading)
            : $data['odometer_reading'];

        $inspection = FleetBookingInspection::create([
            'booking_id'       => $booking->id,
            'vehicle_id'       => $booking->vehicle_id,
            'inspection_type'  => $data['inspection_type'],
            'inspector_id'     => $request->user()->id,
            'inspected_at'     => now(),
            'odometer_reading' => $odometerReading,
            'fuel_level'       => $data['fuel_level'] ?? null,
            'condition_overall'=> $data['condition_overall'] ?? null,
            'lights_ok'        => $data['lights_ok'] ?? false,
            'tyres_ok'         => $data['tyres_ok'] ?? false,
            'brakes_ok'        => $data['brakes_ok'] ?? false,
            'wipers_ok'        => $data['wipers_ok'] ?? false,
            'fluids_ok'        => $data['fluids_ok'] ?? false,
            'first_aid_ok'     => $data['first_aid_ok'] ?? false,
            'fire_ext_ok'      => $data['fire_ext_ok'] ?? false,
            'spare_tyre_ok'    => $data['spare_tyre_ok'] ?? false,
            'interior_ok'      => $data['interior_ok'] ?? false,
            'notes'            => $data['notes'] ?? null,
            'damage_markers'   => json_encode($data['damage_markers'] ?? []),
            'signature_path'   => $data['signature'],   // base64 PNG from canvas
            'photos'           => json_encode($data['photos'] ?? []),
        ]);

        if ($data['inspection_type'] === 'pre_trip') {
            // Security out — car leaving the gate
            if ($odometerReading !== null) {
                FleetVehicle::where('id', $booking->vehicle_id)->update([
                    'odometer_reading' => $odometerReading,
                    'updated_at'       => now(),
                ]);
                DB::table('fleet_odometer_logs')->insert([
                    'vehicle_id'   => $booking->vehicle_id,
                    'reading'      => $odometerReading,
                    'reading_date' => now()->toDateString(),
                    'recorded_by'  => $request->user()->id,
                    'source'       => 'booking',
                    'notes'        => "Pre-trip security check-out for booking {$booking->reference}",
                    'created_by'   => $request->user()->id,
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);
            }
            $booking->update([
                'workflow_state'      => 'security_out',
                'security_checked_by' => $request->user()->id,
                'security_checked_at' => now(),
                'start_odometer'      => $odometerReading ?? $booking->start_odometer,
            ]);
        }

        if ($data['inspection_type'] === 'post_trip') {
            // Security in — car returned to gate; fleet manager still needs to confirm
            $km = max(0, $data['odometer_reading'] - ($booking->start_odometer ?? 0));
            $booking->update([
                'workflow_state'   => 'security_in',
                'actual_return_at' => now(),
                'end_odometer'     => $data['odometer_reading'],
                'km_travelled'     => $km,
            ]);
            FleetVehicle::where('id', $booking->vehicle_id)->update([
                'odometer_reading' => $data['odometer_reading'],
                'updated_at'       => now(),
            ]);
            DB::table('fleet_odometer_logs')->insert([
                'vehicle_id'   => $booking->vehicle_id,
                'reading'      => $data['odometer_reading'],
                'reading_date' => now()->toDateString(),
                'recorded_by'  => $request->user()->id,
                'source'       => 'booking',
                'notes'        => "Post-trip security check-in for booking {$booking->reference}",
                'created_by'   => $request->user()->id,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            // Alert fleet managers if anything differs from pre-trip
            $preTrip = FleetBookingInspection::where('booking_id', $booking->id)
                ->where('inspection_type', 'pre_trip')
                ->first();
            if ($preTrip && $this->hasDifferences($preTrip, $inspection)) {
                $fresh   = $booking->fresh();
                $context = $this->buildDiffContext($preTrip, $inspection, $fresh);
                $this->safeNotifyAll(
                    \App\Core\User\Models\User::role(['fleet_manager', 'administrator'])->get(),
                    new \App\Modules\Fleet\Notifications\FleetBookingStatusNotification($fresh, 'inspection_difference', null, $context)
                );
            }
        }

        return back()->with('success', ucfirst(str_replace('_', '-', $data['inspection_type'])) . ' inspection recorded.');
    }

    public function turnBack(Request $request, FleetBooking $booking): RedirectResponse
    {
        $request->validate(['reason' => 'required|string|max:500']);

        if ($booking->workflow_state !== 'collected') {
            return back()->with('error', 'Only bookings at the security gate (collected) can be turned back.');
        }

        $booking->update([
            'workflow_state' => 'approved',
            'notes'          => trim(($booking->notes ?? '') . "\n[Turn Back] " . now()->toDateTimeString() . ': ' . $request->reason),
        ]);

        // Notify fleet managers so they can collect the keys and investigate
        $this->safeNotifyAll(
            \App\Core\User\Models\User::role(['fleet_manager', 'administrator'])->get(),
            new \App\Modules\Fleet\Notifications\FleetBookingStatusNotification($booking->fresh(), 'turned_back', $request->reason)
        );

        return back()->with('success', 'Vehicle turned back. Fleet manager has been notified.');
    }

    private function buildDiffContext(FleetBookingInspection $pre, FleetBookingInspection $post, FleetBooking $booking): array
    {
        $diff = [];

        // Odometer
        if ($pre->odometer_reading !== null && $post->odometer_reading !== null) {
            $diff['odometer'] = [
                'check_out' => number_format($pre->odometer_reading, 0),
                'check_in'  => number_format($post->odometer_reading, 0),
                'km'        => number_format(max(0, $post->odometer_reading - $pre->odometer_reading), 0),
            ];
        }

        // Fuel level
        if ($pre->fuel_level !== $post->fuel_level) {
            $diff['fuel_level'] = ['check_out' => $pre->fuel_level, 'check_in' => $post->fuel_level];
        }

        // Condition downgrade
        $grades = ['excellent' => 4, 'good' => 3, 'fair' => 2, 'poor' => 1];
        if (($grades[$post->condition_overall] ?? 0) < ($grades[$pre->condition_overall] ?? 0)) {
            $diff['condition'] = ['check_out' => $pre->condition_overall, 'check_in' => $post->condition_overall];
        }

        // Equipment items that went from OK to NOT OK
        $equipment = [
            'lights_ok'     => 'Lights',
            'tyres_ok'      => 'Tyres',
            'brakes_ok'     => 'Brakes',
            'wipers_ok'     => 'Wipers',
            'fluids_ok'     => 'Fluids',
            'first_aid_ok'  => 'First Aid Kit',
            'fire_ext_ok'   => 'Fire Extinguisher',
            'spare_tyre_ok' => 'Spare Tyre',
            'interior_ok'   => 'Interior',
        ];
        $failed = [];
        foreach ($equipment as $field => $label) {
            if ($pre->$field && !$post->$field) {
                $failed[] = $label;
            }
        }
        if (!empty($failed)) {
            $diff['failed_equipment'] = $failed;
        }

        // New damage found at check-in
        $preDmg  = collect(json_decode($pre->damage_markers  ?? '[]', true))->pluck('label')->toArray();
        $postDmg = collect(json_decode($post->damage_markers ?? '[]', true))->pluck('label')->toArray();
        $newDmg  = array_values(array_diff($postDmg, $preDmg));
        if (!empty($newDmg)) {
            $diff['new_damage'] = $newDmg;
        }

        $driverPerson = $booking->driver?->user?->person;
        $driverName   = $driverPerson
            ? trim(($driverPerson->first_name ?? '') . ' ' . ($driverPerson->surname ?? ''))
            : ($booking->driver?->user?->email ?? 'Unknown');

        return [
            'diff'            => $diff,
            'driver'          => $driverName,
            'check_out_at'    => $pre->inspected_at?->format('d M Y H:i'),
            'check_in_at'     => $post->inspected_at?->format('d M Y H:i'),
            'post_trip_notes' => $post->notes,
        ];
    }

    private function hasDifferences(FleetBookingInspection $pre, FleetBookingInspection $post): bool
    {
        foreach (['lights_ok','tyres_ok','brakes_ok','wipers_ok','fluids_ok','first_aid_ok','fire_ext_ok','spare_tyre_ok','interior_ok'] as $f) {
            if ($pre->$f && !$post->$f) return true;
        }
        $grades = ['excellent' => 4, 'good' => 3, 'fair' => 2, 'poor' => 1];
        if (($grades[$post->condition_overall] ?? 0) < ($grades[$pre->condition_overall] ?? 0)) return true;
        $preDmg  = collect($pre->damage_markers  ?? [])->pluck('id')->sort()->values();
        $postDmg = collect($post->damage_markers ?? [])->pluck('id')->sort()->values();
        return $postDmg->diff($preDmg)->isNotEmpty();
    }

    private function personName(?\App\Core\User\Models\User $user): string
    {
        if (! $user) return '—';
        $p = $user->person ?? null;
        if (! $p) return $user->email ?? '—';
        return trim("{$p->first_name} {$p->surname}");
    }
}
