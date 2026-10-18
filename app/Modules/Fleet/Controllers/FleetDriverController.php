<?php

namespace App\Modules\Fleet\Controllers;

use App\Http\Controllers\Controller;
use App\Core\User\Models\User;
use App\Modules\Fleet\Models\FleetDriver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class FleetDriverController extends Controller
{
    public function index(): Response
    {
        $drivers = FleetDriver::with('user.person')
            ->withCount('bookings')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn($d) => [
                'id'               => $d->id,
                'user_id'          => $d->user_id,
                'name'             => trim(($d->user?->person?->first_name ?? '') . ' ' . ($d->user?->person?->surname ?? '')) ?: ($d->user?->email ?? ''),
                'email'            => $d->user?->email,
                'licence_number'   => $d->licence_number,
                'licence_type'     => $d->licence_type,
                'licence_expiry'   => $d->licence_expiry?->format('Y-m-d'),
                'pdp_number'       => $d->pdp_number,
                'pdp_expiry'       => $d->pdp_expiry?->format('Y-m-d'),
                'status'           => $d->status,
                'restriction_reason' => $d->restriction_reason,
                'total_trips'      => $d->total_trips,
                'total_km'         => $d->total_km,
                'incidents_count'  => $d->incidents_count,
                'bookings_count'   => $d->bookings_count,
                'licence_expired'  => $d->isLicenceExpired(),
            ]);

        // All HR people with their eligible_to_drive flag for admin management
        $hrPeople = DB::table('hr_people as p')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->selectRaw("p.id, p.user_id, TRIM(COALESCE(p.first_name,'') || ' ' || COALESCE(p.surname,'')) as name, u.email, p.eligible_to_drive, p.drivers_licence_number, p.drivers_licence_code")
            ->orderByRaw("TRIM(COALESCE(p.first_name,'') || ' ' || COALESCE(p.surname,''))")
            ->get()
            ->map(fn($r) => (array)$r);

        // Only eligible people not yet fleet drivers can be added
        $eligiblePersonIds = collect($hrPeople)->where('eligible_to_drive', true)->pluck('user_id');

        $users = User::whereDoesntHave('fleetDriver')
            ->whereIn('id', $eligiblePersonIds)
            ->with('person')
            ->get(['id', 'email'])
            ->map(fn($u) => [
                'id'             => $u->id,
                'name'           => trim(($u->person?->first_name ?? '') . ' ' . ($u->person?->surname ?? '')) ?: $u->email,
                'licence_number' => $u->person?->drivers_licence_number,
                'licence_code'   => $u->person?->drivers_licence_code,
            ]);

        return Inertia::render('Modules/Fleet/Drivers/Index', [
            'drivers'  => $drivers,
            'users'    => $users,
            'hrPeople' => $hrPeople,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id'          => 'required|exists:users,id|unique:fleet_drivers',
            'licence_number'   => 'nullable|string|max:30',
            'licence_type'     => 'required|in:A,B,C,C1,EB,PrDP',
            'licence_expiry'   => 'nullable|date',
            'pdp_number'       => 'nullable|string|max:30',
            'pdp_expiry'       => 'nullable|date',
            'status'           => 'required|in:active,suspended,inactive',
        ]);

        $data['uuid']       = (string) Str::uuid();
        $data['created_by'] = Auth::id();

        FleetDriver::create($data);

        return back()->with('success', 'Driver profile created.');
    }

    public function update(Request $request, FleetDriver $driver): RedirectResponse
    {
        $data = $request->validate([
            'licence_number'     => 'nullable|string|max:30',
            'licence_type'       => 'required|in:A,B,C,C1,EB,PrDP',
            'licence_expiry'     => 'nullable|date',
            'pdp_number'         => 'nullable|string|max:30',
            'pdp_expiry'         => 'nullable|date',
            'status'             => 'required|in:active,suspended,inactive',
            'restriction_reason' => 'nullable|string',
        ]);

        $data['updated_by'] = Auth::id();
        $driver->update($data);

        return back()->with('success', 'Driver profile updated.');
    }

    public function destroy(FleetDriver $driver): RedirectResponse
    {
        $driver->update(['updated_by' => Auth::id()]);
        $driver->delete();
        return back()->with('success', 'Driver profile removed.');
    }

    public function toggleEligible(Request $request, int $personId): RedirectResponse
    {
        $data = $request->validate(['eligible' => 'required|boolean']);
        DB::table('hr_people')->where('id', $personId)->update(['eligible_to_drive' => $data['eligible']]);
        return back()->with('success', $data['eligible'] ? 'Marked as eligible to drive.' : 'Eligibility removed.');
    }
}
