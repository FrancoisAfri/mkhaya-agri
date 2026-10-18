<?php

namespace App\Modules\Fleet\Controllers;

use App\Core\User\Trait\FileStorage;
use App\Core\User\Trait\SafeNotifies;
use App\Http\Controllers\Controller;
use App\Modules\Fleet\Models\FleetDriver;
use App\Modules\Fleet\Models\FleetOdometerLog;
use App\Modules\Fleet\Models\FleetVehicle;
use App\Modules\Fleet\Models\FleetVehicleChangeLog;
use App\Modules\Fleet\Models\FleetVehicleImage;
use App\Modules\Fleet\Models\FleetFireExtinguisher;
use App\Modules\Fleet\Models\FleetVehicleGeneralCost;
use App\Modules\Fleet\Models\FleetNote;
use App\Modules\Fleet\Models\FleetVehicleKey;
use App\Modules\Fleet\Models\FleetVehicleReminder;
use App\Modules\Fleet\Notifications\FleetVehicleReminderNotification;
use App\Modules\Fleet\Models\FleetVehicleStatusChange;
use App\Modules\Fleet\Services\FleetPolicyGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FleetVehicleController extends Controller
{
    use FileStorage, SafeNotifies;
    public function __construct(private readonly FleetPolicyGuard $policyGuard) {}
    // ── Tracked fields for change log ─────────────────────────────────────────
    private const TRACKED_FIELDS = [
        'registration_number', 'fleet_number', 'make', 'model', 'year',
        'vin_number', 'chassis_number', 'engine_number', 'colour',
        'vehicle_type', 'fuel_type', 'transmission', 'engine_capacity',
        'seating_capacity', 'odometer_reading', 'location', 'department_id',
        'assigned_driver_id', 'purchase_date', 'purchase_price', 'current_value',
        'expected_disposal_date', 'co2_emissions_g_per_km', 'notes',
    ];

    public function index(Request $request): Response
    {
        $query = FleetVehicle::with([
            'driver.user.person',
            'images' => fn($q) => $q->where('is_primary', true)->limit(1),
        ])->withCount(['bookings', 'maintenance', 'documents', 'images']);

        if ($search = $request->search) {
            $query->where(function ($q) use ($search) {
                $q->where('registration_number', 'ilike', "%{$search}%")
                  ->orWhere('fleet_number',          'ilike', "%{$search}%")
                  ->orWhere('make',                  'ilike', "%{$search}%")
                  ->orWhere('model',                 'ilike', "%{$search}%")
                  ->orWhere('vin_number',             'ilike', "%{$search}%");
            });
        }

        if ($status = $request->status) {
            $query->where('status', $status);
        }
        if ($type = $request->vehicle_type) {
            $query->where('vehicle_type', $type);
        }
        if ($fuel = $request->fuel_type) {
            $query->where('fuel_type', $fuel);
        }

        $sortField = in_array($request->sort, [
            'registration_number', 'make', 'model', 'year', 'status', 'odometer_reading', 'created_at',
        ]) ? $request->sort : 'registration_number';

        $sortDir = $request->direction === 'desc' ? 'desc' : 'asc';

        $vehicles = $query->orderBy($sortField, $sortDir)->paginate(25)->withQueryString();

        $drivers = FleetDriver::active()->with('user.person')->get()
            ->map(fn($d) => [
                'id'   => $d->id,
                'name' => trim(($d->user?->person?->first_name ?? '') . ' ' . ($d->user?->person?->surname ?? '')) ?: ($d->user?->email ?? ''),
            ]);

        return Inertia::render('Modules/Fleet/Vehicles/Index', [
            'vehicles'     => $vehicles->through(fn($v) => $this->formatVehicle($v)),
            'drivers'      => $drivers,
            'makes'        => DB::table('fleet_vehicle_makes')->where('active', true)->orderBy('name')->get(['id', 'name']),
            'vehicleModels'=> DB::table('fleet_vehicle_models')->where('active', true)->orderBy('name')->get(['id', 'make_id', 'name']),
            'vehicleTypes' => DB::table('fleet_vehicle_types')->where('active', true)->orderBy('name')->get(['id', 'name']),
            'fuelTypes'    => DB::table('fleet_fuel_types')->where('active', true)->orderBy('name')->get(['id', 'name']),
            'keySafes'     => DB::table('fleet_key_safes')->where('active', true)->orderBy('name')->get(['id', 'name']),
            'companyUnits' => DB::table('company_units')->where('active', 1)->orderBy('name')->get(['id', 'name']),
            'filters'      => $request->only(['search', 'status', 'vehicle_type', 'fuel_type', 'sort', 'direction']),
            'stats'        => [
                'total'          => FleetVehicle::count(),
                'available'      => FleetVehicle::where('status', 'available')->count(),
                'on_booking'     => FleetVehicle::where('status', 'booked')->count(),
                'in_maintenance' => FleetVehicle::where('status', 'maintenance')->count(),
            ],
        ]);
    }

    public function show(FleetVehicle $vehicle): Response
    {
        $vehicle->load(['driver.user.person', 'images', 'cards', 'keys']);

        $personName = fn($user) => trim(
            ($user?->person?->first_name ?? '') . ' ' . ($user?->person?->surname ?? '')
        ) ?: ($user?->email ?? '—');

        $bookings = $vehicle->bookings()
            ->with('driver.user.person', 'requester.person')
            ->orderByDesc('departure_at')
            ->limit(50)
            ->get()
            ->map(fn($b) => [
                'id'             => $b->id,
                'reference'      => $b->reference,
                'driver'         => $personName($b->driver?->user),
                'requester'      => $personName($b->requester),
                'project'        => $b->project,
                'destination'    => $b->destination,
                'departure_at'   => $b->departure_at?->format('d M Y H:i'),
                'return_at'      => $b->actual_return_at?->format('d M Y H:i') ?? $b->expected_return_at?->format('d M Y H:i'),
                'workflow_state' => $b->workflow_state,
                'km_travelled'   => $b->km_travelled,
                'booking_type'   => $b->usage_type ?? 'usage',
            ]);

        $fuelLogs = $vehicle->fuelLogs()
            ->orderByDesc('filled_at')
            ->limit(50)
            ->get()
            ->map(fn($f) => [
                'id'              => $f->id,
                'filled_at'       => $f->filled_at?->format('d M Y H:i'),
                'fuel_type'       => $f->fuel_type,
                'litres'          => $f->litres,
                'cost_per_litre'  => $f->cost_per_litre,
                'total_cost'      => $f->total_cost,
                'odometer'        => $f->odometer_reading,
                'station'         => $f->station_name,
                'consumption'     => $f->consumption_per_100km,
            ]);

        $incidents = DB::table('fleet_booking_incidents as i')
            ->join('fleet_bookings as b', 'b.id', '=', 'i.booking_id')
            ->leftJoin('users as u', 'u.id', '=', 'i.created_by')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->where('i.vehicle_id', $vehicle->id)
            ->selectRaw("i.id, i.incident_type, i.severity, i.description, i.incident_at, i.location, i.police_case_number, b.reference, TRIM(COALESCE(p.first_name,'') || ' ' || COALESCE(p.surname,'')) as reported_by")
            ->orderByDesc('i.incident_at')
            ->limit(50)
            ->get()
            ->map(fn($r) => (array) $r);

        $fines = DB::table('fleet_fines as f')
            ->leftJoin('fleet_drivers as d', 'd.id', '=', 'f.driver_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->where('f.vehicle_id', $vehicle->id)
            ->selectRaw("
                f.id, f.provider, f.fine_number, f.offence_code, f.offence_description,
                f.offence_date, f.location, ROUND(f.amount::numeric,2) as amount,
                ROUND(f.outstanding_amount::numeric,2) as outstanding_amount,
                f.issuing_authority, f.status, f.aarto_points,
                f.dispute_reason, f.dispute_date, f.paid_at, f.due_date,
                f.infringement_notice_path,
                CONCAT(p.first_name, ' ', p.surname) as driver_name
            ")
            ->orderByDesc('f.offence_date')
            ->limit(100)
            ->get()
            ->map(fn($r) => (array) $r);

        $maintenance = $vehicle->maintenance()
            ->orderByDesc('scheduled_date')
            ->limit(50)
            ->get()
            ->map(fn($m) => [
                'id'                       => $m->id,
                'type'                     => $m->maintenance_type,
                'category'                 => $m->category,
                'description'              => $m->description,
                'scheduled_date'           => $m->scheduled_date?->format('d M Y'),
                'scheduled_date_raw'       => $m->scheduled_date?->format('Y-m-d'),
                'completed_date'           => $m->completed_date?->format('d M Y'),
                'completed_date_raw'       => $m->completed_date?->format('Y-m-d'),
                'odometer_at_service'      => $m->odometer_at_service,
                'next_service_km'          => $m->next_service_km,
                'next_service_date'        => $m->next_service_date?->format('d M Y'),
                'next_service_date_raw'    => $m->next_service_date?->format('Y-m-d'),
                'service_provider'         => $m->service_provider,
                'service_provider_contact' => $m->service_provider_contact,
                'status'                   => $m->status,
                'priority'                 => $m->priority,
                'cost'                     => $m->cost,
                'labour_cost'              => $m->labour_cost,
                'parts_cost'               => $m->parts_cost,
                'invoice'                  => $m->invoice_number,
                'notes'                    => $m->notes,
            ]);

        $documents = $vehicle->documents()
            ->orderBy('expiry_date')
            ->get()
            ->map(fn($d) => [
                'id'                   => $d->id,
                'document_type'        => $d->document_type,
                'document_category'    => $d->document_category ?? 'general',
                'supplier'             => $d->supplier,
                'contact_person'       => $d->contact_person,
                'contact_number'       => $d->contact_number,
                'contact_email'        => $d->contact_email,
                'contact_address'      => $d->contact_address,
                'value_covered'        => $d->value_covered,
                'payment_frequency'    => $d->payment_frequency,
                'premium_amount'       => $d->premium_amount,
                'document_number'      => $d->document_number,
                'issued_date'          => $d->issued_date?->format('d M Y'),
                'issued_date_raw'      => $d->issued_date?->format('Y-m-d'),
                'expiry_date'          => $d->expiry_date?->format('d M Y'),
                'expiry_date_raw'      => $d->expiry_date?->format('Y-m-d'),
                'issuing_authority'    => $d->issuing_authority,
                'status'               => $d->status,
                'days_left'            => $d->daysUntilExpiry(),
                'has_file'             => (bool) $d->file_path,
                'notes'                => $d->notes,
            ]);

        // Split documents by explicit category tag
        $insurance  = $documents->filter(fn($d) => ($d['document_category'] ?? 'general') === 'insurance');
        $warranties = $documents->filter(fn($d) => ($d['document_category'] ?? 'general') === 'warranty');
        $permits    = $documents->filter(fn($d) => ($d['document_category'] ?? 'general') === 'permit_licence');
        // General documents tab shows only those not claimed by another category
        $documents  = $documents->filter(fn($d) => ($d['document_category'] ?? 'general') === 'general');

        // Tollgates
        $tollgates = DB::table('fleet_booking_tollgates as t')
            ->join('fleet_bookings as b', 'b.id', '=', 't.booking_id')
            ->where('t.vehicle_id', $vehicle->id)
            ->selectRaw("t.id, t.toll_location, ROUND(t.amount::numeric,2) as amount, t.toll_at, b.reference")
            ->orderByDesc('t.toll_at')
            ->limit(50)
            ->get()
            ->map(fn($r) => (array) $r);

        // General costs
        $generalCosts = DB::table('fleet_vehicle_general_costs as g')
            ->leftJoin('hr_people as p', 'p.id', '=', 'g.person_responsible_id')
            ->where('g.vehicle_id', $vehicle->id)
            ->whereNull('g.deleted_at')
            ->selectRaw("g.id, g.date, g.document_number, g.supplier_name, g.cost_type, ROUND(g.cost::numeric,2) as cost, g.description, g.person_responsible_id, TRIM(COALESCE(p.first_name,'') || ' ' || COALESCE(p.surname,'')) as person_responsible, g.created_at")
            ->orderByDesc('g.date')
            ->get()
            ->map(fn($r) => (array) $r);

        // General costs summary
        $costs = [
            'fuel_total'          => round((float) DB::table('fleet_fuel_logs')->where('vehicle_id', $vehicle->id)->whereNull('deleted_at')->sum('total_cost'), 2),
            'maintenance_total'   => round((float) DB::table('fleet_maintenance')->where('vehicle_id', $vehicle->id)->whereNull('deleted_at')->where('status', 'completed')->sum('cost'), 2),
            'toll_total'          => round((float) DB::table('fleet_booking_tollgates')->where('vehicle_id', $vehicle->id)->sum('amount'), 2),
            'general_costs_total' => round((float) DB::table('fleet_vehicle_general_costs')->where('vehicle_id', $vehicle->id)->whereNull('deleted_at')->sum('cost'), 2),
        ];
        $costs['grand_total'] = $costs['fuel_total'] + $costs['maintenance_total'] + $costs['toll_total'] + $costs['general_costs_total'];

        $notes = $vehicle->notes()->orderByDesc('created_at')->limit(30)->get()
            ->map(fn($n) => ['id' => $n->id, 'note_type' => $n->note_type, 'body' => $n->notes, 'severity' => $n->severity, 'created_at' => $n->created_at?->format('d M Y H:i')]);

        $odometerLogs = $vehicle->odometerLogs()->orderByDesc('reading_date')->limit(30)->get()
            ->map(fn($o) => ['reading' => $o->reading, 'date' => $o->reading_date, 'source' => $o->source, 'notes' => $o->notes]);

        // Expiry dates from documents (for General Details quick view)
        $expiryDates = [];
        foreach ($documents as $d) {
            $type = strtolower($d['document_type'] ?? '');
            if (str_contains($type, 'licen') && !isset($expiryDates['licence_disc_expiry']))
                $expiryDates['licence_disc_expiry'] = $d['expiry_date'];
            if ((str_contains($type, 'road') || str_contains($type, 'cof') || str_contains($type, 'worthi')) && !isset($expiryDates['roadworthy_expiry']))
                $expiryDates['roadworthy_expiry'] = $d['expiry_date'];
            if (str_contains($type, 'insur') && !isset($expiryDates['insurance_expiry']))
                $expiryDates['insurance_expiry'] = $d['expiry_date'];
        }

        $documentTypes = DB::table('system_doc_types')->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $keySafes      = DB::table('fleet_key_safes')->where('active', true)->orderBy('name')->get(['id', 'name']);
        $drivers       = \App\Modules\Fleet\Models\FleetDriver::active()->with('user.person')->get()
            ->map(fn($d) => ['id' => $d->id, 'name' => $personName($d->user)]);

        $vehicleData = array_merge($this->formatVehicle($vehicle), $expiryDates);

        // Raw ISO dates for the edit form inputs
        $vehicleData['purchase_date_raw']          = $vehicle->purchase_date?->format('Y-m-d');
        $vehicleData['expected_disposal_date_raw'] = $vehicle->expected_disposal_date?->format('Y-m-d');

        $vehicleKeys = $vehicle->keys->map(function ($k) {
            $employeeName = null;
            if ($k->issued_to_employee_id) {
                $p = DB::table('hr_people')->where('id', $k->issued_to_employee_id)->first(['first_name', 'surname']);
                $employeeName = $p ? trim($p->first_name . ' ' . $p->surname) : null;
            }
            $safeName = $k->safe_id ? DB::table('fleet_key_safes')->where('id', $k->safe_id)->value('name') : null;
            $issuedByName = null;
            if ($k->issued_by_id) {
                $p = DB::table('hr_people')->where('user_id', $k->issued_by_id)->first(['first_name', 'surname']);
                $issuedByName = $p ? trim($p->first_name . ' ' . $p->surname) : null;
            }
            return [
                'id'               => $k->id,
                'key_number'       => $k->key_number,
                'key_type'         => $k->key_type,
                'key_status'       => $k->key_status,
                'description'      => $k->description,
                'issued_to_type'   => $k->issued_to_type,
                'employee_name'    => $employeeName,
                'safe_name'        => $safeName,
                'date_issued'      => $k->date_issued?->format('d M Y'),
                'issued_by'        => $issuedByName,
                'reason_for_loss'  => $k->reason_for_loss,
            ];
        });

        $employees = DB::table('hr_people')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'surname'])
            ->map(fn($p) => ['id' => $p->id, 'name' => trim($p->first_name . ' ' . $p->surname)]);

        $reminders = $vehicle->reminders()->get()->map(fn($r) => [
            'id'             => $r->id,
            'name'           => $r->name,
            'description'    => $r->description,
            'start_date'     => $r->start_date?->format('d M Y'),
            'start_date_raw' => $r->start_date?->format('Y-m-d'),
            'end_date'       => $r->end_date?->format('d M Y'),
            'end_date_raw'   => $r->end_date?->format('Y-m-d'),
            'notified_at'    => $r->notified_at?->format('d M Y H:i'),
            'created_at'     => $r->created_at?->format('d M Y'),
        ]);

        $fireExtinguishers = $vehicle->fireExtinguishers()->get()->map(fn($e) => [
            'id'                    => $e->id,
            'barcode'               => $e->barcode,
            'item'                  => $e->item,
            'description'           => $e->description,
            'weight_kg'             => $e->weight_kg,
            'serial_number'         => $e->serial_number,
            'invoice_number'        => $e->invoice_number,
            'purchase_order_number' => $e->purchase_order_number,
            'supplier'              => $e->supplier,
            'date_purchased'        => $e->date_purchased?->format('d M Y'),
            'date_purchased_raw'    => $e->date_purchased?->format('Y-m-d'),
            'cost'                  => $e->cost,
            'status'                => $e->status,
            'image_url'             => $e->image_url,
            'created_at'            => $e->created_at?->format('d M Y'),
        ]);

        return Inertia::render('Modules/Fleet/Vehicles/Show', [
            'vehicle'           => $vehicleData,
            'vehicleKeys'       => $vehicleKeys,
            'employees'         => $employees,
            'reminders'         => $reminders,
            'fireExtinguishers' => $fireExtinguishers,
            'generalCosts'      => $generalCosts,
            'images'        => $vehicle->images,
            'bookings'      => $bookings,
            'fuelLogs'      => $fuelLogs,
            'incidents'     => $incidents,
            'fines'         => $fines,
            'maintenance'   => $maintenance,
            'documents'     => $documents->values(),
            'insurance'     => $insurance->values(),
            'warranties'    => $warranties->values(),
            'permits'       => $permits->values(),
            'tollgates'     => $tollgates,
            'costs'         => $costs,
            'notes'         => $notes,
            'odometerLogs'  => $odometerLogs,
            'documentTypes'     => $documentTypes,
            'documentSuppliers' => DB::table('fleet_document_suppliers')->where('active', true)->orderBy('name')->get(['id', 'name']),
            'keySafes'          => $keySafes,
            'drivers'           => $drivers,
            'makes'         => DB::table('fleet_vehicle_makes')->where('active', true)->orderBy('name')->get(['id', 'name']),
            'vehicleModels' => DB::table('fleet_vehicle_models')->where('active', true)->orderBy('name')->get(['id', 'make_id', 'name']),
            'vehicleTypes'  => DB::table('fleet_vehicle_types')->where('active', true)->orderBy('name')->get(['id', 'name']),
            'fuelTypes'     => DB::table('fleet_fuel_types')->where('active', true)->orderBy('name')->get(['id', 'name']),
            'companyUnits'  => DB::table('company_units')->where('active', 1)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'registration_number'    => 'required|string|max:20|unique:fleet_vehicles',
            'fleet_number'           => 'nullable|string|max:20|unique:fleet_vehicles',
            'make_id'                => 'nullable|exists:fleet_vehicle_makes,id',
            'model_id'               => 'nullable|exists:fleet_vehicle_models,id',
            'make'                   => 'nullable|string|max:60',
            'model'                  => 'nullable|string|max:60',
            'year'                   => 'nullable|integer|min:1900|max:' . (date('Y') + 2),
            'vin_number'             => 'nullable|string|max:30|unique:fleet_vehicles',
            'chassis_number'         => 'nullable|string|max:30|unique:fleet_vehicles',
            'engine_number'          => 'nullable|string|max:30',
            'colour'                 => 'nullable|string|max:30',
            'vehicle_type_id'        => 'nullable|exists:fleet_vehicle_types,id',
            'vehicle_type'           => 'nullable|string|max:30',
            'fuel_type'              => 'nullable|string|max:20',
            'transmission'           => 'nullable|in:automatic,manual',
            'engine_capacity'        => 'nullable|numeric|min:0',
            'seating_capacity'       => 'nullable|integer|min:1|max:100',
            'odometer_reading'       => 'nullable|numeric|min:0',
            'tank_size'              => 'nullable|numeric|min:0|max:1000',
            'status'                 => 'required|in:available,booked,maintenance,offroad,disposed',
            'location'               => 'nullable|string|max:100',
            'key_safe_name'          => 'nullable|string|max:80',
            'department_id'          => 'nullable|integer',
            'company_unit_id'        => 'nullable|exists:company_units,id',
            'assigned_driver_id'     => 'nullable|exists:fleet_drivers,id',
            'purchase_date'          => 'nullable|date',
            'purchase_price'         => 'nullable|numeric|min:0',
            'expected_disposal_date' => 'nullable|date',
            'co2_emissions_g_per_km' => 'nullable|numeric|min:0',
            'notes'                  => 'nullable|string',
            'registration_document'  => 'nullable|file|mimes:pdf,docx,doc|max:10240',
        ]);

        // Resolve make/model name from lookup IDs if provided
        if ($data['make_id'] ?? null) {
            $make = DB::table('fleet_vehicle_makes')->where('id', $data['make_id'])->value('name');
            $data['make'] = $make ?? $data['make'] ?? null;
        }
        if ($data['model_id'] ?? null) {
            $model = DB::table('fleet_vehicle_models')->where('id', $data['model_id'])->value('name');
            $data['model'] = $model ?? $data['model'] ?? null;
        }
        if ($data['vehicle_type_id'] ?? null) {
            $type = DB::table('fleet_vehicle_types')->where('id', $data['vehicle_type_id'])->value('name');
            $data['vehicle_type'] = $type ?? $data['vehicle_type'] ?? 'other';
        }

        // Guard NOT NULL columns that have DB defaults
        $data['vehicle_type'] = $data['vehicle_type'] ?? 'sedan';
        $data['fuel_type']    = $data['fuel_type']    ?? 'petrol';

        // Check pending_approval setting
        $requireApproval = DB::table('fleet_settings')
            ->where('key', 'new_vehicle_approval')->value('value') === 'true';

        $data['uuid']             = (string) Str::uuid();
        $data['created_by']       = Auth::id();
        $data['approval_status']  = $requireApproval ? 'pending' : 'approved';

        if ($request->hasFile('registration_document')) {
            $data['registration_document_path'] = json_encode(
                $this->saveFileToDatabase($request->file('registration_document'))
            );
        }

        unset($data['registration_document']);

        $vehicle = FleetVehicle::create($data);

        if (!empty($data['odometer_reading'])) {
            FleetOdometerLog::create([
                'vehicle_id'   => $vehicle->id,
                'reading'      => $data['odometer_reading'],
                'reading_date' => now()->toDateString(),
                'recorded_by'  => Auth::id(),
                'source'       => 'manual',
                'created_by'   => Auth::id(),
            ]);
        }

        $msg = $requireApproval
            ? 'Vehicle submitted for approval.'
            : 'Vehicle registered successfully.';

        return back()->with('success', $msg);
    }

    public function update(Request $request, FleetVehicle $vehicle): RedirectResponse
    {
        $data = $request->validate([
            'registration_number'    => 'required|string|max:20|unique:fleet_vehicles,registration_number,' . $vehicle->id,
            'fleet_number'           => 'nullable|string|max:20|unique:fleet_vehicles,fleet_number,' . $vehicle->id,
            'make'                   => 'nullable|string|max:60',
            'model'                  => 'nullable|string|max:60',
            'year'                   => 'nullable|integer|min:1900|max:' . (date('Y') + 2),
            'vin_number'             => 'nullable|string|max:30|unique:fleet_vehicles,vin_number,' . $vehicle->id,
            'chassis_number'         => 'nullable|string|max:30|unique:fleet_vehicles,chassis_number,' . $vehicle->id,
            'engine_number'          => 'nullable|string|max:30',
            'colour'                 => 'nullable|string|max:30',
            'vehicle_type'           => 'nullable|string|max:30',
            'fuel_type'              => 'nullable|string|max:20',
            'transmission'           => 'nullable|in:automatic,manual',
            'engine_capacity'        => 'nullable|numeric|min:0',
            'seating_capacity'       => 'nullable|integer|min:1|max:100',
            'tank_size'              => 'nullable|numeric|min:0|max:1000',
            'odometer_reading'       => 'nullable|numeric|min:0',
            'location'               => 'nullable|string|max:100',
            'company_unit_id'        => 'nullable|exists:company_units,id',
            'department_id'          => 'nullable|integer',
            'assigned_driver_id'     => 'nullable|exists:fleet_drivers,id',
            'purchase_date'          => 'nullable|date',
            'purchase_price'         => 'nullable|numeric|min:0',
            'current_value'          => 'nullable|numeric|min:0',
            'expected_disposal_date' => 'nullable|date',
            'co2_emissions_g_per_km' => 'nullable|numeric|min:0',
            'notes'                  => 'nullable|string',
            'registration_document'  => 'nullable|file|mimes:pdf,docx,doc|max:10240',
        ]);

        // Field-level change log
        $this->logFieldChanges($vehicle, $data);

        $oldOdometer = $vehicle->odometer_reading;

        if ($request->hasFile('registration_document')) {
            $data['registration_document_path'] = json_encode(
                $this->saveFileToDatabase($request->file('registration_document'))
            );
        }

        unset($data['registration_document']);

        $data['updated_by'] = Auth::id();
        $data['version']    = $vehicle->version + 1;
        $vehicle->update($data);

        if (isset($data['odometer_reading']) && (float)$data['odometer_reading'] !== (float)$oldOdometer) {
            FleetOdometerLog::create([
                'vehicle_id'   => $vehicle->id,
                'reading'      => $data['odometer_reading'],
                'reading_date' => now()->toDateString(),
                'recorded_by'  => Auth::id(),
                'source'       => 'manual',
                'created_by'   => Auth::id(),
            ]);
        }

        return back()->with('success', 'Vehicle updated.');
    }

    public function changeStatus(Request $request, FleetVehicle $vehicle): RedirectResponse
    {
        $data = $request->validate([
            'status' => 'required|in:available,booked,maintenance,offroad,disposed',
            'reason' => 'required|string|max:500',
        ]);

        FleetVehicleStatusChange::create([
            'vehicle_id'  => $vehicle->id,
            'from_status' => $vehicle->status,
            'to_status'   => $data['status'],
            'reason'      => $data['reason'],
            'changed_by'  => Auth::id(),
        ]);

        $vehicle->update([
            'status'        => $data['status'],
            'status_reason' => $data['reason'],
            'updated_by'    => Auth::id(),
            'version'       => $vehicle->version + 1,
        ]);

        return back()->with('success', 'Vehicle status updated.');
    }

    public function dispose(Request $request, FleetVehicle $vehicle): RedirectResponse
    {
        $data = $request->validate([
            'disposal_type'   => 'required|in:sold,written_off,transferred',
            'disposal_date'   => 'required|date',
            'disposal_reason' => 'required|string|max:1000',
            'disposal_document' => 'nullable|file|mimes:pdf,docx,doc,jpg,png|max:10240',
        ]);

        $this->policyGuard->forVehicleDisposed($vehicle, $data['disposal_reason'], Auth::id());

        $disposalDocument = null;
        if ($request->hasFile('disposal_document')) {
            $stored = $this->saveFileToDatabase($request->file('disposal_document'));
            // Written before the update so the path is ready to save with the rest of the change.
            $disposalDocument = $vehicle->writeBytes(
                base64_decode($stored['data']),
                $request->file('disposal_document')->getClientOriginalExtension(),
            );
        }

        FleetVehicleStatusChange::create([
            'vehicle_id'  => $vehicle->id,
            'from_status' => $vehicle->status,
            'to_status'   => 'disposed',
            'reason'      => "Disposal ({$data['disposal_type']}): {$data['disposal_reason']}",
            'changed_by'  => Auth::id(),
        ]);

        $vehicle->update(array_filter([
            'status'                 => 'disposed',
            'status_reason'          => $data['disposal_reason'],
            'disposal_type'          => $data['disposal_type'],
            'disposal_date'          => $data['disposal_date'],
            'disposal_reason'        => $data['disposal_reason'],
            'disposal_document_path' => $disposalDocument,
            'updated_by'             => Auth::id(),
            'version'                => $vehicle->version + 1,
        ], fn($v) => !is_null($v)));

        return back()->with('success', 'Vehicle disposal recorded.');
    }

    // ── Image upload ──────────────────────────────────────────────────────────

    public function uploadImage(Request $request, FleetVehicle $vehicle): RedirectResponse
    {
        $request->validate([
            'images'   => 'required|array|max:10',
            'images.*' => 'image|mimes:jpg,jpeg,png|max:5120',
        ]);

        $currentCount = $vehicle->images()->count();
        if ($currentCount >= 10) {
            return back()->withErrors(['images' => 'Maximum 10 images per vehicle.']);
        }

        foreach ($request->file('images') as $file) {
            if ($currentCount >= 10) break;
            $fileData = $this->saveFileToDatabase($file);
            FleetVehicleImage::create([
                'vehicle_id'  => $vehicle->id,
                'file_path'   => json_encode($fileData),
                'file_name'   => $fileData['original_name'],
                'file_size'   => $fileData['size'],
                'mime_type'   => $fileData['mime'],
                'sort_order'  => $currentCount,
                'is_primary'  => $currentCount === 0,
                'uploaded_by' => Auth::id(),
            ]);
            $currentCount++;
        }

        return back()->with('success', 'Images uploaded.');
    }

    public function deleteImage(FleetVehicleImage $image): RedirectResponse
    {
        $image->delete();
        return back()->with('success', 'Image removed.');
    }

    // ── Key Tracking ──────────────────────────────────────────────────────────

    public function storeKey(Request $request, FleetVehicle $vehicle): RedirectResponse
    {
        $data = $request->validate([
            'key_number'             => 'nullable|string|max:40',
            'key_type'               => 'required|string|max:40',
            'key_status'             => 'required|string|max:40',
            'description'            => 'nullable|string',
            'issued_to_type'         => 'required|in:employee,safe',
            'issued_to_employee_id'  => 'nullable|integer|exists:hr_people,id',
            'safe_id'                => 'nullable|integer|exists:fleet_key_safes,id',
            'date_issued'            => 'nullable|date',
            'issued_by_id'           => 'nullable|integer|exists:users,id',
            'reason_for_loss'        => 'nullable|string|max:255',
        ]);

        $data['vehicle_id']   = $vehicle->id;
        $data['captured_by']  = Auth::id();
        $data['created_by']   = Auth::id();

        FleetVehicleKey::create($data);

        return back()->with('success', 'Key record added.');
    }

    public function deleteKey(FleetVehicleKey $key): RedirectResponse
    {
        $key->delete();
        return back()->with('success', 'Key record removed.');
    }

    // ── Vehicle Notes ─────────────────────────────────────────────────────────

    public function storeNote(Request $request, FleetVehicle $vehicle): RedirectResponse
    {
        $data = $request->validate([
            'note_type' => 'required|in:general,incident,alert,maintenance',
            'body'      => 'required|string',
            'severity'  => 'required|in:info,warning,critical',
        ]);

        FleetNote::create([
            'vehicle_id' => $vehicle->id,
            'note_type'  => $data['note_type'],
            'notes'      => $data['body'],
            'severity'   => $data['severity'],
            'created_by' => Auth::id(),
        ]);

        return back()->with('success', 'Note added.');
    }

    public function updateNote(Request $request, FleetNote $note): RedirectResponse
    {
        $data = $request->validate([
            'note_type' => 'required|in:general,incident,alert,maintenance',
            'body'      => 'required|string',
            'severity'  => 'required|in:info,warning,critical',
        ]);

        $note->update([
            'note_type'  => $data['note_type'],
            'notes'      => $data['body'],
            'severity'   => $data['severity'],
            'updated_by' => Auth::id(),
        ]);

        return back()->with('success', 'Note updated.');
    }

    public function destroyNote(FleetNote $note): RedirectResponse
    {
        $note->delete();
        return back()->with('success', 'Note removed.');
    }

    // ── Reminders ─────────────────────────────────────────────────────────────

    public function storeReminder(Request $request, FleetVehicle $vehicle): RedirectResponse
    {
        $data = $request->validate([
            'name'        => 'required|string|max:150',
            'description' => 'nullable|string',
            'start_date'  => 'nullable|date',
            'end_date'    => 'nullable|date',
        ]);

        $data['vehicle_id'] = $vehicle->id;
        $data['created_by'] = Auth::id();
        $data['updated_by'] = Auth::id();

        // Schedule notification for start_date (start of day); send immediately if no date or past
        $sendAt = isset($data['start_date'])
            ? \Carbon\Carbon::parse($data['start_date'])->startOfDay()
            : now();

        $data['notified_at'] = $sendAt;

        $reminder = FleetVehicleReminder::create($data);

        $notification = new FleetVehicleReminderNotification($reminder, $vehicle->registration_number);

        /** @var \App\Core\User\Models\User $user */
        $user = Auth::user();

        if ($sendAt->isFuture()) {
            $this->safeNotify($user, $notification->delay($sendAt));
            $msg = 'Reminder added. Notification will be sent on ' . $sendAt->format('d M Y') . '.';
        } else {
            $this->safeNotify($user, $notification);
            $msg = 'Reminder added and notification sent.';
        }

        return back()->with('success', $msg);
    }

    public function updateReminder(Request $request, FleetVehicleReminder $reminder): RedirectResponse
    {
        $data = $request->validate([
            'name'        => 'required|string|max:150',
            'description' => 'nullable|string',
            'start_date'  => 'nullable|date',
            'end_date'    => 'nullable|date',
        ]);

        $data['updated_by'] = Auth::id();
        $reminder->update($data);

        return back()->with('success', 'Reminder updated.');
    }

    public function destroyReminder(FleetVehicleReminder $reminder): RedirectResponse
    {
        $reminder->delete();
        return back()->with('success', 'Reminder removed.');
    }

    // ── Fire Extinguishers ────────────────────────────────────────────────────

    public function storeFireExtinguisher(Request $request, FleetVehicle $vehicle): RedirectResponse
    {
        $data = $request->validate([
            'barcode'               => 'nullable|string|max:80',
            'item'                  => 'nullable|string|max:150',
            'description'           => 'nullable|string',
            'weight_kg'             => 'nullable|numeric|min:0',
            'serial_number'         => 'nullable|string|max:80',
            'invoice_number'        => 'nullable|string|max:80',
            'purchase_order_number' => 'nullable|string|max:80',
            'supplier'              => 'nullable|string|max:100',
            'date_purchased'        => 'nullable|date',
            'cost'                  => 'nullable|numeric|min:0',
            'status'                => 'nullable|string|max:40',
            'image'                 => 'nullable|file|mimes:jpg,jpeg,png|max:5120',
            'document'              => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $data['vehicle_id']  = $vehicle->id;
        $data['created_by']  = Auth::id();
        $data['updated_by']  = Auth::id();
        $data['status']      = $data['status'] ?? 'active';

        if ($request->hasFile('image')) {
            $data['image_path'] = $this->saveFileToDatabase($request->file('image'));
        }
        if ($request->hasFile('document')) {
            $data['document_path'] = $this->saveFileToDatabase($request->file('document'));
        }

        unset($data['image'], $data['document']);

        FleetFireExtinguisher::create($data);

        return back()->with('success', 'Fire extinguisher record added.');
    }

    public function updateFireExtinguisher(Request $request, FleetFireExtinguisher $extinguisher): RedirectResponse
    {
        $data = $request->validate([
            'barcode'               => 'nullable|string|max:80',
            'item'                  => 'nullable|string|max:150',
            'description'           => 'nullable|string',
            'weight_kg'             => 'nullable|numeric|min:0',
            'serial_number'         => 'nullable|string|max:80',
            'invoice_number'        => 'nullable|string|max:80',
            'purchase_order_number' => 'nullable|string|max:80',
            'supplier'              => 'nullable|string|max:100',
            'date_purchased'        => 'nullable|date',
            'cost'                  => 'nullable|numeric|min:0',
            'status'                => 'nullable|string|max:40',
            'image'                 => 'nullable|file|mimes:jpg,jpeg,png|max:5120',
            'document'              => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        if ($request->hasFile('image')) {
            $data['image_path'] = $this->saveFileToDatabase($request->file('image'));
        }
        if ($request->hasFile('document')) {
            $data['document_path'] = $this->saveFileToDatabase($request->file('document'));
        }

        unset($data['image'], $data['document']);
        $data['updated_by'] = Auth::id();

        $extinguisher->update($data);

        return back()->with('success', 'Fire extinguisher updated.');
    }

    public function destroyFireExtinguisher(FleetFireExtinguisher $extinguisher): RedirectResponse
    {
        $extinguisher->delete();
        return back()->with('success', 'Fire extinguisher record removed.');
    }

    // ── General Costs ─────────────────────────────────────────────────────────

    public function storeGeneralCost(Request $request, FleetVehicle $vehicle): RedirectResponse
    {
        $data = $request->validate([
            'date'                  => 'nullable|date',
            'document_number'       => 'nullable|string|max:60',
            'supplier_name'         => 'nullable|string|max:150',
            'cost_type'             => 'nullable|string|max:60',
            'cost'                  => 'nullable|numeric|min:0',
            'description'           => 'nullable|string',
            'person_responsible_id' => 'nullable|integer',
        ]);

        $data['vehicle_id'] = $vehicle->id;
        $data['uuid']       = (string) \Illuminate\Support\Str::uuid();
        $data['created_by'] = Auth::id();
        $data['updated_by'] = Auth::id();

        FleetVehicleGeneralCost::create($data);

        return back()->with('success', 'General cost added.');
    }

    public function updateGeneralCost(Request $request, FleetVehicleGeneralCost $generalCost): RedirectResponse
    {
        $data = $request->validate([
            'date'                  => 'nullable|date',
            'document_number'       => 'nullable|string|max:60',
            'supplier_name'         => 'nullable|string|max:150',
            'cost_type'             => 'nullable|string|max:60',
            'cost'                  => 'nullable|numeric|min:0',
            'description'           => 'nullable|string',
            'person_responsible_id' => 'nullable|integer',
        ]);

        $data['updated_by'] = Auth::id();
        $generalCost->update($data);

        return back()->with('success', 'General cost updated.');
    }

    public function destroyGeneralCost(FleetVehicleGeneralCost $generalCost): RedirectResponse
    {
        $generalCost->delete();
        return back()->with('success', 'General cost removed.');
    }

    // ── CSV Export ────────────────────────────────────────────────────────────

    public function export(): StreamedResponse
    {
        $vehicles = FleetVehicle::with('driver.user.person')->get();

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="fleet-vehicles-' . now()->format('Y-m-d') . '.csv"',
        ];

        return response()->stream(function () use ($vehicles) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Registration', 'Fleet Number', 'Make', 'Model', 'Year', 'VIN', 'Chassis', 'Engine Number',
                'Colour', 'Type', 'Fuel', 'Transmission', 'Seats', 'Odometer', 'Status',
                'Location', 'Driver', 'Purchase Date', 'Purchase Price', 'Current Value',
                'CO2 (g/km)', 'Expected Disposal Date', 'Notes',
            ]);

            foreach ($vehicles as $v) {
                $driver = $v->driver?->user;
                $name   = trim(($driver?->person?->first_name ?? '') . ' ' . ($driver?->person?->surname ?? '')) ?: ($driver?->email ?? '');
                fputcsv($out, [
                    $v->registration_number, $v->fleet_number, $v->make, $v->model, $v->year,
                    $v->vin_number, $v->chassis_number, $v->engine_number, $v->colour,
                    $v->vehicle_type, $v->fuel_type, $v->transmission, $v->seating_capacity,
                    $v->odometer_reading, $v->status, $v->location, $name,
                    $v->purchase_date?->format('Y-m-d'), $v->purchase_price, $v->current_value,
                    $v->co2_emissions_g_per_km, $v->expected_disposal_date?->format('Y-m-d'), $v->notes,
                ]);
            }
            fclose($out);
        }, 200, $headers);
    }

    // ── CSV Import ────────────────────────────────────────────────────────────

    public function import(Request $request): RedirectResponse
    {
        $request->validate(['csv_file' => 'required|file|mimes:csv,txt|max:5120']);

        $file   = $request->file('csv_file');
        $handle = fopen($file->getRealPath(), 'r');

        $headers = array_map('trim', fgetcsv($handle));

        $required = ['Registration'];
        foreach ($required as $col) {
            if (!in_array($col, $headers)) {
                fclose($handle);
                return back()->withErrors(['csv_file' => "CSV is missing required column: {$col}"]);
            }
        }

        $idx = array_flip($headers);
        $col = fn(string $name) => $idx[$name] ?? null;

        $imported = 0;
        $errors   = [];
        $row      = 1;

        while (($line = fgetcsv($handle)) !== false) {
            $row++;
            $reg = trim($line[$col('Registration')] ?? '');

            if (!$reg) { $errors[] = "Row {$row}: Registration is required."; continue; }
            if (FleetVehicle::where('registration_number', $reg)->exists()) {
                $errors[] = "Row {$row}: {$reg} already exists — skipped."; continue;
            }

            try {
                FleetVehicle::create([
                    'uuid'                   => (string) Str::uuid(),
                    'registration_number'    => $reg,
                    'fleet_number'           => trim($line[$col('Fleet Number')] ?? '') ?: null,
                    'make'                   => trim($line[$col('Make')]  ?? '') ?: null,
                    'model'                  => trim($line[$col('Model')] ?? '') ?: null,
                    'year'                   => is_numeric($line[$col('Year')] ?? '') ? (int)$line[$col('Year')] : null,
                    'vin_number'             => trim($line[$col('VIN')]     ?? '') ?: null,
                    'chassis_number'         => trim($line[$col('Chassis')] ?? '') ?: null,
                    'engine_number'          => trim($line[$col('Engine Number')] ?? '') ?: null,
                    'colour'                 => trim($line[$col('Colour')]  ?? '') ?: null,
                    'vehicle_type'           => trim($line[$col('Type')]    ?? '') ?: 'sedan',
                    'fuel_type'              => trim($line[$col('Fuel')]    ?? '') ?: 'petrol',
                    'transmission'           => trim($line[$col('Transmission')] ?? '') ?: 'automatic',
                    'seating_capacity'       => is_numeric($line[$col('Seats')] ?? '') ? (int)$line[$col('Seats')] : 5,
                    'odometer_reading'       => is_numeric($line[$col('Odometer')] ?? '') ? (float)$line[$col('Odometer')] : 0,
                    'status'                 => 'available',
                    'purchase_date'          => trim($line[$col('Purchase Date')] ?? '') ?: null,
                    'purchase_price'         => is_numeric($line[$col('Purchase Price')] ?? '') ? (float)$line[$col('Purchase Price')] : null,
                    'co2_emissions_g_per_km' => is_numeric($line[$col('CO2 (g/km)')] ?? '') ? (float)$line[$col('CO2 (g/km)')] : null,
                    'notes'                  => trim($line[$col('Notes')] ?? '') ?: null,
                    'created_by'             => Auth::id(),
                    'approval_status'        => 'approved',
                ]);
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = "Row {$row} ({$reg}): " . $e->getMessage();
            }
        }

        fclose($handle);

        $message = "Imported {$imported} vehicle(s).";
        if ($errors) {
            $message .= ' Errors: ' . implode(' | ', array_slice($errors, 0, 5));
            if (count($errors) > 5) $message .= ' … and ' . (count($errors) - 5) . ' more.';
        }

        return back()->with($errors ? 'warning' : 'success', $message);
    }

    public function destroy(FleetVehicle $vehicle): RedirectResponse
    {
        $vehicle->update(['updated_by' => Auth::id()]);
        $vehicle->delete();
        return back()->with('success', 'Vehicle removed from register.');
    }

    // ── Vehicle Approvals ─────────────────────────────────────────────────────

    public function pendingApprovals(): Response
    {
        $vehicles = FleetVehicle::with('driver.user.person')
            ->where('approval_status', 'pending')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($v) => $this->formatVehicle($v));

        return Inertia::render('Modules/Fleet/Vehicles/Approvals', [
            'vehicles' => $vehicles,
        ]);
    }

    public function approveVehicle(FleetVehicle $vehicle): RedirectResponse
    {
        $vehicle->update([
            'approval_status' => 'approved',
            'approved_by'     => Auth::id(),
            'approved_at'     => now(),
            'updated_by'      => Auth::id(),
        ]);
        return back()->with('success', 'Vehicle approved.');
    }

    public function rejectVehicle(Request $request, FleetVehicle $vehicle): RedirectResponse
    {
        $data = $request->validate(['reason' => 'nullable|string|max:255']);
        $vehicle->update([
            'approval_status' => 'rejected',
            'approved_by'     => Auth::id(),
            'approved_at'     => now(),
            'notes'           => $data['reason'] ?? null,
            'updated_by'      => Auth::id(),
        ]);
        return back()->with('success', 'Vehicle rejected.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function formatVehicle(FleetVehicle $v): array
    {
        $driver = $v->driver?->user;
        $driverName = trim(($driver?->person?->first_name ?? '') . ' ' . ($driver?->person?->surname ?? '')) ?: ($driver?->email ?? null);

        // Company unit + department name
        $companyUnit = null;
        $department  = null;
        if ($v->company_unit_id) {
            $unit = DB::table('company_units')->where('id', $v->company_unit_id)->first();
            $companyUnit = $unit?->name;
            if ($unit?->parent_id) {
                $department = DB::table('company_units')->where('id', $unit->parent_id)->value('name');
            }
        }

        // Fleet card linked to this vehicle
        $card = DB::table('fleet_cards')
            ->where('vehicle_id', $v->id)
            ->where('status', 'active')
            ->first(['card_number_last4', 'card_type', 'provider']);

        return [
            'id'                      => $v->id,
            'uuid'                    => $v->uuid,
            'registration_number'     => $v->registration_number,
            'registration'            => $v->registration_number,   // alias
            'fleet_number'            => $v->fleet_number,
            'make'                    => $v->make,
            'model'                   => $v->model,
            'year'                    => $v->year,
            'vin_number'              => $v->vin_number,
            'vin'                     => $v->vin_number,             // alias
            'chassis_number'          => $v->chassis_number,
            'engine_number'           => $v->engine_number,
            'colour'                  => $v->colour,
            'color'                   => $v->colour,                 // alias
            'vehicle_type'            => $v->vehicle_type,
            'fuel_type'               => $v->fuel_type,
            'tank_size'               => $v->tank_size,
            'vehicle_cell_number'     => $v->vehicle_cell_number,
            'tracking_cell_number'    => $v->tracking_cell_number,
            'owner_name'              => $v->owner_name,
            'title_holder_name'       => $v->title_holder_name,
            'extras'                  => $v->extras,
            'transmission'            => $v->transmission,
            'seating_capacity'        => $v->seating_capacity,
            'seats'                   => $v->seating_capacity,       // alias
            'odometer_reading'        => $v->odometer_reading,
            'odometer_unit'           => 'km',
            'status'                  => $v->status,
            'status_reason'           => $v->status_reason,
            'approval_status'         => $v->approval_status,
            'location'                => $v->location,
            'key_safe_name'           => $v->key_safe_name,
            'department_id'           => $v->department_id,
            'company_unit_id'         => $v->company_unit_id,
            'company_unit'            => $companyUnit,
            'department'              => $department,
            'division'                => $companyUnit,
            'assigned_driver_id'      => $v->assigned_driver_id,
            'driver_name'             => $driverName,
            'current_driver'          => $driverName,               // alias
            'fleet_card'              => $card ? "****{$card->card_number_last4} ({$card->card_type})" : null,
            'purchase_date'           => $v->purchase_date?->format('d M Y'),
            'purchase_price'          => $v->purchase_price,
            'current_value'           => $v->current_value,
            'expected_disposal_date'  => $v->expected_disposal_date?->format('d M Y'),
            'disposal_date'           => $v->disposal_date?->format('d M Y'),
            'disposal_type'           => $v->disposal_type,
            'disposal_reason'         => $v->disposal_reason,
            'co2_emissions_g_per_km'  => $v->co2_emissions_g_per_km,
            'registration_document_path' => $v->registration_document_path,
            'notes'                   => $v->notes,
            'bookings_count'          => $v->bookings_count  ?? 0,
            'maintenance_count'       => $v->maintenance_count ?? 0,
            'documents_count'         => $v->documents_count  ?? 0,
            'images_count'            => $v->images_count     ?? 0,
            'primary_image_url'       => $v->relationLoaded('images') ? ($v->images->first()?->data_url ?? null) : null,
        ];
    }

    private function logFieldChanges(FleetVehicle $vehicle, array $newData): void
    {
        $logs = [];
        foreach (self::TRACKED_FIELDS as $field) {
            if (!array_key_exists($field, $newData)) continue;
            $old = (string)($vehicle->getAttribute($field) ?? '');
            $new = (string)($newData[$field] ?? '');
            if ($old === $new) continue;
            $logs[] = [
                'vehicle_id'  => $vehicle->id,
                'field_name'  => $field,
                'old_value'   => $old ?: null,
                'new_value'   => $new ?: null,
                'changed_by'  => Auth::id(),
                'created_at'  => now(),
                'updated_at'  => now(),
            ];
        }
        if ($logs) {
            FleetVehicleChangeLog::insert($logs);
        }
    }
}
