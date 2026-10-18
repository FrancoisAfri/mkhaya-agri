<?php

namespace App\Modules\Fleet\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class FleetAnalyticsController extends Controller
{
    public function index(Request $request): Response
    {
        // ── Date range ────────────────────────────────────────────────────────
        if ($request->filled('date_from') && $request->filled('date_to')) {
            $from = now()->parse($request->input('date_from'))->startOfDay();
            $to   = now()->parse($request->input('date_to'))->endOfDay();
        } else {
            [$from, $to] = $this->periodRange($request->input('period', 'year'));
        }

        // ── Active filters ────────────────────────────────────────────────────
        $filters = [
            'vehicle_type' => $request->input('vehicle_type'),    // string|null
            'fuel_type'    => $request->input('fuel_type'),       // string|null
            'department_id'=> $request->input('department_id'),   // int|null
            'vehicle_id'   => $request->input('vehicle_id'),      // int|null
            'driver_id'    => $request->input('driver_id'),       // int|null
        ];

        // ── Filter option lists (for the dropdowns) ───────────────────────────
        $vehicleQuery = DB::table('fleet_vehicles')->whereNull('deleted_at');
        if ($filters['vehicle_type'])  $vehicleQuery->where('vehicle_type', $filters['vehicle_type']);
        if ($filters['fuel_type'])     $vehicleQuery->where('fuel_type', $filters['fuel_type']);
        if ($filters['department_id']) $vehicleQuery->where('department_id', $filters['department_id']);

        $filterOptions = [
            'vehicle_types' => DB::table('fleet_vehicles')->whereNull('deleted_at')
                ->distinct()->pluck('vehicle_type')->filter()->sort()->values()->toArray(),
            'fuel_types'    => DB::table('fleet_vehicles')->whereNull('deleted_at')
                ->distinct()->pluck('fuel_type')->filter()->sort()->values()->toArray(),
            'departments'   => DB::table('company_units')
                ->where('type', 'department')
                ->whereIn('id', DB::table('fleet_vehicles')->whereNull('deleted_at')->whereNotNull('department_id')->pluck('department_id'))
                ->select('id', 'name')
                ->orderBy('name')
                ->get()
                ->map(fn($r) => ['id' => $r->id, 'name' => $r->name])
                ->toArray(),
            'vehicles'      => DB::table('fleet_vehicles')->whereNull('deleted_at')
                ->select('id', 'registration_number', 'make', 'model')
                ->orderBy('registration_number')
                ->get()
                ->map(fn($r) => ['id' => $r->id, 'label' => trim("$r->registration_number – $r->make $r->model")])
                ->toArray(),
            'drivers'       => DB::table('fleet_drivers as d')
                ->join('users as u', 'u.id', '=', 'd.user_id')
                ->join('hr_people as p', 'p.user_id', '=', 'u.id')
                ->whereNull('d.deleted_at')
                ->selectRaw("d.id, CONCAT(p.first_name, ' ', p.surname) as name")
                ->orderBy('name')
                ->get()
                ->map(fn($r) => ['id' => $r->id, 'name' => $r->name])
                ->toArray(),
        ];

        return Inertia::render('Modules/Fleet/Analytics/Index', [
            'period'               => $request->input('period', 'year'),
            'dateFrom'             => $request->input('date_from'),
            'dateTo'               => $request->input('date_to'),
            'filters'              => $filters,
            'filterOptions'        => $filterOptions,
            'kpis'                 => $this->kpis($from, $to, $filters),
            'vehicleStatus'        => $this->vehicleStatus($filters),
            'bookingTrend'         => $this->bookingTrend($from, $to, $filters),
            'fuelTrend'            => $this->fuelTrend($from, $to, $filters),
            'fuelByVehicleType'    => $this->fuelByVehicleType($from, $to, $filters),
            'maintenanceByCategory'=> $this->maintenanceByCategory($from, $to, $filters),
            'maintenanceByStatus'  => $this->maintenanceByStatus($from, $to, $filters),
            'finesByStatus'        => $this->finesByStatus($from, $to, $filters),
            'finesByAuthority'     => $this->finesByAuthority($from, $to, $filters),
            'costBreakdown'        => $this->costBreakdown($from, $to, $filters),
            'topVehicles'          => $this->topVehicles($from, $to, $filters),
            'topDrivers'           => $this->topDrivers($from, $to, $filters),
        ]);
    }

    // ── Period helper ─────────────────────────────────────────────────────────

    private function periodRange(string $period): array
    {
        $now = now();
        return match ($period) {
            'month'    => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'quarter'  => [$now->copy()->subMonths(2)->startOfMonth(), $now->copy()->endOfMonth()],
            'halfyear' => [$now->copy()->subMonths(5)->startOfMonth(), $now->copy()->endOfMonth()],
            'lastyear' => [$now->copy()->subYear()->startOfYear(), $now->copy()->subYear()->endOfYear()],
            default    => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
        };
    }

    // ── Shared filter applicators ─────────────────────────────────────────────

    /** Apply vehicle-level filters to a query that has fleet_vehicles joined as 'v'. */
    private function applyVehicleFilters($query, array $f): void
    {
        if ($f['vehicle_type'])   $query->where('v.vehicle_type', $f['vehicle_type']);
        if ($f['fuel_type'])      $query->where('v.fuel_type', $f['fuel_type']);
        if ($f['department_id'])  $query->where('v.department_id', $f['department_id']);
        if ($f['vehicle_id'])     $query->where('v.id', $f['vehicle_id']);
    }

    /** Return a subquery of vehicle_ids matching active vehicle-level filters. */
    private function filteredVehicleIds(array $f): ?array
    {
        if (!$f['vehicle_type'] && !$f['fuel_type'] && !$f['department_id'] && !$f['vehicle_id']) {
            return null; // no vehicle filter active
        }
        $q = DB::table('fleet_vehicles')->whereNull('deleted_at');
        if ($f['vehicle_type'])   $q->where('vehicle_type', $f['vehicle_type']);
        if ($f['fuel_type'])      $q->where('fuel_type', $f['fuel_type']);
        if ($f['department_id'])  $q->where('department_id', $f['department_id']);
        if ($f['vehicle_id'])     $q->where('id', $f['vehicle_id']);
        return $q->pluck('id')->toArray();
    }

    // ── KPI cards ─────────────────────────────────────────────────────────────

    private function kpis($from, $to, array $f): array
    {
        $vIds = $this->filteredVehicleIds($f);

        $vehicleQ = DB::table('fleet_vehicles')->whereNull('deleted_at');
        if ($vIds !== null) $vehicleQ->whereIn('id', $vIds);

        $totalVehicles = $vehicleQ->count();
        $inUse         = (clone $vehicleQ)->where('status', 'booked')->count();

        $bookingQ = DB::table('fleet_bookings')->whereBetween('departure_at', [$from, $to]);
        if ($vIds !== null)   $bookingQ->whereIn('vehicle_id', $vIds);
        if ($f['driver_id'])  $bookingQ->where('driver_id', $f['driver_id']);

        $bookings          = (clone $bookingQ)->whereNotIn('workflow_state', ['cancelled', 'rejected', 'turned_back'])->count();
        $completedBookings = (clone $bookingQ)->where('workflow_state', 'returned')->count();
        $totalKm           = (clone $bookingQ)->where('workflow_state', 'returned')->sum('km_travelled') ?? 0;

        $fuelQ = DB::table('fleet_fuel_logs')->whereNull('deleted_at')->whereBetween('filled_at', [$from, $to]);
        if ($vIds !== null)   $fuelQ->whereIn('vehicle_id', $vIds);
        if ($f['driver_id'])  $fuelQ->where('driver_id', $f['driver_id']);
        $fuelData = $fuelQ->selectRaw('COALESCE(SUM(total_cost),0) as cost, COALESCE(SUM(litres),0) as litres')->first();

        $maintQ = DB::table('fleet_maintenance')->whereNull('deleted_at')
            ->where('status', 'completed')
            ->whereBetween('completed_date', [$from->toDateString(), $to->toDateString()]);
        if ($vIds !== null) $maintQ->whereIn('vehicle_id', $vIds);
        $maintenanceCost = $maintQ->sum('cost') ?? 0;

        $finesQ = DB::table('fleet_fines')->where('status', 'outstanding')->whereBetween('offence_date', [$from, $to]);
        if ($vIds !== null)  $finesQ->whereIn('vehicle_id', $vIds);
        if ($f['driver_id']) $finesQ->where('driver_id', $f['driver_id']);
        $outstandingFines = $finesQ->selectRaw('COALESCE(SUM(outstanding_amount),0) as amount, COALESCE(SUM(aarto_points),0) as points')->first();

        $incidentQ = DB::table('fleet_booking_incidents')->whereBetween('created_at', [$from, $to]);
        if ($vIds !== null) $incidentQ->whereIn('vehicle_id', $vIds);
        $incidents = $incidentQ->count();

        return [
            'total_vehicles'        => $totalVehicles,
            'in_use'                => $inUse,
            'utilization_pct'       => $totalVehicles > 0 ? round($inUse / $totalVehicles * 100, 1) : 0,
            'total_bookings'        => $bookings,
            'completed_bookings'    => $completedBookings,
            'total_km'              => round($totalKm, 0),
            'fuel_cost'             => round($fuelData->cost ?? 0, 2),
            'fuel_litres'           => round($fuelData->litres ?? 0, 0),
            'maintenance_cost'      => round($maintenanceCost, 2),
            'outstanding_fines'     => round($outstandingFines->amount ?? 0, 2),
            'aarto_points_exposure' => (int) ($outstandingFines->points ?? 0),
            'incidents'             => $incidents,
        ];
    }

    // ── Vehicle status donut ──────────────────────────────────────────────────

    private function vehicleStatus(array $f): array
    {
        $q = DB::table('fleet_vehicles')->whereNull('deleted_at');
        if ($f['vehicle_type'])   $q->where('vehicle_type', $f['vehicle_type']);
        if ($f['fuel_type'])      $q->where('fuel_type', $f['fuel_type']);
        if ($f['department_id'])  $q->where('department_id', $f['department_id']);
        if ($f['vehicle_id'])     $q->where('id', $f['vehicle_id']);

        return $q->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')->orderByDesc('count')->get()
            ->map(fn($r) => ['status' => $r->status, 'count' => (int) $r->count])
            ->toArray();
    }

    // ── Booking trend (monthly) ───────────────────────────────────────────────

    private function bookingTrend($from, $to, array $f): array
    {
        $vIds = $this->filteredVehicleIds($f);
        $q = DB::table('fleet_bookings')->whereBetween('departure_at', [$from, $to]);
        if ($vIds !== null)  $q->whereIn('vehicle_id', $vIds);
        if ($f['driver_id']) $q->where('driver_id', $f['driver_id']);

        return $q->selectRaw("
                TO_CHAR(departure_at, 'YYYY-MM') as month,
                COUNT(*) as total,
                SUM(CASE WHEN workflow_state = 'returned' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN workflow_state IN ('cancelled', 'rejected', 'turned_back') THEN 1 ELSE 0 END) as cancelled
            ")
            ->groupByRaw("TO_CHAR(departure_at, 'YYYY-MM')")
            ->orderByRaw("TO_CHAR(departure_at, 'YYYY-MM')")
            ->get()
            ->map(fn($r) => [
                'month'     => $r->month,
                'total'     => (int) $r->total,
                'completed' => (int) $r->completed,
                'cancelled' => (int) $r->cancelled,
            ])->toArray();
    }

    // ── Fuel spend trend (monthly) ────────────────────────────────────────────

    private function fuelTrend($from, $to, array $f): array
    {
        $vIds = $this->filteredVehicleIds($f);
        $q = DB::table('fleet_fuel_logs')->whereNull('deleted_at')->whereBetween('filled_at', [$from, $to]);
        if ($vIds !== null)  $q->whereIn('vehicle_id', $vIds);
        if ($f['driver_id']) $q->where('driver_id', $f['driver_id']);

        return $q->selectRaw("
                TO_CHAR(filled_at, 'YYYY-MM') as month,
                ROUND(SUM(total_cost)::numeric, 2) as cost,
                ROUND(SUM(litres)::numeric, 1) as litres
            ")
            ->groupByRaw("TO_CHAR(filled_at, 'YYYY-MM')")
            ->orderByRaw("TO_CHAR(filled_at, 'YYYY-MM')")
            ->get()
            ->map(fn($r) => [
                'month'  => $r->month,
                'cost'   => (float) $r->cost,
                'litres' => (float) $r->litres,
            ])->toArray();
    }

    // ── Fuel efficiency by vehicle type ───────────────────────────────────────

    private function fuelByVehicleType($from, $to, array $f): array
    {
        $q = DB::table('fleet_fuel_logs as f')
            ->join('fleet_vehicles as v', 'v.id', '=', 'f.vehicle_id')
            ->whereNull('f.deleted_at')->whereNull('v.deleted_at')
            ->whereBetween('f.filled_at', [$from, $to])
            ->whereNotNull('f.consumption_per_100km')
            ->where('f.consumption_per_100km', '>', 0);

        $this->applyVehicleFilters($q, $f);
        if ($f['driver_id']) $q->where('f.driver_id', $f['driver_id']);

        return $q->selectRaw("
                v.vehicle_type,
                ROUND(AVG(f.consumption_per_100km)::numeric, 2) as avg_consumption,
                ROUND(SUM(f.total_cost)::numeric, 2) as total_cost,
                COUNT(*) as fill_count
            ")
            ->groupBy('v.vehicle_type')->orderBy('avg_consumption')
            ->get()
            ->map(fn($r) => [
                'vehicle_type'    => $r->vehicle_type,
                'avg_consumption' => (float) $r->avg_consumption,
                'total_cost'      => (float) $r->total_cost,
                'fill_count'      => (int) $r->fill_count,
            ])->toArray();
    }

    // ── Maintenance by category ───────────────────────────────────────────────

    private function maintenanceByCategory($from, $to, array $f): array
    {
        $vIds = $this->filteredVehicleIds($f);
        $q = DB::table('fleet_maintenance')->whereNull('deleted_at')
            ->where('status', 'completed')
            ->whereBetween('completed_date', [$from->toDateString(), $to->toDateString()]);
        if ($vIds !== null) $q->whereIn('vehicle_id', $vIds);

        return $q->selectRaw("
                COALESCE(category, 'other') as category,
                COUNT(*) as count,
                ROUND(SUM(cost)::numeric, 2) as total_cost
            ")
            ->groupBy(DB::raw("COALESCE(category, 'other')"))
            ->orderByDesc('total_cost')
            ->get()
            ->map(fn($r) => [
                'category'   => $r->category,
                'count'      => (int) $r->count,
                'total_cost' => (float) $r->total_cost,
            ])->toArray();
    }

    // ── Maintenance by status ─────────────────────────────────────────────────

    private function maintenanceByStatus($from, $to, array $f): array
    {
        $vIds = $this->filteredVehicleIds($f);
        $q = DB::table('fleet_maintenance')->whereNull('deleted_at')
            ->whereBetween('scheduled_date', [$from->toDateString(), $to->toDateString()]);
        if ($vIds !== null) $q->whereIn('vehicle_id', $vIds);

        return $q->selectRaw('status, COUNT(*) as count')->groupBy('status')
            ->get()
            ->map(fn($r) => ['status' => $r->status, 'count' => (int) $r->count])
            ->toArray();
    }

    // ── Fines by status ───────────────────────────────────────────────────────

    private function finesByStatus($from, $to, array $f): array
    {
        $vIds = $this->filteredVehicleIds($f);
        $q = DB::table('fleet_fines')->whereBetween('offence_date', [$from, $to]);
        if ($vIds !== null)  $q->whereIn('vehicle_id', $vIds);
        if ($f['driver_id']) $q->where('driver_id', $f['driver_id']);

        return $q->selectRaw("
                status,
                COUNT(*) as count,
                ROUND(SUM(amount)::numeric, 2) as total_amount
            ")
            ->groupBy('status')
            ->get()
            ->map(fn($r) => [
                'status'       => $r->status,
                'count'        => (int) $r->count,
                'total_amount' => (float) $r->total_amount,
            ])->toArray();
    }

    // ── Fines by issuing authority ────────────────────────────────────────────

    private function finesByAuthority($from, $to, array $f): array
    {
        $vIds = $this->filteredVehicleIds($f);
        $q = DB::table('fleet_fines')
            ->whereBetween('offence_date', [$from, $to])
            ->whereNotNull('issuing_authority');
        if ($vIds !== null)  $q->whereIn('vehicle_id', $vIds);
        if ($f['driver_id']) $q->where('driver_id', $f['driver_id']);

        return $q->selectRaw("
                issuing_authority,
                COUNT(*) as count,
                ROUND(SUM(amount)::numeric, 2) as total_amount
            ")
            ->groupBy('issuing_authority')->orderByDesc('count')->limit(10)
            ->get()
            ->map(fn($r) => [
                'authority'    => $r->issuing_authority,
                'count'        => (int) $r->count,
                'total_amount' => (float) $r->total_amount,
            ])->toArray();
    }

    // ── Overall cost breakdown ────────────────────────────────────────────────

    private function costBreakdown($from, $to, array $f): array
    {
        $vIds = $this->filteredVehicleIds($f);

        $fuelQ = DB::table('fleet_fuel_logs')->whereNull('deleted_at')->whereBetween('filled_at', [$from, $to]);
        if ($vIds !== null)  $fuelQ->whereIn('vehicle_id', $vIds);
        if ($f['driver_id']) $fuelQ->where('driver_id', $f['driver_id']);
        $fuel = $fuelQ->sum('total_cost') ?? 0;

        $maintQ = DB::table('fleet_maintenance')->whereNull('deleted_at')
            ->where('status', 'completed')
            ->whereBetween('completed_date', [$from->toDateString(), $to->toDateString()]);
        if ($vIds !== null) $maintQ->whereIn('vehicle_id', $vIds);
        $maintenance = $maintQ->sum('cost') ?? 0;

        $finesQ = DB::table('fleet_fines')->whereBetween('offence_date', [$from, $to]);
        if ($vIds !== null)  $finesQ->whereIn('vehicle_id', $vIds);
        if ($f['driver_id']) $finesQ->where('driver_id', $f['driver_id']);
        $fines = $finesQ->sum('amount') ?? 0;

        // Tollgates are on bookings — filter by vehicle if needed
        $tollQ = DB::table('fleet_booking_tollgates')->whereBetween('created_at', [$from, $to]);
        if ($vIds !== null) $tollQ->whereIn('vehicle_id', $vIds);
        $tollgates = $tollQ->sum('amount') ?? 0;

        return [
            'fuel'        => round($fuel, 2),
            'maintenance' => round($maintenance, 2),
            'fines'       => round($fines, 2),
            'tollgates'   => round($tollgates, 2),
            'total'       => round($fuel + $maintenance + $fines + $tollgates, 2),
        ];
    }

    // ── Top vehicles ──────────────────────────────────────────────────────────

    private function topVehicles($from, $to, array $f): array
    {
        $q = DB::table('fleet_bookings as b')
            ->join('fleet_vehicles as v', 'v.id', '=', 'b.vehicle_id')
            ->whereNull('v.deleted_at')
            ->whereBetween('b.departure_at', [$from, $to])
            ->where('b.workflow_state', 'returned');

        $this->applyVehicleFilters($q, $f);
        if ($f['driver_id']) $q->where('b.driver_id', $f['driver_id']);

        return $q->selectRaw("
                v.id,
                v.registration_number,
                v.make,
                v.model,
                COUNT(b.id) as trips,
                ROUND(SUM(b.km_travelled)::numeric, 0) as total_km
            ")
            ->groupBy('v.id', 'v.registration_number', 'v.make', 'v.model')
            ->orderByDesc('trips')->limit(10)
            ->get()
            ->map(function ($r) use ($from, $to, $f) {
                $fuelQ = DB::table('fleet_fuel_logs')
                    ->whereNull('deleted_at')->where('vehicle_id', $r->id)->whereBetween('filled_at', [$from, $to]);
                if ($f['driver_id']) $fuelQ->where('driver_id', $f['driver_id']);
                $fuelCost = $fuelQ->sum('total_cost') ?? 0;

                return [
                    'registration' => $r->registration_number,
                    'make_model'   => trim(($r->make ?? '') . ' ' . ($r->model ?? '')),
                    'trips'        => (int) $r->trips,
                    'total_km'     => (float) $r->total_km,
                    'fuel_cost'    => round($fuelCost, 2),
                ];
            })->toArray();
    }

    // ── Top drivers ───────────────────────────────────────────────────────────

    private function topDrivers($from, $to, array $f): array
    {
        $vIds = $this->filteredVehicleIds($f);

        $q = DB::table('fleet_bookings as b')
            ->join('fleet_drivers as d', 'd.id', '=', 'b.driver_id')
            ->join('users as u', 'u.id', '=', 'd.user_id')
            ->join('hr_people as p', 'p.user_id', '=', 'u.id')
            ->whereNull('d.deleted_at')
            ->whereBetween('b.departure_at', [$from, $to])
            ->where('b.workflow_state', 'returned');

        if ($vIds !== null)  $q->whereIn('b.vehicle_id', $vIds);
        if ($f['driver_id']) $q->where('b.driver_id', $f['driver_id']);

        return $q->selectRaw("
                d.id,
                CONCAT(p.first_name, ' ', p.surname) as driver_name,
                COUNT(b.id) as trips,
                ROUND(SUM(b.km_travelled)::numeric, 0) as total_km
            ")
            ->groupBy('d.id', 'p.first_name', 'p.surname')
            ->orderByDesc('trips')->limit(10)
            ->get()
            ->map(function ($r) use ($from, $to) {
                $avg = DB::table('fleet_fuel_logs')->whereNull('deleted_at')
                    ->where('driver_id', $r->id)->whereBetween('filled_at', [$from, $to])
                    ->whereNotNull('consumption_per_100km')->avg('consumption_per_100km');

                return [
                    'name'            => $r->driver_name,
                    'trips'           => (int) $r->trips,
                    'total_km'        => (float) $r->total_km,
                    'avg_consumption' => $avg ? round($avg, 2) : null,
                ];
            })->toArray();
    }
}
