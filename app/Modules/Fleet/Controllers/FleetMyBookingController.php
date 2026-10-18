<?php

namespace App\Modules\Fleet\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Fleet\Models\FleetBookingApprovalConfig;
use App\Modules\Fleet\Models\FleetDriver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class FleetMyBookingController extends Controller
{
    public function create(): Response
    {
        $userId    = Auth::id();
        $isManager = Auth::user()->hasAnyRole(['fleet_manager', 'administrator', 'super_admin']);

        // Current user's driver record
        $driver = FleetDriver::where('user_id', $userId)
            ->whereNull('deleted_at')
            ->first();

        $isEligible = $driver?->isEligible() ?? false;

        // All active eligible drivers (managers can pick a different driver)
        $drivers = $isManager
            ? FleetDriver::where('status', 'active')
                ->where('can_drive', true)
                ->with('user.person')
                ->get()
                ->map(fn($d) => [
                    'id'      => $d->id,
                    'user_id' => $d->user_id,
                    'name'    => trim(
                        (optional($d->user->person)->first_name ?? '') . ' ' .
                        (optional($d->user->person)->surname ?? '')
                    ) ?: ($d->user->email ?? ''),
                    'licence_type'   => $d->licence_type,
                    'licence_expiry' => $d->licence_expiry?->format('Y-m-d'),
                    'is_eligible'    => $d->isEligible(),
                ])
                ->values()
            : collect();

        // Self-driver entry for non-managers
        $selfDriver = $driver ? [
            'id'           => $driver->id,
            'user_id'      => $userId,
            'name'         => trim(
                (optional(Auth::user()->person)->first_name ?? '') . ' ' .
                (optional(Auth::user()->person)->surname ?? '')
            ) ?: Auth::user()->email,
            'licence_type'   => $driver->licence_type,
            'licence_expiry' => $driver->licence_expiry?->format('Y-m-d'),
            'is_eligible'    => $isEligible,
        ] : null;

        // Employees list for passenger selection (exclude self)
        $employees = DB::table('hr_people as p')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('u.id', '!=', $userId)
            ->orderByRaw("TRIM(COALESCE(p.first_name,'') || ' ' || COALESCE(p.surname,''))")
            ->selectRaw("u.id as user_id, TRIM(COALESCE(p.first_name,'') || ' ' || COALESCE(p.surname,'')) as name")
            ->get();

        // Projects
        $projects = DB::table('fleet_projects')
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        // Approval steps (for info display)
        $approvalSteps = FleetBookingApprovalConfig::where('is_active', true)
            ->where('step_number', '>', 0)
            ->orderBy('step_number')
            ->get(['step_number', 'step_label', 'approver_type']);

        return Inertia::render('Modules/Fleet/MyBookings/Create', [
            'driver'        => $selfDriver,
            'isEligible'    => $isEligible,
            'isManager'     => $isManager,
            'selfDriverId'  => $driver?->id,
            'drivers'       => $drivers,
            'employees'     => $employees,
            'vehicleTypes'  => ['sedan', 'suv', 'truck', 'van', 'motorcycle', 'bus', 'other'],
            'usageTypes'    => ['official', 'local', 'personal', 'business'],
            'projects'      => $projects,
            'approvalSteps' => $approvalSteps,
        ]);
    }
}
