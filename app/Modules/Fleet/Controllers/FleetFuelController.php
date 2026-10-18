<?php

namespace App\Modules\Fleet\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Fleet\Models\FleetDriver;
use App\Modules\Fleet\Models\FleetFuelLog;
use App\Modules\Fleet\Models\FleetOdometerLog;
use App\Modules\Fleet\Models\FleetVehicle;
use App\Modules\Fleet\Services\FleetPolicyGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class FleetFuelController extends Controller
{
    public function __construct(private readonly FleetPolicyGuard $policyGuard) {}

public function index(): Response
{
    $query = FleetFuelLog::with(['vehicle', 'driver.user'])
        ->orderByDesc('filled_at');

    $fuelLogs = $query->paginate(10);

    $items = $fuelLogs->through(fn($f) => [
        'id'                    => $f->id,
        'vehicle_id'            => $f->vehicle_id,
        'vehicle'               => $f->vehicle?->registration_number . ' ' . $f->vehicle?->make,
        'driver'                => $f->driver?->user?->name ?? $f->driver?->user?->email,
        'filled_at'             => $f->filled_at?->format('d M Y H:i'),
        'fuel_type'             => $f->fuel_type,
        'litres'                => $f->litres,
        'cost_per_litre'        => $f->cost_per_litre,
        'total_cost'            => $f->total_cost,
        'odometer_reading'      => $f->odometer_reading,
        'station_name'          => $f->station_name,
        'consumption_per_100km' => $f->consumption_per_100km,
        'full_tank'             => $f->full_tank,
    ]);

    $logs = [
        'data' => $items->items(),
        'meta' => [
            'current_page' => $fuelLogs->currentPage(),
            'last_page'    => $fuelLogs->lastPage(),
            'total'        => $fuelLogs->total(),
            'per_page'     => $fuelLogs->perPage(),
        ],
        'links' => [
            'prev' => $fuelLogs->previousPageUrl(),
            'next' => $fuelLogs->nextPageUrl(),
        ],
    ];

    $summary = [
        'total_cost'       => FleetFuelLog::whereNull('deleted_at')->sum('total_cost'),
        'total_litres'     => FleetFuelLog::whereNull('deleted_at')->sum('litres'),
        'avg_consumption'  => FleetFuelLog::whereNull('deleted_at')->whereNotNull('consumption_per_100km')->avg('consumption_per_100km'),
        'fills_this_month' => FleetFuelLog::whereNull('deleted_at')->whereMonth('filled_at', now()->month)->whereYear('filled_at', now()->year)->count(),
    ];

    $vehicles = FleetVehicle::whereNotIn('status', ['disposed'])
        ->orderBy('registration_number')
        ->get(['id', 'registration_number', 'make', 'model', 'fuel_type'])
        ->map(fn($v) => [
            'id'           => $v->id,
            'registration' => $v->registration_number,
            'make'         => $v->make,
            'model'        => $v->model,
        ]);

    return Inertia::render('Modules/Fleet/Fuel/Index', [
        'fuelLogs' => $logs,
        'summary'  => $summary,
        'vehicles' => $vehicles,
    ]);
}

public function store(Request $request): RedirectResponse
{
    $data = $request->validate([
        'vehicle_id'       => 'required|exists:fleet_vehicles,id',
        'driver_id'        => 'nullable|exists:fleet_drivers,id',
        'filled_at'        => 'required|date',
        'fuel_type'        => 'required|in:petrol,diesel,hybrid,electric,lpg',
        'litres'           => 'required|numeric|min:0.001',
        'cost_per_litre'   => 'nullable|numeric|min:0',
        'total_cost'       => 'required|numeric|min:0',
        'odometer_reading' => 'required|numeric|min:0',
        'station_name'     => 'nullable|string|max:100',
        'station_city'     => 'nullable|string|max:60',
        'full_tank'        => 'boolean',
        'notes'            => 'nullable|string',
    ]);

    $data['created_by'] = Auth::id();
    $data['full_tank']  = $request->boolean('full_tank');

    if (empty($data['total_cost']) && !empty($data['cost_per_litre'])) {
        $data['total_cost'] = round($data['litres'] * $data['cost_per_litre'], 2);
    }

    if (!empty($data['odometer_reading']) && $data['full_tank']) {
        $lastFull = FleetFuelLog::where('vehicle_id', $data['vehicle_id'])
            ->where('full_tank', true)
            ->whereNotNull('odometer_reading')
            ->orderByDesc('filled_at')
            ->first();

        if ($lastFull && $lastFull->odometer_reading > 0) {
            $kmSince = $data['odometer_reading'] - $lastFull->odometer_reading;
            if ($kmSince > 0 && $data['litres'] > 0) {
                $data['consumption_per_100km'] = round(($data['litres'] / $kmSince) * 100, 2);
            }
        }
    }

    FleetFuelLog::create($data);

    if (!empty($data['odometer_reading'])) {
        FleetOdometerLog::create([
            'vehicle_id'   => $data['vehicle_id'],
            'reading'      => $data['odometer_reading'],
            'reading_date' => now()->toDateString(),
            'recorded_by'  => Auth::id(),
            'source'       => 'fuel_log',
            'created_by'   => Auth::id(),
        ]);
        FleetVehicle::find($data['vehicle_id'])?->update(['odometer_reading' => $data['odometer_reading']]);
    }

    return redirect()->back()->with('success', 'Fuel log recorded successfully.');
}



    public function destroy(FleetFuelLog $fuel): RedirectResponse
    {
        $fuel->update(['updated_by' => Auth::id()]);
        $fuel->delete();
        return back()->with('success', 'Fuel log deleted.');
    }
}
