<?php

namespace App\Modules\Fleet\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class FleetMyDashboardController extends Controller
{
    public function index(): Response
    {
        $userId = Auth::id();

        // Resolve driver record for this user
        $driver = DB::table('fleet_drivers')
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->first();

        $driverId = $driver?->id;

        // ── Stats ─────────────────────────────────────────────────────────────
        $totalTrips = $driverId
            ? DB::table('fleet_bookings')->where('driver_id', $driverId)->whereNull('deleted_at')->count()
            : 0;

        $totalKm = $driverId
            ? (float) DB::table('fleet_bookings')
                ->where('driver_id', $driverId)->whereNull('deleted_at')
                ->whereNotNull('km_travelled')->sum('km_travelled')
            : 0.0;

        $fuelStats = $driverId
            ? DB::table('fleet_fuel_logs')
                ->where('driver_id', $driverId)->whereNull('deleted_at')
                ->selectRaw('COALESCE(SUM(litres),0) as total_litres, COALESCE(SUM(total_cost),0) as total_cost')
                ->first()
            : null;

        $outstandingFines = $driverId
            ? (float) DB::table('fleet_fines')
                ->where('driver_id', $driverId)
                ->whereIn('status', ['unpaid', 'outstanding'])
                ->sum('outstanding_amount')
            : 0.0;

        $incidentCount = $driverId
            ? DB::table('fleet_booking_incidents as i')
                ->join('fleet_bookings as b', 'b.id', '=', 'i.booking_id')
                ->where('b.driver_id', $driverId)
                ->count()
            : 0;

        $stats = [
            'total_trips'       => $totalTrips,
            'total_km'          => round($totalKm, 1),
            'total_litres'      => round((float) ($fuelStats?->total_litres ?? 0), 1),
            'fuel_cost'         => round((float) ($fuelStats?->total_cost ?? 0), 2),
            'outstanding_fines' => round($outstandingFines, 2),
            'incident_count'    => $incidentCount,
        ];

        // ── Recent bookings (as requester OR driver) ──────────────────────────
        $recentBookings = DB::table('fleet_bookings as b')
            ->join('fleet_vehicles as v', 'v.id', '=', 'b.vehicle_id')
            ->leftJoin('fleet_drivers as fd', 'fd.id', '=', 'b.driver_id')
            ->leftJoin('users as du', 'du.id', '=', 'fd.user_id')
            ->leftJoin('hr_people as dp', 'dp.user_id', '=', 'du.id')
            ->where(function ($q) use ($userId, $driverId) {
                $q->where('b.requester_id', $userId);
                if ($driverId) {
                    $q->orWhere('b.driver_id', $driverId);
                }
            })
            ->whereNull('b.deleted_at')
            ->selectRaw("
                b.id, b.reference, b.workflow_state, b.purpose, b.destination,
                b.departure_at, b.expected_return_at, b.actual_return_at,
                b.km_travelled, b.usage_type, b.project,
                b.requester_id, b.passenger_count,
                v.registration_number, v.make, v.model,
                du.id as driver_user_id,
                TRIM(COALESCE(dp.first_name,'') || ' ' || COALESCE(dp.surname,'')) as driver_name,
                (SELECT fba.step_number
                    FROM fleet_booking_approvals fba
                    WHERE fba.booking_id = b.id AND fba.status = 'pending'
                    ORDER BY fba.step_number LIMIT 1) as current_step_number,
                (SELECT cfg.step_label
                    FROM fleet_booking_approvals fba2
                    JOIN fleet_booking_approval_configs cfg ON cfg.id = fba2.config_step_id
                    WHERE fba2.booking_id = b.id AND fba2.status = 'pending'
                    ORDER BY fba2.step_number LIMIT 1) as current_step_label,
                (SELECT COUNT(*) FROM fleet_booking_approvals fba3
                    WHERE fba3.booking_id = b.id) as total_steps
            ")
            ->orderByDesc('b.departure_at')
            ->limit(30)
            ->get()
            ->map(fn($r) => [
                'id'                  => $r->id,
                'reference'           => $r->reference,
                'workflow_state'      => $r->workflow_state,
                'purpose'             => $r->purpose,
                'destination'         => $r->destination,
                'departure_at'        => $r->departure_at ? Carbon::parse($r->departure_at)->format('d M Y H:i') : '—',
                'departure_raw'       => $r->departure_at,
                'return_at'           => ($r->actual_return_at ?? $r->expected_return_at)
                    ? Carbon::parse($r->actual_return_at ?? $r->expected_return_at)->format('d M Y H:i')
                    : '—',
                'km_travelled'        => $r->km_travelled,
                'usage_type'          => $r->usage_type,
                'project'             => $r->project,
                'passenger_count'     => (int) ($r->passenger_count ?? 0),
                'vehicle'             => trim("{$r->make} {$r->model} — {$r->registration_number}"),
                'requester_id'        => $r->requester_id,
                'driver_user_id'      => $r->driver_user_id,
                'driver_name'         => trim($r->driver_name ?? '') ?: null,
                'current_step_number' => $r->current_step_number,
                'current_step_label'  => $r->current_step_label,
                'total_steps'         => (int) $r->total_steps,
            ])
            ->values()->all();

        // ── This week's bookings (for calendar) ───────────────────────────────
        $weekStart = now()->startOfWeek(Carbon::MONDAY);
        $weekEnd   = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);

        $weekBookings = DB::table('fleet_bookings as b')
            ->join('fleet_vehicles as v', 'v.id', '=', 'b.vehicle_id')
            ->leftJoin('users as ru', 'ru.id', '=', 'b.requester_id')
            ->leftJoin('hr_people as rp', 'rp.user_id', '=', 'ru.id')
            ->where(function ($q) use ($userId, $driverId) {
                $q->where('b.requester_id', $userId);
                if ($driverId) {
                    $q->orWhere('b.driver_id', $driverId);
                }
            })
            ->whereNull('b.deleted_at')
            ->whereBetween('b.departure_at', [$weekStart->toDateTimeString(), $weekEnd->toDateTimeString()])
            ->whereIn('b.workflow_state', ['pending', 'approved', 'collected', 'security_out', 'security_in'])
            ->selectRaw("
                b.id, b.reference, b.workflow_state, b.purpose, b.destination,
                b.departure_at, b.expected_return_at, b.actual_return_at,
                v.registration_number, v.make, v.model,
                TRIM(COALESCE(rp.first_name,'') || ' ' || COALESCE(rp.surname,'')) as requester_name
            ")
            ->orderBy('b.departure_at')
            ->get()
            ->map(fn($r) => [
                'id'             => $r->id,
                'reference'      => $r->reference,
                'workflow_state' => $r->workflow_state,
                'purpose'        => $r->purpose,
                'destination'    => $r->destination,
                'departure_at'   => $r->departure_at,
                'departure_time' => Carbon::parse($r->departure_at)->format('H:i'),
                'return_at'      => $r->actual_return_at ?? $r->expected_return_at,
                'return_time'    => ($r->actual_return_at ?? $r->expected_return_at)
                    ? Carbon::parse($r->actual_return_at ?? $r->expected_return_at)->format('H:i')
                    : null,
                'vehicle'        => trim("{$r->make} {$r->model}"),
                'reg'            => $r->registration_number,
                'requester'      => trim($r->requester_name) ?: '—',
                'day'            => Carbon::parse($r->departure_at)->dayOfWeekIso, // 1=Mon … 7=Sun
            ])
            ->values()->all();

        // ── Incidents ─────────────────────────────────────────────────────────
        $incidents = $driverId
            ? DB::table('fleet_booking_incidents as i')
                ->join('fleet_bookings as b', 'b.id', '=', 'i.booking_id')
                ->join('fleet_vehicles as v', 'v.id', '=', 'i.vehicle_id')
                ->where('b.driver_id', $driverId)
                ->selectRaw("
                    i.id, i.incident_type, i.severity, i.description,
                    i.incident_at, i.location, i.police_case_number,
                    b.reference as booking_reference,
                    v.registration_number, v.make, v.model
                ")
                ->orderByDesc('i.incident_at')
                ->get()
                ->map(fn($r) => (array) $r)
                ->values()->all()
            : [];

        // ── Fines ─────────────────────────────────────────────────────────────
        $fines = $driverId
            ? DB::table('fleet_fines as f')
                ->join('fleet_vehicles as v', 'v.id', '=', 'f.vehicle_id')
                ->where('f.driver_id', $driverId)
                ->selectRaw("
                    f.id, f.fine_number, f.provider, f.offence_code,
                    f.offence_description, f.offence_date, f.location,
                    ROUND(f.amount::numeric,2) as amount,
                    ROUND(f.outstanding_amount::numeric,2) as outstanding_amount,
                    f.status, f.aarto_points, f.due_date, f.paid_at,
                    v.registration_number, v.make, v.model
                ")
                ->orderByDesc('f.offence_date')
                ->get()
                ->map(fn($r) => (array) $r)
                ->values()->all()
            : [];

        // ── Fuel logs ─────────────────────────────────────────────────────────
        $fuelLogs = $driverId
            ? DB::table('fleet_fuel_logs as f')
                ->join('fleet_vehicles as v', 'v.id', '=', 'f.vehicle_id')
                ->where('f.driver_id', $driverId)
                ->whereNull('f.deleted_at')
                ->selectRaw("
                    f.id, f.filled_at, f.fuel_type, f.litres,
                    ROUND(f.cost_per_litre::numeric,2) as cost_per_litre,
                    ROUND(f.total_cost::numeric,2) as total_cost,
                    f.odometer_reading, f.station_name, f.consumption_per_100km,
                    v.registration_number, v.make, v.model
                ")
                ->orderByDesc('f.filled_at')
                ->get()
                ->map(fn($r) => (array) $r)
                ->values()->all()
            : [];

        // ── Driver behaviour (telematics API) ─────────────────────────────────
        $behaviourIntegration = DB::table('fleet_integrations')
            ->where('integration_type', 'telematics')
            ->where('active', true)
            ->first();

        $behaviourRecords = [];
        if ($behaviourIntegration && $behaviourIntegration->api_endpoint && $driverId) {
            try {
                $response = \Illuminate\Support\Facades\Http::withHeaders([
                    'Authorization' => 'Bearer ' . $behaviourIntegration->api_key,
                ])->timeout(5)->get(rtrim($behaviourIntegration->api_endpoint, '/') . '/driver-behaviour', [
                    'driver_id' => $driverId,
                ]);

                if ($response->successful()) {
                    $behaviourRecords = $response->json('data', []);
                }
            } catch (\Throwable) {
                // API unavailable — silently degrade
            }
        }

        return Inertia::render('Modules/Fleet/MyDashboard/Index', [
            'driver'             => $driver ? [
                'id'             => $driver->id,
                'licence_number' => $driver->licence_number,
                'licence_type'   => $driver->licence_type,
                'licence_expiry' => $driver->licence_expiry,
                'pdp_number'     => $driver->pdp_number,
                'status'         => $driver->status,
                'total_trips'    => $driver->total_trips,
                'total_km'       => $driver->total_km,
                'incidents_count'=> $driver->incidents_count,
                'is_eligible'    => $driver->status === 'active'
                    && (bool) $driver->can_drive
                    && (!$driver->licence_expiry || $driver->licence_expiry > now()->toDateString()),
            ] : null,
            'stats'              => $stats,
            'recentBookings'     => $recentBookings,
            'weekBookings'       => $weekBookings,
            'weekStart'          => $weekStart->toDateString(),
            'incidents'          => $incidents,
            'fines'              => $fines,
            'fuelLogs'           => $fuelLogs,
            'behaviourRecords'   => $behaviourRecords,
            'hasBehaviourApi'    => (bool) $behaviourIntegration,
        ]);
    }
}
