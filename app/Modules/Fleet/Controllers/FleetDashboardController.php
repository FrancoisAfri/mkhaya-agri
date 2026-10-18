<?php

namespace App\Modules\Fleet\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Fleet\Models\FleetBooking;
use App\Modules\Fleet\Models\FleetDocument;
use App\Modules\Fleet\Models\FleetMaintenance;
use App\Modules\Fleet\Models\FleetVehicle;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class FleetDashboardController extends Controller
{
    public function index(): Response
    {
        $userId = Auth::id();
        $now    = now();
        $weekStart = $now->copy()->startOfWeek();
        $weekEnd   = $now->copy()->endOfWeek();

        // ── Personal bookings ─────────────────────────────────────────────────
        $myBookings = FleetBooking::with(['vehicle', 'driver.user.person'])
            ->where('requester_id', $userId)
            ->orderByDesc('departure_at')
            ->limit(20)
            ->get()
            ->map(fn($b) => [
                'id'             => $b->id,
                'reference'      => $b->reference,
                'vehicle'        => $b->vehicle?->registration_number,
                'vehicle_id'     => $b->vehicle_id,
                'vehicle_uuid'   => $b->vehicle?->uuid,
                'destination'    => $b->destination,
                'departure_at'   => $b->departure_at?->format('d M Y H:i'),
                'return_at'      => $b->expected_return_at?->format('d M Y H:i'),
                'workflow_state' => $b->workflow_state,
                'project'        => $b->project,
                'purpose'        => $b->purpose,
            ]);

        $mySummary = [
            'pending'  => FleetBooking::where('requester_id', $userId)->where('workflow_state', 'pending')->count(),
            'approved' => FleetBooking::where('requester_id', $userId)->whereIn('workflow_state', ['approved', 'collected'])->count(),
            'in_use'   => FleetBooking::where('requester_id', $userId)->whereIn('workflow_state', ['security_out', 'security_in'])->count(),
            'ytd'      => FleetBooking::where('requester_id', $userId)->whereIn('workflow_state', ['returned'])->whereYear('departure_at', $now->year)->count(),
        ];

        // ── Current week calendar (all bookings for this week) ────────────────
        $weekBookings = FleetBooking::with(['vehicle', 'driver.user.person'])
            ->whereBetween('departure_at', [$weekStart, $weekEnd])
            ->whereNotIn('workflow_state', ['cancelled'])
            ->orderBy('departure_at')
            ->get()
            ->map(fn($b) => [
                'id'             => $b->id,
                'reference'      => $b->reference,
                'vehicle'        => $b->vehicle?->registration_number,
                'vehicle_id'     => $b->vehicle_id,
                'vehicle_uuid'   => $b->vehicle?->uuid,
                'driver'         => trim(($b->driver?->user?->person?->first_name ?? '') . ' ' . ($b->driver?->user?->person?->surname ?? '')) ?: ($b->driver?->user?->email ?? ''),
                'destination'    => $b->destination,
                'departure_at'   => $b->departure_at?->toIso8601String(),
                'return_at'      => $b->expected_return_at?->toIso8601String(),
                'workflow_state' => $b->workflow_state,
                'is_mine'        => $b->requester_id === $userId,
            ]);

        // ── Fleet-wide stats (visible to all, useful context) ─────────────────
        $fleetStats = [
            'total_vehicles'   => FleetVehicle::count(),
            'available'        => FleetVehicle::where('status', 'available')->count(),
            'pending_approval' => FleetVehicle::where('approval_status', 'pending')->count(),
            'alerts'           => DB::table('fleet_booking_incidents')->count()
                                + FleetDocument::expiringSoon(30)->count()
                                + FleetMaintenance::overdue()->count(),
        ];

        return Inertia::render('Modules/Fleet/Dashboard/Index', [
            'myBookings'  => $myBookings,
            'mySummary'   => $mySummary,
            'weekBookings'=> $weekBookings,
            'weekStart'   => $weekStart->toDateString(),
            'weekEnd'     => $weekEnd->toDateString(),
            'fleetStats'  => $fleetStats,
        ]);
    }
}
