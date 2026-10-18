<?php

namespace App\Modules\Fleet\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Fleet\Exports\FleetReportExport;
use App\Modules\Fleet\Models\FleetScheduledReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Inertia\Inertia;
use Inertia\Response;

class FleetReportController extends Controller
{
    private array $reportKeyLabels = [
        'vehicle_details'     => 'Vehicle Details',
        'vehicle_expenditure' => 'Vehicle Expenditure',
        'fines'               => 'Fines & Flagged Transactions',
        'service_records'     => 'Service Records',
        'incident_report'     => 'Incident Report',
        'insurance'           => 'Insurance',
        'warranty'            => 'Warranty',
        'fire_extinguisher'   => 'Fire Extinguisher Checks',
        'tollgates'           => 'Tollgates',
        'fleet_card'          => 'Fleet Card Transactions',
        'fuel_consumption'    => 'Fuel Consumption',
        'oil_consumption'     => 'Oil Consumption',
        'document_expiry'     => 'Document Expiry',
        'licence_expiry'      => 'Licence Expiry',
        'booking_utilisation' => 'Booking Utilisation',
        'collection_return'   => 'Collection & Return',
        'carbon_emissions'    => 'Carbon Emissions',
        'driver_scorecard'    => 'Driver Scorecard',
        'project_expenses'    => 'Project Expenses Report',
        'general_expenses'    => 'General Expenses Report',
    ];

    // ── Executive Dashboard (FR-6.1) ─────────────────────────────────────────

    public function index(Request $request): Response
    {
        $filters = $request->only(['vehicle_id', 'project_id', 'driver_id', 'date_from', 'date_to']);

        $vehicles = DB::table('fleet_vehicles')
            ->whereNull('deleted_at')
            ->orderBy('registration_number')
            ->get(['id', 'registration_number', 'make', 'model'])
            ->map(fn($r) => ['value' => $r->id, 'title' => "{$r->registration_number} — {$r->make} {$r->model}"])
            ->toArray();

        $projects = DB::table('fleet_projects')
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn($r) => ['value' => $r->id, 'title' => $r->name])
            ->toArray();

        $drivers = DB::table('fleet_drivers as d')
            ->join('users as u', 'u.id', '=', 'd.user_id')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->whereNull('d.deleted_at')
            ->orderByRaw("TRIM(COALESCE(p.first_name,'') || ' ' || COALESCE(p.surname,''))")
            ->get(['d.id', 'p.first_name', 'p.surname'])
            ->map(fn($r) => ['value' => $r->id, 'title' => trim(($r->first_name ?? '') . ' ' . ($r->surname ?? ''))])
            ->toArray();

        return Inertia::render('Modules/Fleet/Reports/Index', [
            'kpis'              => $this->kpiData(),
            'expiryRadar'       => $this->expiryRadar(),
            'monthlyFuel'       => $this->monthlyFuelTrend(),
            'topCostVehicles'   => $this->topCostVehicles(5),
            'topCostDrivers'    => $this->topCostDrivers(5),
            'anomalySummary'    => $this->anomalySummary(),
            'reportKeys'        => $this->reportKeyLabels,
            'vehicles'          => $vehicles,
            'projects'          => $projects,
            'drivers'           => $drivers,
            'filters'           => $filters,
            'projectExpenses'   => $this->projectExpensesRows($filters),
            'generalExpenses'   => $this->generalExpensesRows($filters),
        ]);
    }

    // ── Standard Reports (FR-6.2) ─────────────────────────────────────────────

    public function generate(Request $request, string $key): JsonResponse
    {
        $filters = $request->only(['vehicle_id', 'project_id', 'driver_id', 'date_from', 'date_to']);
        $rows = $this->rowsByKey($key, $filters);
        return response()->json(['count' => count($rows), 'key' => $key]);
    }

    public function export(Request $request, string $key, string $format): HttpResponse|\Symfony\Component\HttpFoundation\BinaryFileResponse|\Symfony\Component\HttpFoundation\StreamedResponse
    {
        $filters = $request->only(['vehicle_id', 'project_id', 'driver_id', 'date_from', 'date_to']);
        $rows  = $this->rowsByKey($key, $filters);
        $title = $this->reportKeyLabels[$key] ?? $key;
        $slug  = 'fleet-' . str_replace('_', '-', $key);
        $date  = now()->format('Y-m-d');

        return match ($format) {
            'excel' => Excel::download(
                new FleetReportExport(
                    headers: empty($rows) ? [] : array_keys((array) $rows[0]),
                    rows: array_map(fn($r) => array_values((array) $r), $rows),
                ),
                "{$slug}-{$date}.xlsx"
            ),
            'csv' => response()->streamDownload(function () use ($rows) {
                $fp = fopen('php://output', 'w');
                if (!empty($rows)) {
                    fputcsv($fp, array_keys((array) $rows[0]));
                    foreach ($rows as $row) {
                        fputcsv($fp, array_values((array) $row));
                    }
                }
                fclose($fp);
            }, "{$slug}-{$date}.csv", ['Content-Type' => 'text/csv']),
            default => response(
                $this->buildPdfHtml($title, $rows, $date),
                200,
                ['Content-Type' => 'application/pdf']
            )->setContent(
                Pdf::loadHTML($this->buildPdfHtml($title, $rows, $date))
                    ->setPaper('a4', 'landscape')
                    ->output()
            ),
        };
    }

    // ── Scheduled Reports CRUD ────────────────────────────────────────────────

    public function scheduledReports(): Response
    {
        $reports = FleetScheduledReport::orderByDesc('created_at')->get()->map(fn($r) => [
            'id'           => $r->id,
            'name'         => $r->name,
            'report_key'   => $r->report_key,
            'cadence'      => $r->cadence,
            'run_time'     => $r->run_time,
            'day_of_week'  => $r->day_of_week,
            'day_of_month' => $r->day_of_month,
            'format'       => $r->format,
            'recipients'   => $r->recipients,
            'active'       => $r->active,
            'next_run'     => $r->next_run_at?->format('d M Y H:i'),
            'last_run_at'  => $r->last_run_at?->format('d M Y H:i'),
        ]);

        return Inertia::render('Modules/Fleet/Setup/ScheduledReports', [
            'reports'    => $reports,
            'reportKeys' => $this->reportKeyLabels,
        ]);
    }

    public function storeScheduledReport(Request $request): RedirectResponse
    {
        $data = $this->validateScheduled($request);
        $data['created_by'] = $request->user()->id;

        $scheduled = FleetScheduledReport::create($data);
        $scheduled->update(['next_run_at' => $scheduled->computeNextRun()]);

        return back()->with('success', 'Scheduled report created.');
    }

    public function updateScheduledReport(Request $request, FleetScheduledReport $scheduledReport): RedirectResponse
    {
        $scheduledReport->update($this->validateScheduled($request));
        $scheduledReport->update(['next_run_at' => $scheduledReport->computeNextRun()]);

        return back()->with('success', 'Scheduled report updated.');
    }

    public function destroyScheduledReport(FleetScheduledReport $scheduledReport): RedirectResponse
    {
        $scheduledReport->delete();
        return back()->with('success', 'Scheduled report deleted.');
    }

    // ── Report data — also called by artisan command ──────────────────────────

    public function rowsByKey(string $key, array $filters = []): array
    {
        return match ($key) {
            'vehicle_details'     => $this->vehicleDetailsRows(),
            'vehicle_expenditure' => $this->vehicleExpenditureRows(),
            'fines'               => $this->finesRows(),
            'service_records'     => $this->serviceRecordsRows(),
            'incident_report'     => $this->incidentReportRows(),
            'insurance'           => $this->insuranceRows(),
            'warranty'            => $this->warrantyRows(),
            'fire_extinguisher'   => $this->fireExtinguisherRows(),
            'tollgates'           => $this->tollgatesRows(),
            'fleet_card'          => $this->fleetCardRows(),
            'fuel_consumption'    => $this->fuelConsumptionRows(),
            'oil_consumption'     => $this->oilConsumptionRows(),
            'document_expiry'     => $this->documentExpiryRows(),
            'licence_expiry'      => $this->licenceExpiryRows(),
            'booking_utilisation' => $this->bookingUtilisationRows(),
            'collection_return'   => $this->collectionReturnRows(),
            'carbon_emissions'    => $this->carbonEmissionsRows(),
            'driver_scorecard'    => $this->driverScorecardRows(),
            'project_expenses'    => $this->projectExpensesRows($filters),
            'general_expenses'    => $this->generalExpensesRows($filters),
            default               => [],
        };
    }

    // ── KPI helpers ───────────────────────────────────────────────────────────

    private function kpiData(): array
    {
        $totalVehicles = DB::table('fleet_vehicles')->whereNull('deleted_at')->where('status', '!=', 'disposed')->count();

        $activeBookings = DB::table('fleet_bookings')
            ->whereNull('deleted_at')
            ->whereIn('workflow_state', ['approved', 'collected', 'security_out', 'security_in'])
            ->count();

        $utilRate = $totalVehicles > 0 ? round(($activeBookings / $totalVehicles) * 100, 1) : 0;

        $totalKm = (float) DB::table('fleet_bookings')
            ->whereNull('deleted_at')
            ->where('workflow_state', 'returned')
            ->sum('km_travelled');

        $totalFuelCost = (float) DB::table('fleet_fuel_logs')
            ->whereNull('deleted_at')
            ->sum('total_cost');

        $totalMaintCost = (float) DB::table('fleet_maintenance')
            ->whereNull('deleted_at')
            ->where('status', 'completed')
            ->sum('cost');

        $costPerKm = $totalKm > 0 ? round(($totalFuelCost + $totalMaintCost) / $totalKm, 2) : 0;

        $avgBookingDays = (float) DB::table('fleet_bookings')
            ->whereNull('deleted_at')
            ->where('workflow_state', 'returned')
            ->selectRaw("AVG(EXTRACT(EPOCH FROM (actual_return_at - departure_at)) / 86400) as avg_days")
            ->value('avg_days');

        $avgFuelEfficiency = (float) DB::table('fleet_fuel_logs')
            ->whereNull('deleted_at')
            ->whereNotNull('consumption_per_100km')
            ->avg('consumption_per_100km');

        return [
            'total_vehicles'      => $totalVehicles,
            'utilisation_rate'    => $utilRate,
            'cost_per_km'         => $costPerKm,
            'avg_booking_days'    => round($avgBookingDays, 1),
            'avg_fuel_efficiency' => round($avgFuelEfficiency, 1),
            'total_fuel_cost'     => round($totalFuelCost, 2),
            'total_maint_cost'    => round($totalMaintCost, 2),
        ];
    }

    private function expiryRadar(): array
    {
        $now = now();

        return [
            'docs_30'  => DB::table('fleet_documents')->whereNull('deleted_at')->whereNotNull('expiry_date')->whereBetween('expiry_date', [$now, $now->copy()->addDays(30)])->count(),
            'docs_60'  => DB::table('fleet_documents')->whereNull('deleted_at')->whereNotNull('expiry_date')->whereBetween('expiry_date', [$now, $now->copy()->addDays(60)])->count(),
            'docs_90'  => DB::table('fleet_documents')->whereNull('deleted_at')->whereNotNull('expiry_date')->whereBetween('expiry_date', [$now, $now->copy()->addDays(90)])->count(),
            'lic_30'   => DB::table('fleet_drivers')->whereNull('deleted_at')->whereNotNull('licence_expiry')->whereBetween('licence_expiry', [$now, $now->copy()->addDays(30)])->count(),
            'lic_60'   => DB::table('fleet_drivers')->whereNull('deleted_at')->whereNotNull('licence_expiry')->whereBetween('licence_expiry', [$now, $now->copy()->addDays(60)])->count(),
            'lic_90'   => DB::table('fleet_drivers')->whereNull('deleted_at')->whereNotNull('licence_expiry')->whereBetween('licence_expiry', [$now, $now->copy()->addDays(90)])->count(),
            'maint_due'=> DB::table('fleet_maintenance')->whereNull('deleted_at')->where('status', 'scheduled')->where('scheduled_date', '<=', $now->copy()->addDays(30))->count(),
        ];
    }

    private function monthlyFuelTrend(): array
    {
        return DB::table('fleet_fuel_logs')
            ->whereNull('deleted_at')
            ->selectRaw("TO_CHAR(DATE_TRUNC('month',filled_at),'Mon YYYY') as month, DATE_TRUNC('month',filled_at) as m, ROUND(SUM(total_cost)::numeric,2) as cost, ROUND(SUM(litres)::numeric,1) as litres")
            ->groupByRaw("DATE_TRUNC('month',filled_at)")
            ->orderByRaw("DATE_TRUNC('month',filled_at) desc")
            ->limit(12)
            ->get()
            ->sortBy('m')
            ->values()
            ->map(fn($r) => (array) $r)
            ->toArray();
    }

    private function topCostVehicles(int $n): array
    {
        return DB::table('fleet_vehicles as v')
            ->leftJoin('fleet_fuel_logs as f', fn($j) => $j->on('f.vehicle_id', '=', 'v.id')->whereNull('f.deleted_at'))
            ->leftJoin('fleet_maintenance as m', fn($j) => $j->on('m.vehicle_id', '=', 'v.id')->whereNull('m.deleted_at')->where('m.status', 'completed'))
            ->whereNull('v.deleted_at')
            ->selectRaw("v.registration_number, v.make, v.model, ROUND((COALESCE(SUM(f.total_cost),0) + COALESCE(SUM(m.cost),0))::numeric,2) as total_cost")
            ->groupBy('v.id', 'v.registration_number', 'v.make', 'v.model')
            ->orderByRaw('total_cost desc')
            ->limit($n)
            ->get()
            ->map(fn($r) => (array) $r)
            ->toArray();
    }

    private function topCostDrivers(int $n): array
    {
        return DB::table('fleet_fuel_logs as f')
            ->join('fleet_drivers as d', 'd.id', '=', 'f.driver_id')
            ->join('users as u', 'u.id', '=', 'd.user_id')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->whereNull('f.deleted_at')
            ->whereNull('d.deleted_at')
            ->selectRaw("TRIM(COALESCE(p.first_name,'') || ' ' || COALESCE(p.surname,'')) as driver_name, ROUND(SUM(f.total_cost)::numeric,2) as total_cost")
            ->groupBy('u.id', 'p.first_name', 'p.surname')
            ->orderByRaw('SUM(f.total_cost) desc')
            ->limit($n)
            ->get()
            ->map(fn($r) => (array) $r)
            ->toArray();
    }

    private function anomalySummary(): array
    {
        return DB::table('fleet_fuel_anomalies')
            ->selectRaw("severity, COUNT(*) as count, SUM(CASE WHEN reviewed_at IS NULL THEN 1 ELSE 0 END) as unreviewed")
            ->groupBy('severity')
            ->get()
            ->map(fn($r) => (array) $r)
            ->toArray();
    }

    // ── 18 Standard report builders ───────────────────────────────────────────

    private function vehicleDetailsRows(): array
    {
        return DB::table('fleet_vehicles as v')
            ->whereNull('v.deleted_at')
            ->selectRaw("v.registration_number as \"Registration\", v.make as \"Make\", v.model as \"Model\", v.year as \"Year\", v.vehicle_type as \"Type\", v.fuel_type as \"Fuel\", v.colour as \"Colour\", v.vin_number as \"VIN\", v.status as \"Status\", ROUND(v.odometer_reading::numeric,1) as \"Odometer (km)\", v.purchase_date as \"Purchase Date\", ROUND(v.purchase_price::numeric,2) as \"Purchase Price\"")
            ->orderBy('v.registration_number')
            ->get()
            ->map(fn($r) => (array) $r)
            ->toArray();
    }

    private function vehicleExpenditureRows(): array
    {
        return DB::table('fleet_vehicles as v')
            ->leftJoin('fleet_fuel_logs as f', fn($j) => $j->on('f.vehicle_id', '=', 'v.id')->whereNull('f.deleted_at'))
            ->leftJoin('fleet_maintenance as m', fn($j) => $j->on('m.vehicle_id', '=', 'v.id')->whereNull('m.deleted_at')->where('m.status', 'completed'))
            ->whereNull('v.deleted_at')
            ->selectRaw("v.registration_number as \"Registration\", v.make as \"Make\", v.model as \"Model\", ROUND(COALESCE(SUM(f.total_cost),0)::numeric,2) as \"Fuel Cost\", ROUND(COALESCE(SUM(m.cost),0)::numeric,2) as \"Maintenance Cost\", ROUND((COALESCE(SUM(f.total_cost),0)+COALESCE(SUM(m.cost),0))::numeric,2) as \"Total Cost\"")
            ->groupBy('v.id', 'v.registration_number', 'v.make', 'v.model')
            ->orderByRaw('(COALESCE(SUM(f.total_cost),0)+COALESCE(SUM(m.cost),0)) desc')
            ->get()
            ->map(fn($r) => (array) $r)
            ->toArray();
    }

    private function finesRows(): array
    {
        return DB::table('fleet_card_transactions as t')
            ->join('fleet_cards as c', 'c.id', '=', 't.fleet_card_id')
            ->leftJoin('fleet_vehicles as v', 'v.id', '=', 'c.vehicle_id')
            ->where('t.is_flagged', true)
            ->selectRaw("c.card_number_last4 as \"Card Last 4\", c.provider as \"Provider\", v.registration_number as \"Vehicle\", t.transaction_type as \"Type\", ROUND(t.amount::numeric,2) as \"Amount\", t.transaction_at as \"Date\", t.flag_reason as \"Description\"")
            ->orderByDesc('t.transaction_at')
            ->get()
            ->map(fn($r) => (array) $r)
            ->toArray();
    }

    private function serviceRecordsRows(): array
    {
        return DB::table('fleet_maintenance as m')
            ->join('fleet_vehicles as v', 'v.id', '=', 'm.vehicle_id')
            ->whereNull('m.deleted_at')
            ->selectRaw("v.registration_number as \"Registration\", v.make as \"Make\", m.maintenance_type as \"Type\", m.category as \"Category\", m.description as \"Description\", m.status as \"Status\", m.scheduled_date as \"Scheduled\", m.completed_date as \"Completed\", m.service_provider as \"Provider\", ROUND(COALESCE(m.cost,0)::numeric,2) as \"Cost\", m.invoice_number as \"Invoice\"")
            ->orderByDesc('m.scheduled_date')
            ->get()
            ->map(fn($r) => (array) $r)
            ->toArray();
    }

    private function incidentReportRows(): array
    {
        return DB::table('fleet_booking_incidents as i')
            ->join('fleet_vehicles as v', 'v.id', '=', 'i.vehicle_id')
            ->join('fleet_bookings as b', 'b.id', '=', 'i.booking_id')
            ->leftJoin('fleet_drivers as d', 'd.id', '=', 'b.driver_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->selectRaw("v.registration_number as \"Registration\", i.incident_type as \"Type\", i.severity as \"Severity\", i.description as \"Description\", i.incident_at as \"Date\", i.location as \"Location\", i.police_case_number as \"Case No.\", TRIM(COALESCE(p.first_name,'') || ' ' || COALESCE(p.surname,'')) as \"Driver\"")
            ->orderByDesc('i.incident_at')
            ->get()
            ->map(fn($r) => (array) $r)
            ->toArray();
    }

    private function insuranceRows(): array
    {
        return DB::table('fleet_documents as d')
            ->join('fleet_vehicles as v', 'v.id', '=', 'd.vehicle_id')
            ->whereNull('d.deleted_at')
            ->where('d.document_type', 'insurance')
            ->selectRaw("v.registration_number as \"Registration\", v.make as \"Make\", v.model as \"Model\", d.document_number as \"Policy Number\", d.issuing_authority as \"Insurer\", d.issued_date as \"Issue Date\", d.expiry_date as \"Expiry Date\", d.status as \"Status\"")
            ->orderBy('d.expiry_date')
            ->get()
            ->map(fn($r) => (array) $r)
            ->toArray();
    }

    private function warrantyRows(): array
    {
        return DB::table('fleet_vehicles as v')
            ->whereNull('v.deleted_at')
            ->whereNotNull('v.purchase_date')
            ->selectRaw("v.registration_number as \"Registration\", v.make as \"Make\", v.model as \"Model\", v.year as \"Year\", v.purchase_date as \"Purchase Date\", ROUND(v.purchase_price::numeric,2) as \"Purchase Price\", (v.purchase_date::date + INTERVAL '3 years')::date as \"Est. Warranty Expiry\", v.status as \"Status\"")
            ->orderBy('v.purchase_date')
            ->get()
            ->map(fn($r) => (array) $r)
            ->toArray();
    }

    private function fireExtinguisherRows(): array
    {
        return DB::table('fleet_booking_inspections as i')
            ->join('fleet_vehicles as v', 'v.id', '=', 'i.vehicle_id')
            ->join('fleet_bookings as b', 'b.id', '=', 'i.booking_id')
            ->selectRaw("v.registration_number as \"Registration\", i.inspection_type as \"Inspection Type\", i.inspected_at as \"Inspected At\", CASE WHEN i.fire_ext_ok THEN 'OK' ELSE 'FAIL' END as \"Fire Extinguisher\", i.condition_overall as \"Overall Condition\", b.workflow_state as \"Booking State\"")
            ->where('i.fire_ext_ok', false)
            ->orWhere(fn($q) => $q->whereNotNull('i.inspected_at'))
            ->orderByDesc('i.inspected_at')
            ->get()
            ->map(fn($r) => (array) $r)
            ->toArray();
    }

    private function tollgatesRows(): array
    {
        return DB::table('fleet_booking_tollgates as t')
            ->join('fleet_vehicles as v', 'v.id', '=', 't.vehicle_id')
            ->join('fleet_bookings as b', 'b.id', '=', 't.booking_id')
            ->leftJoin('fleet_drivers as d', 'd.id', '=', 'b.driver_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->selectRaw("v.registration_number as \"Registration\", t.toll_location as \"Toll Plaza\", ROUND(t.amount::numeric,2) as \"Amount\", t.toll_at as \"Date/Time\", TRIM(COALESCE(p.first_name,'') || ' ' || COALESCE(p.surname,'')) as \"Driver\"")
            ->orderByDesc('t.toll_at')
            ->get()
            ->map(fn($r) => (array) $r)
            ->toArray();
    }

    private function fleetCardRows(): array
    {
        return DB::table('fleet_card_transactions as t')
            ->join('fleet_cards as c', 'c.id', '=', 't.fleet_card_id')
            ->leftJoin('fleet_vehicles as v', 'v.id', '=', 'c.vehicle_id')
            ->selectRaw("c.card_number_last4 as \"Card\", c.provider as \"Provider\", v.registration_number as \"Vehicle\", t.transaction_type as \"Type\", ROUND(t.amount::numeric,2) as \"Amount\", t.transaction_at as \"Date\", CASE WHEN t.is_flagged THEN 'Yes' ELSE 'No' END as \"Flagged\", t.merchant_name as \"Merchant\"")
            ->orderByDesc('t.transaction_at')
            ->get()
            ->map(fn($r) => (array) $r)
            ->toArray();
    }

    private function fuelConsumptionRows(): array
    {
        return DB::table('fleet_fuel_logs as f')
            ->join('fleet_vehicles as v', 'v.id', '=', 'f.vehicle_id')
            ->leftJoin('fleet_drivers as d', 'd.id', '=', 'f.driver_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->whereNull('f.deleted_at')
            ->selectRaw("v.registration_number as \"Registration\", f.filled_at as \"Date\", f.fuel_type as \"Fuel Type\", ROUND(f.litres::numeric,2) as \"Litres\", ROUND(COALESCE(f.cost_per_litre,0)::numeric,4) as \"Cost/L\", ROUND(COALESCE(f.total_cost,0)::numeric,2) as \"Total Cost\", ROUND(COALESCE(f.consumption_per_100km,0)::numeric,2) as \"L/100km\", f.station_name as \"Station\", TRIM(COALESCE(p.first_name,'') || ' ' || COALESCE(p.surname,'')) as \"Driver\"")
            ->orderByDesc('f.filled_at')
            ->get()
            ->map(fn($r) => (array) $r)
            ->toArray();
    }

    private function oilConsumptionRows(): array
    {
        $cardRows = DB::table('fleet_card_transactions as t')
            ->join('fleet_cards as c', 'c.id', '=', 't.fleet_card_id')
            ->leftJoin('fleet_vehicles as v', 'v.id', '=', 'c.vehicle_id')
            ->where('t.transaction_type', 'oil')
            ->selectRaw("v.registration_number as \"Registration\", 'Fleet Card' as \"Source\", t.transaction_at as \"Date\", ROUND(t.amount::numeric,2) as \"Cost\", t.merchant_name as \"Notes\"")
            ->get();

        $maintRows = DB::table('fleet_maintenance as m')
            ->join('fleet_vehicles as v', 'v.id', '=', 'm.vehicle_id')
            ->whereNull('m.deleted_at')
            ->where('m.category', 'oil_change')
            ->selectRaw("v.registration_number as \"Registration\", 'Maintenance' as \"Source\", COALESCE(m.completed_date::text, m.scheduled_date::text) as \"Date\", ROUND(COALESCE(m.cost,0)::numeric,2) as \"Cost\", m.description as \"Notes\"")
            ->get();

        return $cardRows->merge($maintRows)->sortByDesc('"Date"')->values()->map(fn($r) => (array) $r)->toArray();
    }

    private function documentExpiryRows(): array
    {
        return DB::table('fleet_documents as d')
            ->join('fleet_vehicles as v', 'v.id', '=', 'd.vehicle_id')
            ->whereNull('d.deleted_at')
            ->whereNotNull('d.expiry_date')
            ->where('d.expiry_date', '<=', now()->addDays(90))
            ->selectRaw("v.registration_number as \"Registration\", d.document_type as \"Document Type\", d.document_number as \"Document No\", d.expiry_date as \"Expiry Date\", CASE WHEN d.expiry_date < CURRENT_DATE THEN 'Expired' WHEN d.expiry_date <= CURRENT_DATE + 30 THEN 'Critical' WHEN d.expiry_date <= CURRENT_DATE + 60 THEN 'Warning' ELSE 'Upcoming' END as \"Status\"")
            ->orderBy('d.expiry_date')
            ->get()
            ->map(fn($r) => (array) $r)
            ->toArray();
    }

    private function licenceExpiryRows(): array
    {
        return DB::table('fleet_drivers as d')
            ->join('users as u', 'u.id', '=', 'd.user_id')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->whereNull('d.deleted_at')
            ->whereNotNull('d.licence_expiry')
            ->where('d.licence_expiry', '<=', now()->addDays(90))
            ->selectRaw("TRIM(COALESCE(p.first_name,'') || ' ' || COALESCE(p.surname,'')) as \"Driver\", d.licence_number as \"Licence No\", d.licence_type as \"Licence Type\", d.licence_expiry as \"Expiry Date\", CASE WHEN d.licence_expiry < CURRENT_DATE THEN 'Expired' WHEN d.licence_expiry <= CURRENT_DATE + 30 THEN 'Critical' WHEN d.licence_expiry <= CURRENT_DATE + 60 THEN 'Warning' ELSE 'Upcoming' END as \"Status\", d.pdp_number as \"PDP No\", d.pdp_expiry as \"PDP Expiry\"")
            ->orderBy('d.licence_expiry')
            ->get()
            ->map(fn($r) => (array) $r)
            ->toArray();
    }

    private function bookingUtilisationRows(): array
    {
        return DB::table('fleet_vehicles as v')
            ->leftJoin('fleet_bookings as b', fn($j) => $j->on('b.vehicle_id', '=', 'v.id')->whereNull('b.deleted_at')->where('b.workflow_state', 'returned'))
            ->whereNull('v.deleted_at')
            ->selectRaw("v.registration_number as \"Registration\", v.make as \"Make\", v.model as \"Model\", COUNT(b.id) as \"Total Trips\", COALESCE(SUM(b.km_travelled),0) as \"Total KM\", ROUND(AVG(EXTRACT(EPOCH FROM (b.actual_return_at - b.departure_at))/86400)::numeric,1) as \"Avg Duration (days)\", MAX(b.actual_return_at) as \"Last Used\"")
            ->groupBy('v.id', 'v.registration_number', 'v.make', 'v.model')
            ->orderByRaw('COUNT(b.id) desc')
            ->get()
            ->map(fn($r) => (array) $r)
            ->toArray();
    }

    private function collectionReturnRows(): array
    {
        return DB::table('fleet_bookings as b')
            ->join('fleet_vehicles as v', 'v.id', '=', 'b.vehicle_id')
            ->leftJoin('fleet_drivers as d', 'd.id', '=', 'b.driver_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->whereNull('b.deleted_at')
            ->whereIn('b.workflow_state', ['returned', 'security_out', 'security_in'])
            ->selectRaw("v.registration_number as \"Registration\", TRIM(COALESCE(p.first_name,'') || ' ' || COALESCE(p.surname,'')) as \"Driver\", b.departure_at as \"Planned Start\", b.expected_return_at as \"Planned End\", b.collected_at as \"Collected At\", b.start_odometer as \"Start Odometer\", b.end_odometer as \"End Odometer\", COALESCE(b.km_travelled,0) as \"KM Travelled\", b.workflow_state as \"Status\"")
            ->orderByDesc('b.collected_at')
            ->get()
            ->map(fn($r) => (array) $r)
            ->toArray();
    }

    private function carbonEmissionsRows(): array
    {
        return DB::table('fleet_fuel_logs as f')
            ->join('fleet_vehicles as v', 'v.id', '=', 'f.vehicle_id')
            ->whereNull('f.deleted_at')
            ->selectRaw("v.registration_number as \"Registration\", f.fuel_type as \"Fuel Type\", ROUND(SUM(f.litres)::numeric,2) as \"Total Litres\", ROUND((SUM(f.litres) * CASE WHEN f.fuel_type = 'diesel' THEN 2.68 ELSE 2.31 END)::numeric,2) as \"CO2e (kg)\", ROUND((SUM(f.litres) * CASE WHEN f.fuel_type = 'diesel' THEN 2.68 ELSE 2.31 END / 1000)::numeric,3) as \"CO2e (tonnes)\"")
            ->groupBy('v.id', 'v.registration_number', 'f.fuel_type')
            ->orderByRaw("SUM(f.litres) desc")
            ->get()
            ->map(fn($r) => (array) $r)
            ->toArray();
    }

    private function driverScorecardRows(): array
    {
        return DB::table('fleet_drivers as d')
            ->join('users as u', 'u.id', '=', 'd.user_id')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->whereNull('d.deleted_at')
            ->selectRaw("TRIM(COALESCE(p.first_name,'') || ' ' || COALESCE(p.surname,'')) as \"Driver\", d.licence_number as \"Licence No\", d.licence_type as \"Licence Type\", d.status as \"Status\", d.total_trips as \"Trips\", ROUND(d.total_km::numeric,1) as \"Total KM\", d.incidents_count as \"Incidents\", CASE WHEN d.total_trips = 0 THEN 'N/A' WHEN d.incidents_count = 0 THEN 'Excellent' WHEN (d.incidents_count::float / NULLIF(d.total_trips,0)) < 0.05 THEN 'Good' WHEN (d.incidents_count::float / NULLIF(d.total_trips,0)) < 0.10 THEN 'Fair' ELSE 'Poor' END as \"Rating\"")
            ->orderBy('d.total_trips', 'desc')
            ->get()
            ->map(fn($r) => (array) $r)
            ->toArray();
    }

    private function projectExpensesRows(array $filters = []): array
    {
        $q = DB::table('fleet_bookings as b')
            ->join('fleet_vehicles as v', 'v.id', '=', 'b.vehicle_id')
            ->leftJoin('fleet_projects as proj', 'proj.id', '=', 'b.project_id')
            ->leftJoin('fleet_drivers as d', 'd.id', '=', 'b.driver_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->whereNull('b.deleted_at')
            ->where('b.workflow_state', 'returned')
            ->selectRaw("
                b.reference as \"Booking Ref\",
                v.registration_number as \"Vehicle\",
                COALESCE(proj.name, b.project, 'No Project') as \"Project\",
                TRIM(COALESCE(p.first_name,'') || ' ' || COALESCE(p.surname,'')) as \"Driver\",
                b.destination as \"Destination\",
                b.departure_at::date as \"Date\",
                ROUND(COALESCE((SELECT SUM(fl.total_cost) FROM fleet_fuel_logs fl WHERE fl.booking_id = b.id AND fl.deleted_at IS NULL), 0)::numeric, 2) as \"Fuel Cost\",
                ROUND(COALESCE((SELECT SUM(f.amount) FROM fleet_fines f WHERE f.booking_id = b.id), 0)::numeric, 2) as \"Fine Cost\",
                ROUND(COALESCE((SELECT SUM(gc.cost) FROM fleet_vehicle_general_costs gc WHERE gc.booking_id = b.id AND gc.deleted_at IS NULL), 0)::numeric, 2) as \"General Cost\",
                COALESCE((SELECT COUNT(*) FROM fleet_booking_incidents i WHERE i.booking_id = b.id), 0) as \"Incidents\",
                ROUND(COALESCE((SELECT SUM(t.amount) FROM fleet_booking_tollgates t WHERE t.booking_id = b.id), 0)::numeric, 2) as \"Toll Cost\",
                ROUND((
                    COALESCE((SELECT SUM(fl.total_cost) FROM fleet_fuel_logs fl WHERE fl.booking_id = b.id AND fl.deleted_at IS NULL), 0) +
                    COALESCE((SELECT SUM(f.amount) FROM fleet_fines f WHERE f.booking_id = b.id), 0) +
                    COALESCE((SELECT SUM(gc.cost) FROM fleet_vehicle_general_costs gc WHERE gc.booking_id = b.id AND gc.deleted_at IS NULL), 0) +
                    COALESCE((SELECT SUM(t.amount) FROM fleet_booking_tollgates t WHERE t.booking_id = b.id), 0)
                )::numeric, 2) as \"Total Cost\"
            ");

        if (!empty($filters['vehicle_id'])) {
            $q->where('b.vehicle_id', $filters['vehicle_id']);
        }
        if (!empty($filters['project_id'])) {
            $q->where('b.project_id', $filters['project_id']);
        }
        if (!empty($filters['driver_id'])) {
            $q->where('b.driver_id', $filters['driver_id']);
        }
        if (!empty($filters['date_from'])) {
            $q->where('b.departure_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $q->where('b.departure_at', '<=', $filters['date_to'] . ' 23:59:59');
        }

        return $q->orderByRaw("COALESCE(proj.name, b.project, 'No Project'), b.departure_at desc")
            ->get()
            ->map(fn($r) => (array) $r)
            ->toArray();
    }

    private function generalExpensesRows(array $filters = []): array
    {
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo   = $filters['date_to'] ?? null;
        $vehicleId = $filters['vehicle_id'] ?? null;

        // Fuel logs
        $fuelQ = DB::table('fleet_fuel_logs as fl')
            ->join('fleet_vehicles as v', 'v.id', '=', 'fl.vehicle_id')
            ->leftJoin('fleet_bookings as b', 'b.id', '=', 'fl.booking_id')
            ->whereNull('fl.deleted_at')
            ->selectRaw("'Fuel' as \"Category\", v.registration_number as \"Vehicle\", COALESCE(b.reference, '—') as \"Booking Ref\", fl.filled_at::date as \"Date\", fl.station_name as \"Description\", ROUND(COALESCE(fl.total_cost,0)::numeric,2) as \"Amount\"");
        if ($vehicleId) $fuelQ->where('fl.vehicle_id', $vehicleId);
        if ($dateFrom)  $fuelQ->where('fl.filled_at', '>=', $dateFrom);
        if ($dateTo)    $fuelQ->where('fl.filled_at', '<=', $dateTo . ' 23:59:59');

        // Fines
        $fineQ = DB::table('fleet_fines as f')
            ->join('fleet_vehicles as v', 'v.id', '=', 'f.vehicle_id')
            ->leftJoin('fleet_bookings as b', 'b.id', '=', 'f.booking_id')
            ->selectRaw("'Fine' as \"Category\", v.registration_number as \"Vehicle\", COALESCE(b.reference, '—') as \"Booking Ref\", f.offence_date::date as \"Date\", COALESCE(f.offence_description, f.fine_number) as \"Description\", ROUND(COALESCE(f.amount,0)::numeric,2) as \"Amount\"");
        if ($vehicleId) $fineQ->where('f.vehicle_id', $vehicleId);
        if ($dateFrom)  $fineQ->where('f.offence_date', '>=', $dateFrom);
        if ($dateTo)    $fineQ->where('f.offence_date', '<=', $dateTo);

        // General costs
        $gcQ = DB::table('fleet_vehicle_general_costs as gc')
            ->join('fleet_vehicles as v', 'v.id', '=', 'gc.vehicle_id')
            ->leftJoin('fleet_bookings as b', 'b.id', '=', 'gc.booking_id')
            ->whereNull('gc.deleted_at')
            ->selectRaw("'General Cost' as \"Category\", v.registration_number as \"Vehicle\", COALESCE(b.reference, '—') as \"Booking Ref\", gc.date::date as \"Date\", COALESCE(gc.description, gc.cost_type) as \"Description\", ROUND(COALESCE(gc.cost,0)::numeric,2) as \"Amount\"");
        if ($vehicleId) $gcQ->where('gc.vehicle_id', $vehicleId);
        if ($dateFrom)  $gcQ->where('gc.date', '>=', $dateFrom);
        if ($dateTo)    $gcQ->where('gc.date', '<=', $dateTo);

        // Tollgates
        $tollQ = DB::table('fleet_booking_tollgates as t')
            ->join('fleet_vehicles as v', 'v.id', '=', 't.vehicle_id')
            ->join('fleet_bookings as b', 'b.id', '=', 't.booking_id')
            ->selectRaw("'Tollgate' as \"Category\", v.registration_number as \"Vehicle\", b.reference as \"Booking Ref\", t.toll_at::date as \"Date\", t.toll_location as \"Description\", ROUND(COALESCE(t.amount,0)::numeric,2) as \"Amount\"");
        if ($vehicleId) $tollQ->where('t.vehicle_id', $vehicleId);
        if ($dateFrom)  $tollQ->where('t.toll_at', '>=', $dateFrom);
        if ($dateTo)    $tollQ->where('t.toll_at', '<=', $dateTo . ' 23:59:59');

        // Maintenance
        $maintQ = DB::table('fleet_maintenance as m')
            ->join('fleet_vehicles as v', 'v.id', '=', 'm.vehicle_id')
            ->whereNull('m.deleted_at')
            ->where('m.status', 'completed')
            ->selectRaw("'Maintenance' as \"Category\", v.registration_number as \"Vehicle\", '—' as \"Booking Ref\", COALESCE(m.completed_date::date, m.scheduled_date::date) as \"Date\", m.description as \"Description\", ROUND(COALESCE(m.cost,0)::numeric,2) as \"Amount\"");
        if ($vehicleId) $maintQ->where('m.vehicle_id', $vehicleId);
        if ($dateFrom)  $maintQ->where('m.scheduled_date', '>=', $dateFrom);
        if ($dateTo)    $maintQ->where('m.scheduled_date', '<=', $dateTo);

        return $fuelQ->unionAll($fineQ)->unionAll($gcQ)->unionAll($tollQ)->unionAll($maintQ)
            ->orderByRaw('"Date" desc')
            ->get()
            ->map(fn($r) => (array) $r)
            ->toArray();
    }

    // ── PDF helper ────────────────────────────────────────────────────────────

    private function buildPdfHtml(string $title, array $rows, string $date): string
    {
        $headers = empty($rows) ? [] : array_keys((array) $rows[0]);

        $html = '<html><head><style>'
            . 'body{font-family:Arial,sans-serif;font-size:10px;}'
            . 'h2{margin:0 0 4px 0;color:#1565C0;}'
            . 'p{margin:0 0 10px 0;color:#666;}'
            . 'table{width:100%;border-collapse:collapse;}'
            . 'th,td{border:1px solid #ddd;padding:5px;text-align:left;}'
            . 'th{background:#E3F2FD;font-weight:bold;}'
            . 'tr:nth-child(even){background:#F5F5F5;}'
            . '</style></head><body>'
            . '<h2>Fleet — ' . e($title) . '</h2>'
            . '<p>Generated ' . e($date) . '</p>'
            . '<table><thead><tr>';

        foreach ($headers as $h) {
            $html .= '<th>' . e((string) $h) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        if (empty($rows)) {
            $html .= '<tr><td colspan="' . max(1, count($headers)) . '">No data available.</td></tr>';
        } else {
            foreach ($rows as $row) {
                $row = (array) $row;
                $html .= '<tr>';
                foreach ($headers as $h) {
                    $html .= '<td>' . e((string) ($row[$h] ?? '—')) . '</td>';
                }
                $html .= '</tr>';
            }
        }

        $html .= '</tbody></table></body></html>';
        return $html;
    }

    // ── Validation helper ─────────────────────────────────────────────────────

    private function validateScheduled(Request $request): array
    {
        return $request->validate([
            'name'         => 'required|string|max:120',
            'report_key'   => 'required|string|in:' . implode(',', array_keys($this->reportKeyLabels)),
            'cadence'      => 'required|string|in:daily,weekly,monthly',
            'run_time'     => ['required', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'day_of_week'  => 'nullable|integer|min:1|max:7',
            'day_of_month' => 'nullable|integer|min:1|max:28',
            'format'       => 'required|string|in:pdf,excel,both',
            'recipients'   => 'required|string|max:2000',
            'active'       => 'boolean',
        ]);
    }
}
