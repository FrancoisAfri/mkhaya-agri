<?php

namespace App\Modules\Fleet\Controllers;

use App\Core\User\Models\User;
use App\Core\User\Trait\FileStorage;
use App\Core\User\Trait\SafeNotifies;
use App\Http\Controllers\Controller;
use App\Modules\Fleet\Models\FleetBooking;
use App\Modules\Fleet\Models\FleetBookingApproval;
use App\Modules\Fleet\Models\FleetBookingApprovalConfig;
use App\Modules\Fleet\Models\FleetBookingApprovalToken;
use App\Modules\Fleet\Models\FleetBookingIncident;
use App\Modules\Fleet\Models\FleetBookingInspection;
use App\Modules\Fleet\Models\FleetBookingTollgate;
use App\Modules\Fleet\Models\FleetDriver;
use App\Modules\Fleet\Models\FleetFuelLog;
use App\Modules\Fleet\Models\FleetOdometerLog;
use App\Modules\Fleet\Models\FleetVehicle;
use App\Modules\Fleet\Models\FleetVehicleGeneralCost;
use App\Modules\Fleet\Notifications\FleetBookingApprovalNotification;
use App\Modules\Fleet\Notifications\FleetBookingStatusNotification;
use App\Modules\Fleet\Notifications\FleetIncidentReportedNotification;
use App\Modules\Fleet\Services\FleetPolicyGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class FleetBookingController extends Controller
{
    use FileStorage, SafeNotifies;

    public function __construct(private readonly FleetPolicyGuard $policyGuard) {}

    // ── Index ─────────────────────────────────────────────────────────────────

    public function index(): Response
    {
        $bookings = FleetBooking::with([
                'vehicle',
                'driver.user.person',
                'requester.person',
                'approvals',
                'tollgates',
                'incidents',
                'inspections.inspector.person',
            ])
            ->orderByDesc('departure_at')
            ->get()
            ->map(fn($b) => $this->formatBooking($b));

        $vehicles = FleetVehicle::available()
            ->orderBy('registration_number')
            ->get(['id', 'registration_number', 'make', 'model', 'year', 'fuel_type']);

        $drivers = FleetDriver::active()
            ->where('can_drive', true)
            ->with('user.person')
            ->get()
            ->map(fn($d) => [
                'id'           => $d->id,
                'name'         => $this->driverName($d),
                'licence_type' => $d->licence_type,
                'licence_expiry' => $d->licence_expiry?->format('Y-m-d'),
                'is_eligible'  => $d->isEligible(),
            ]);

        $approvalConfigs = FleetBookingApprovalConfig::orderBy('step_number')->get();

        return Inertia::render('Modules/Fleet/Bookings/Index', [
            'bookings'        => $bookings,
            'vehicles'        => $vehicles,
            'drivers'         => $drivers,
            'approvalConfigs' => $approvalConfigs,
            'projects'        => DB::table('fleet_projects')->where('active', true)->orderBy('name')->get(['id', 'name', 'description']),
            'fillingStations' => DB::table('fleet_filling_stations')->where('active', true)->orderBy('name')->get(['id', 'name', 'location', 'fuel_types']),
        ]);
    }

    // ── FR-3.1 Available vehicle search ───────────────────────────────────────

    public function availableVehicles(Request $request): JsonResponse
    {
        $from        = $request->query('from');
        $to          = $request->query('to');
        $search      = $request->query('search');
        $vehicleType = $request->query('vehicle_type');

        $query = FleetVehicle::where('status', 'available');

        if ($from && $to) {
            $busyIds = FleetBooking::whereIn('workflow_state', ['pending', 'approved', 'collected', 'security_out', 'security_in'])
                ->where('departure_at', '<', $to)
                ->where('expected_return_at', '>', $from)
                ->pluck('vehicle_id');

            $query->whereNotIn('id', $busyIds);
        }

        if ($vehicleType) {
            $query->where('vehicle_type', $vehicleType);
        }

        if ($search) {
            $like = '%' . $search . '%';
            $query->where(function ($q) use ($like) {
                $q->where('registration_number', 'ilike', $like)
                  ->orWhere('make', 'ilike', $like)
                  ->orWhere('model', 'ilike', $like);
            });
        }

        $vehicles = $query->get()->map(fn($v) => $this->scoreVehicle($v, $request->query('purpose')));

        // FR-4.5 — Sort by recommendation score descending
        $vehicles = $vehicles->sortByDesc('recommendation_score')->values();

        return response()->json($vehicles);
    }

    // ── FR-3.2 Store booking ──────────────────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        $isManager = Auth::user()->hasAnyRole(['fleet_manager', 'administrator', 'super_admin']);

        $validated = $request->validate([
            'vehicle_id'          => 'required|exists:fleet_vehicles,id',
            'driver_id'           => 'nullable|exists:fleet_drivers,id',
            'on_behalf_of_id'     => 'nullable|exists:users,id',
            'purpose'             => 'required|string|max:500',
            'destination'         => 'required|string|max:200',
            'departure_at'        => 'required|date|after:now',
            'expected_return_at'  => 'required|date|after:departure_at',
            'usage_type'          => 'required|in:official,local,personal,business',
            'is_local_trip'       => 'required|in:0,1',
            'project'             => 'nullable|string|max:100',
            'passenger_ids'       => 'nullable|array',
            'passenger_ids.*'     => 'exists:users,id',
            'passenger_count'     => 'nullable|integer|min:0|max:50',
            'supporting_document' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'notes'               => 'nullable|string',
        ]);

        try {
            // Convert is_local_trip from string to boolean
            $validated['is_local_trip'] = $request->input('is_local_trip') === '1';

            // Check approval steps
            $hasApprovalSteps = FleetBookingApprovalConfig::where('is_active', true)
                ->where('step_number', '>', 0)
                ->where('approver_type', '!=', 'none')
                ->exists();

            if (!$hasApprovalSteps) {
                throw ValidationException::withMessages([
                    'purpose' => 'No approval workflow has been configured. Please contact your system administrator.',
                ]);
            }

            // Resolve driver
            if ($isManager && !empty($validated['driver_id'])) {
                $driver = FleetDriver::findOrFail($validated['driver_id']);
            } else {
                $driver = FleetDriver::where('user_id', Auth::id())->whereNull('deleted_at')->first();
                if (!$driver) {
                    throw ValidationException::withMessages([
                        'driver_id' => 'You do not have a driver profile. Please contact the fleet manager.',
                    ]);
                }
                $validated['driver_id'] = $driver->id;
            }

            // Non-managers cannot use on_behalf_of
            if (!$isManager) {
                unset($validated['on_behalf_of_id']);
            }

            // Driver eligibility
            if (!$driver->isEligible()) {
                throw ValidationException::withMessages([
                    'driver_id' => 'The selected driver is not eligible to drive (suspended, restricted, or expired licence).',
                ]);
            }

            // Passenger capacity check
            $passengerIds = $validated['passenger_ids'] ?? [];
            $vehicle = FleetVehicle::findOrFail($validated['vehicle_id']);
            $totalOccupants = count($passengerIds) + 1;
            if ($totalOccupants > $vehicle->seating_capacity) {
                throw ValidationException::withMessages([
                    'passenger_ids' => "Total occupants ({$totalOccupants}) exceeds the vehicle's seating capacity ({$vehicle->seating_capacity}).",
                ]);
            }
            $validated['passenger_count'] = count($passengerIds);
            $validated['passenger_ids']   = $passengerIds ?: null;

            // Requester overlap check
            $overlapExists = FleetBooking::where('requester_id', Auth::id())
                ->whereIn('workflow_state', ['pending', 'approved', 'collected', 'security_out', 'security_in'])
                ->where('departure_at', '<', $validated['expected_return_at'])
                ->where('expected_return_at', '>', $validated['departure_at'])
                ->exists();

            if ($overlapExists) {
                throw ValidationException::withMessages([
                    'departure_at' => 'You already have an active booking that overlaps with this period.',
                ]);
            }

            // Vehicle conflict check
            $vehicleConflict = FleetBooking::where('vehicle_id', $validated['vehicle_id'])
                ->whereIn('workflow_state', ['pending', 'approved', 'collected', 'security_out', 'security_in'])
                ->where('departure_at', '<', $validated['expected_return_at'])
                ->where('expected_return_at', '>', $validated['departure_at'])
                ->exists();

            if ($vehicleConflict) {
                throw ValidationException::withMessages([
                    'vehicle_id' => 'This vehicle is already booked for the requested period.',
                ]);
            }

            // Supporting document upload
            if ($request->hasFile('supporting_document')) {
                $validated['supporting_document_path'] = json_encode(
                    $this->saveFileToDatabase($request->file('supporting_document'))
                );
            }
            unset($validated['supporting_document']);

            // Vehicle snapshot
            $validated['vehicle_make_snapshot']  = $vehicle->make;
            $validated['vehicle_model_snapshot'] = $vehicle->model;
            $validated['vehicle_year_snapshot']  = $vehicle->year;
            $validated['vehicle_reg_snapshot']   = $vehicle->registration_number;

            $validated['requester_id']   = Auth::id();
            $validated['created_by']     = Auth::id();
            $validated['workflow_state'] = 'pending';

            // Policy guard
            $booking = new FleetBooking($validated);
            $this->policyGuard->forBookingSubmitted($booking, $vehicle, Auth::id());

            $booking->save();
            $this->initiateApprovalWorkflow($booking);

            return redirect()
                ->route('fleet.bookings.index')
                ->with('success', 'Booking request submitted successfully. You will be notified when it is approved.');

        } catch (ValidationException $e) {
            // Re-throw validation errors so they appear on the form fields
            throw $e;
        } catch (\Exception $e) {
            // Log the unexpected error
            Log::error('Booking creation failed', [
                'user_id' => Auth::id(),
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            // Return a generic error message to the form
            throw ValidationException::withMessages([
                'purpose' => 'An unexpected error occurred while creating the booking. Please try again.',
            ]);
        }
    }

    // ── FR-3.3 Approve (step-based) ───────────────────────────────────────────

    public function approve(Request $request, FleetBooking $booking): RedirectResponse
    {
        $request->validate(['comments' => 'nullable|string']);

        if ($booking->workflow_state !== 'pending') {
            return back()->with('error', 'Booking cannot be approved in its current state.');
        }

        $this->policyGuard->forBookingApproved($booking, Auth::id());

        $step = FleetBookingApproval::where('booking_id', $booking->id)
            ->where('status', 'pending')
            ->orderBy('step_number')
            ->first();

        if (!$step) {
            // No workflow steps — direct approval
            $booking->update([
                'workflow_state' => 'approved',
                'approved_by'    => Auth::id(),
                'approved_at'    => now(),
                'updated_by'     => Auth::id(),
                'version'        => $booking->version + 1,
            ]);
            $booking->vehicle?->update(['status' => 'booked']);
            return back()->with('success', 'Booking approved.');
        }

        $step->update([
            'status'      => 'approved',
            'approver_id' => Auth::id(),
            'comments'    => $request->comments,
            'action_at'   => now(),
        ]);

        $this->advanceApprovalWorkflow($booking);

        return back()->with('success', 'Approval step recorded.');
    }

    // ── FR-3.3.5 Token-based email approval (public) ──────────────────────────

    public function approveByToken(string $token): RedirectResponse|Response
    {
        $record = FleetBookingApprovalToken::where('token', $token)
            ->with(['booking.vehicle', 'approval'])
            ->first();

        if (!$record || !$record->isValid() || $record->action !== 'approved') {
            return Inertia::render('Pages/Found/404');
        }

        DB::transaction(function () use ($record) {
            $record->update(['used_at' => now()]);

            $record->approval->update([
                'status'    => 'approved',
                'action_at' => now(),
            ]);

            // Invalidate the corresponding reject token for this approval
            FleetBookingApprovalToken::where('approval_id', $record->approval_id)
                ->where('action', 'rejected')
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            $this->advanceApprovalWorkflow($record->booking);
        });

        return redirect()->route('fleet.bookings.index')
            ->with('success', "Booking {$record->booking->reference} has been approved.");
    }

    public function rejectByToken(Request $request, string $token): RedirectResponse|Response
    {
        $record = FleetBookingApprovalToken::where('token', $token)
            ->with(['booking', 'approval'])
            ->first();

        if (!$record || !$record->isValid() || $record->action !== 'rejected') {
            return Inertia::render('Pages/Found/404');
        }

        DB::transaction(function () use ($record, $request) {
            $record->update(['used_at' => now()]);

            $record->approval->update([
                'status'    => 'rejected',
                'comments'  => $request->query('reason'),
                'action_at' => now(),
            ]);

            // Invalidate the corresponding approve token
            FleetBookingApprovalToken::where('approval_id', $record->approval_id)
                ->where('action', 'approved')
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            $record->booking->update([
                'workflow_state'      => 'cancelled',
                'cancellation_reason' => 'Rejected at approval step ' . $record->approval->step_number,
            ]);
        });

        // Notify requester
        $requester = User::find($record->booking->requester_id);
        if ($requester) {
            $this->safeNotify($requester, new FleetBookingStatusNotification(
                $record->booking,
                'rejected',
                'Rejected at approval step ' . $record->approval->step_number
            ));
        }

        return redirect()->route('fleet.bookings.index')
            ->with('success', "Booking {$record->booking->reference} has been rejected.");
    }

    // ── FR-3.3.7 Bulk approve ─────────────────────────────────────────────────

    public function bulkApprove(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'booking_ids'   => 'required|array',
            'booking_ids.*' => 'exists:fleet_bookings,id',
            'comments'      => 'nullable|string',
        ]);

        $approved = 0;

        foreach ($data['booking_ids'] as $id) {
            $booking = FleetBooking::find($id);
            if (!$booking || $booking->workflow_state !== 'pending') {
                continue;
            }

            $step = FleetBookingApproval::where('booking_id', $id)
                ->where('status', 'pending')
                ->orderBy('step_number')
                ->first();

            if ($step) {
                $step->update([
                    'status'      => 'approved',
                    'approver_id' => Auth::id(),
                    'comments'    => $request->comments,
                    'action_at'   => now(),
                ]);
                $this->advanceApprovalWorkflow($booking);
            } else {
                $booking->update([
                    'workflow_state' => 'approved',
                    'approved_by'    => Auth::id(),
                    'approved_at'    => now(),
                    'updated_by'     => Auth::id(),
                ]);
                $booking->vehicle?->update(['status' => 'booked']);
            }

            $approved++;
        }

        return back()->with('success', "{$approved} booking(s) approved.");
    }

    // ── Security check ────────────────────────────────────────────────────────

    public function securityCheck(FleetBooking $booking): RedirectResponse
    {
        $booking->update([
            'workflow_state'       => 'security_check',
            'security_checked_by'  => Auth::id(),
            'security_checked_at'  => now(),
            'updated_by'           => Auth::id(),
        ]);

        return back()->with('success', 'Security check recorded.');
    }

    // ── FR-3.4 Collection (vehicle checkout with document/image) ─────────────

    // Fleet manager marks keys handed to driver — driver proceeds to security gate
    public function collect(Request $request, FleetBooking $booking): RedirectResponse
    {
        if ($booking->workflow_state !== 'approved') {
            return back()->with('error', 'Booking must be approved before collection.');
        }

        // Block collection if the vehicle is still out on another active booking
        $activePrevious = FleetBooking::where('vehicle_id', $booking->vehicle_id)
            ->where('id', '!=', $booking->id)
            ->whereIn('workflow_state', ['collected', 'security_out', 'security_in'])
            ->first();

        if ($activePrevious) {
            return back()->with('error',
                "Vehicle is still out on booking {$activePrevious->reference}. That booking must be returned before this vehicle can be collected."
            );
        }

        $request->validate([
            'start_odometer'      => 'nullable|numeric|min:0',
            'collection_location' => 'nullable|string|max:200',
            'collection_document' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'collection_image'    => 'nullable|file|mimes:jpg,jpeg,png|max:5120',
        ]);

        $startOdometer = $request->input('start_odometer') ?? $booking->vehicle?->odometer_reading;

        $update = [
            'workflow_state'      => 'collected',
            'start_odometer'      => $startOdometer,
            'collected_at'        => now(),
            'collected_by'        => Auth::id(),
            'collection_location' => $request->input('collection_location'),
            'updated_by'          => Auth::id(),
            'version'             => $booking->version + 1,
        ];

        if ($request->hasFile('collection_document')) {
            $update['collection_document_path'] = json_encode(
                $this->saveFileToDatabase($request->file('collection_document'))
            );
        }

        if ($request->hasFile('collection_image')) {
            $update['collection_image_path'] = json_encode(
                $this->saveFileToDatabase($request->file('collection_image'))
            );
        }

        $booking->update($update);
        $fresh = $booking->fresh();

        // Notify security so they know a vehicle is on its way to the gate
        $this->safeNotifyAll(
            User::role(['fleet_manager', 'administrator'])->get(),
            new FleetBookingStatusNotification($fresh, 'collected')
        );

        return back()->with('success', 'Keys handed to driver. Security has been notified — driver must proceed to the security gate for inspection.');
    }

    // Fleet manager confirms vehicle returned — after security post-trip inspection
    public function markReturned(FleetBooking $booking): RedirectResponse
    {
        if ($booking->workflow_state !== 'security_in') {
            return back()->with('error', 'Vehicle must be security-checked in before marking as returned.');
        }

        $booking->update([
            'workflow_state' => 'returned',
            'updated_by'     => Auth::id(),
            'version'        => $booking->version + 1,
        ]);

        $booking->vehicle?->update(['status' => 'available']);

        $requester = User::find($booking->requester_id);
        if ($requester) {
            $this->safeNotify($requester, new FleetBookingStatusNotification($booking->fresh(), 'returned'));
        }

        return back()->with('success', 'Booking marked as returned.');
    }

    public function returnVehicle(Request $request, FleetBooking $booking): RedirectResponse
    {
        if ($booking->workflow_state !== 'security_in') {
            return back()->with('error', 'Vehicle must be security-checked in before marking as returned.');
        }

        $request->validate([
            'fuel_log'                    => 'nullable|array',
            'fuel_log.fuel_type'          => 'nullable|in:petrol,diesel,hybrid,electric,lpg',
            'fuel_log.litres'             => 'nullable|numeric|min:0.001',
            'fuel_log.cost_per_litre'     => 'nullable|numeric|min:0',
            'fuel_log.total_cost'         => 'nullable|numeric|min:0',
            'fuel_log.odometer_reading'   => 'nullable|numeric|min:0',
            'fuel_log.station_name'       => 'nullable|string|max:100',
            'fuel_log.full_tank'          => 'nullable|boolean',
            'fuel_log.filled_at'          => 'nullable|date',
            'fuel_log.receipt'            => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',

            'fine'                        => 'nullable|array',
            'fine.fine_number'            => 'nullable|string|max:100',
            'fine.offence_description'    => 'nullable|string',
            'fine.offence_date'           => 'nullable|date',
            'fine.amount'                 => 'nullable|numeric|min:0',
            'fine.location'               => 'nullable|string|max:500',
            'fine.issuing_authority'      => 'nullable|string|max:100',
            'fine.due_date'               => 'nullable|date',
            'fine.notes'                  => 'nullable|string|max:500',
            'fine.notice'                 => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',

            'incident'                    => 'nullable|array',
            'incident.incident_type'      => 'nullable|in:accident,breakdown,theft,damage,other',
            'incident.severity'           => 'nullable|in:low,medium,high,critical',
            'incident.description'        => 'nullable|string',
            'incident.incident_at'        => 'nullable|date',
            'incident.location'           => 'nullable|string|max:200',
            'incident.police_case_number' => 'nullable|string|max:60',
            'incident.evidence'           => 'nullable|array',
            'incident.evidence.*'         => 'file|mimes:pdf,jpg,jpeg,png|max:10240',

            'general_cost'                => 'nullable|array',
            'general_cost.cost_type'      => 'nullable|string|max:60',
            'general_cost.cost'           => 'nullable|numeric|min:0',
            'general_cost.description'    => 'nullable|string',
            'general_cost.date'           => 'nullable|date',
            'general_cost.supplier_name'  => 'nullable|string|max:150',
            'general_cost.receipt'        => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',

            'tollgate'                    => 'nullable|array',
            'tollgate.amount'             => 'nullable|numeric|min:0',
            'tollgate.toll_location'      => 'nullable|string|max:200',
            'tollgate.toll_at'            => 'nullable|date',
            'tollgate.receipt'            => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        DB::transaction(function () use ($request, $booking) {
            $fuel = $request->input('fuel_log');
            $fine = $request->input('fine');
            $inc  = $request->input('incident');
            $gc   = $request->input('general_cost');
            $toll = $request->input('tollgate');

            // ── Fuel log ─────────────────────────────────────────────────────
            if (!empty($fuel['litres'])) {
                if (empty($fuel['total_cost']) && !empty($fuel['cost_per_litre'])) {
                    $fuel['total_cost'] = round((float) $fuel['litres'] * (float) $fuel['cost_per_litre'], 2);
                }
                $receiptPath = null;
                if ($request->hasFile('fuel_log.receipt')) {
                    $receiptPath = json_encode($this->saveFileToDatabase($request->file('fuel_log.receipt')));
                }
                FleetFuelLog::create([
                    'vehicle_id'       => $booking->vehicle_id,
                    'booking_id'       => $booking->id,
                    'driver_id'        => $booking->driver_id ?? null,
                    'filled_at'        => $fuel['filled_at'] ?? now(),
                    'fuel_type'        => $fuel['fuel_type'] ?? 'petrol',
                    'litres'           => $fuel['litres'],
                    'cost_per_litre'   => $fuel['cost_per_litre'] ?? null,
                    'total_cost'       => $fuel['total_cost'] ?? null,
                    'odometer_reading' => $fuel['odometer_reading'] ?? null,
                    'station_name'     => $fuel['station_name'] ?? null,
                    'full_tank'        => !empty($fuel['full_tank']),
                    'receipt_path'     => $receiptPath,
                    'created_by'       => Auth::id(),
                ]);
                if (!empty($fuel['odometer_reading'])) {
                    DB::table('fleet_odometer_logs')->insert([
                        'vehicle_id'   => $booking->vehicle_id,
                        'reading'      => $fuel['odometer_reading'],
                        'reading_date' => now()->toDateString(),
                        'recorded_by'  => Auth::id(),
                        'source'       => 'fuel_log',
                        'notes'        => "Fuel log added during return of booking {$booking->reference}",
                        'created_by'   => Auth::id(),
                        'created_at'   => now(),
                        'updated_at'   => now(),
                    ]);
                }
            }

            // ── Fine ─────────────────────────────────────────────────────────
            if (!empty($fine['amount'])) {
                $noticePath = null;
                if ($request->hasFile('fine.notice')) {
                    $noticePath = json_encode($this->saveFileToDatabase($request->file('fine.notice')));
                }
                DB::table('fleet_fines')->insert([
                    'vehicle_id'               => $booking->vehicle_id,
                    'driver_id'                => $booking->driver_id ?? null,
                    'booking_id'               => $booking->id,
                    'provider'                 => 'manual',
                    'fine_number'              => $fine['fine_number'] ?? null,
                    'offence_description'      => $fine['offence_description'] ?? null,
                    'offence_date'             => $fine['offence_date'] ?? null,
                    'amount'                   => $fine['amount'],
                    'location'                 => $fine['location'] ?? null,
                    'issuing_authority'        => $fine['issuing_authority'] ?? null,
                    'due_date'                 => $fine['due_date'] ?? null,
                    'notes'                    => $fine['notes'] ?? null,
                    'infringement_notice_path' => $noticePath,
                    'status'                   => 'outstanding',
                    'created_by'               => Auth::id(),
                    'created_at'               => now(),
                    'updated_at'               => now(),
                ]);
            }

            // ── Incident ─────────────────────────────────────────────────────
            if (!empty($inc['description'])) {
                $evidencePaths = [];
                if ($request->hasFile('incident.evidence')) {
                    foreach ($request->file('incident.evidence') as $file) {
                        $evidencePaths[] = $this->saveFileToDatabase($file);
                    }
                }
                $incident = FleetBookingIncident::create([
                    'booking_id'         => $booking->id,
                    'vehicle_id'         => $booking->vehicle_id,
                    'incident_type'      => $inc['incident_type'] ?? 'other',
                    'severity'           => $inc['severity'] ?? 'medium',
                    'description'        => $inc['description'],
                    'incident_at'        => $inc['incident_at'] ?? now(),
                    'location'           => $inc['location'] ?? null,
                    'police_case_number' => $inc['police_case_number'] ?? null,
                    'evidence_paths'     => $evidencePaths,
                    'created_by'         => Auth::id(),
                ]);
                if (($inc['severity'] ?? '') === 'critical') {
                    $booking->vehicle?->update(['status' => 'out_of_service']);
                }
                $this->safeNotifyAll(
                    User::role(['fleet_manager', 'administrator'])->get(),
                    new FleetIncidentReportedNotification($incident, $booking)
                );
            }

            // ── General cost ─────────────────────────────────────────────────
            if (!empty($gc['cost'])) {
                $receiptPath = null;
                if ($request->hasFile('general_cost.receipt')) {
                    $receiptPath = json_encode($this->saveFileToDatabase($request->file('general_cost.receipt')));
                }
                FleetVehicleGeneralCost::create([
                    'uuid'          => (string) \Illuminate\Support\Str::uuid(),
                    'vehicle_id'    => $booking->vehicle_id,
                    'booking_id'    => $booking->id,
                    'cost_type'     => $gc['cost_type'] ?? null,
                    'cost'          => $gc['cost'],
                    'description'   => $gc['description'] ?? null,
                    'date'          => $gc['date'] ?? now()->toDateString(),
                    'supplier_name' => $gc['supplier_name'] ?? null,
                    'receipt_path'  => $receiptPath,
                    'created_by'    => Auth::id(),
                    'updated_by'    => Auth::id(),
                ]);
            }

            // ── Tollgate ─────────────────────────────────────────────────────
            if (!empty($toll['amount'])) {
                $receiptPath = null;
                if ($request->hasFile('tollgate.receipt')) {
                    $receiptPath = json_encode($this->saveFileToDatabase($request->file('tollgate.receipt')));
                }
                FleetBookingTollgate::create([
                    'booking_id'    => $booking->id,
                    'vehicle_id'    => $booking->vehicle_id,
                    'amount'        => $toll['amount'],
                    'toll_location' => $toll['toll_location'] ?? null,
                    'toll_at'       => $toll['toll_at'] ?? now(),
                    'receipt_path'  => $receiptPath,
                    'created_by'    => Auth::id(),
                ]);
            }

            // ── Mark returned ─────────────────────────────────────────────────
            $booking->update([
                'workflow_state' => 'returned',
                'updated_by'     => Auth::id(),
                'version'        => $booking->version + 1,
            ]);

            // Reset vehicle status; update odometer if provided via fuel log
            $vehicleUpdate = ['status' => 'available'];
            if (!empty($fuel['odometer_reading'])) {
                $vehicleUpdate['odometer_reading'] = $fuel['odometer_reading'];
            }
            $booking->vehicle?->update($vehicleUpdate);

            // Update driver trip counter
            $driver = FleetDriver::find($booking->driver_id);
            if ($driver) $driver->increment('total_trips');
        });

        $requester = User::find($booking->requester_id);
        if ($requester) {
            $this->safeNotify($requester, new FleetBookingStatusNotification($booking->fresh(), 'returned'));
        }

        return back()->with('success', 'Vehicle returned successfully.');
    }

    public function checkOut(Request $request, FleetBooking $booking): RedirectResponse
    {
        return $this->collect($request, $booking);
    }

    // ── Check-in ─────────────────────────────────────────────────────────────

    public function checkIn(Request $request, FleetBooking $booking): RedirectResponse
    {
        $data = $request->validate([
            'end_odometer' => 'required|numeric|min:0',
            'notes'        => 'nullable|string',
        ]);

        $km = $booking->start_odometer
            ? max(0, $data['end_odometer'] - $booking->start_odometer)
            : null;

        $this->policyGuard->forVehicleReturned($booking, (int) $data['end_odometer'], $km, Auth::id());

        // `notes` is nullable, so it is simply absent when the form leaves it blank — reading it
        // unguarded raised "Undefined array key" and turned an ordinary check-in into a 500.
        $notes = $booking->notes . (($data['notes'] ?? null) ? "\n" . $data['notes'] : '');

        $booking->update([
            'workflow_state'   => 'returned',
            'end_odometer'     => $data['end_odometer'],
            'km_travelled'     => $km,
            'actual_return_at' => now(),
            'notes'            => $notes,
            'updated_by'       => Auth::id(),
            'version'          => $booking->version + 1,
        ]);

        FleetOdometerLog::create([
            'vehicle_id'   => $booking->vehicle_id,
            'reading'      => $data['end_odometer'],
            'reading_date' => now()->toDateString(),
            'recorded_by'  => Auth::id(),
            'source'       => 'booking',
            'created_by'   => Auth::id(),
        ]);

        $booking->vehicle?->update([
            'status'           => 'available',
            'odometer_reading' => $data['end_odometer'],
        ]);

        $driver = FleetDriver::find($booking->driver_id);
        if ($driver) {
            $driver->increment('total_trips');
            if ($km) $driver->increment('total_km', $km);
        }

        // Notify requester that vehicle was returned and booking is complete
        $requester = User::find($booking->requester_id);
        if ($requester) {
            $this->safeNotify($requester, new FleetBookingStatusNotification($booking->fresh(), 'returned'));
        }

        return back()->with('success', 'Vehicle returned successfully.');
    }

    // ── Cancel ────────────────────────────────────────────────────────────────

    public function cancel(Request $request, FleetBooking $booking): RedirectResponse
    {
        $request->validate(['cancellation_reason' => 'required|string']);

        /*
         * Only a trip that has not happened yet can be called off.
         *
         * `approve()` guards its state and this did not, so a returned booking could be cancelled
         * — rewriting a completed trip after the fact, with its odometer readings and fuel already
         * logged against it. The same applied to one already cancelled.
         */
        if (! in_array($booking->workflow_state, ['pending', 'approved'], true)) {
            return back()->with('error', 'Only a pending or approved booking can be cancelled.');
        }

        $this->policyGuard->forBookingCancelled($booking, $request->cancellation_reason, Auth::id());

        $booking->update([
            'workflow_state'      => 'cancelled',
            'cancelled_by'        => Auth::id(),
            'cancellation_reason' => $request->cancellation_reason,
            'updated_by'          => Auth::id(),
        ]);

        if ($booking->vehicle?->status === 'booked') {
            $booking->vehicle->update(['status' => 'available']);
        }

        // Notify requester (if cancellation was done by someone else)
        $requester = User::find($booking->requester_id);
        if ($requester && $requester->id !== Auth::id()) {
            $this->safeNotify($requester, new FleetBookingStatusNotification(
                $booking, 'cancelled', $request->cancellation_reason
            ));
        }

        return back()->with('success', 'Booking cancelled.');
    }

    // ── FR-3.5.2 Tollgate ─────────────────────────────────────────────────────

    public function logTollgate(Request $request, FleetBooking $booking): RedirectResponse
    {
        $data = $request->validate([
            'amount'        => 'required|numeric|min:0',
            'toll_location' => 'nullable|string|max:200',
            'toll_at'       => 'required|date',
            'receipt'       => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        $record = [
            'booking_id'    => $booking->id,
            'vehicle_id'    => $booking->vehicle_id,
            'amount'        => $data['amount'],
            'toll_location' => $data['toll_location'] ?? null,
            'toll_at'       => $data['toll_at'],
            'created_by'    => Auth::id(),
        ];

        if ($request->hasFile('receipt')) {
            $record['receipt_path'] = json_encode(
                $this->saveFileToDatabase($request->file('receipt'))
            );
        }

        FleetBookingTollgate::create($record);

        return back()->with('success', 'Tollgate recorded.');
    }

    // ── FR-3.5.3 Incident report ──────────────────────────────────────────────

    public function reportIncident(Request $request, FleetBooking $booking): RedirectResponse
    {
        $data = $request->validate([
            'incident_type'      => 'required|in:accident,breakdown,theft,damage,other',
            'severity'           => 'required|in:low,medium,high,critical',
            'description'        => 'required|string',
            'incident_at'        => 'required|date',
            'location'           => 'nullable|string|max:200',
            'police_case_number' => 'nullable|string|max:60',
            'evidence'           => 'nullable|array',
            'evidence.*'         => 'file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $this->policyGuard->forIncidentReported(
            $booking,
            $data['incident_type'],
            $data['severity'],
            $data['location'] ?? null,
            Auth::id()
        );

        $evidencePaths = [];
        if ($request->hasFile('evidence')) {
            foreach ($request->file('evidence') as $file) {
                $evidencePaths[] = $this->saveFileToDatabase($file);
            }
        }

        $incident = FleetBookingIncident::create([
            'booking_id'         => $booking->id,
            'vehicle_id'         => $booking->vehicle_id,
            'incident_type'      => $data['incident_type'],
            'severity'           => $data['severity'],
            'description'        => $data['description'],
            'incident_at'        => $data['incident_at'],
            'location'           => $data['location'] ?? null,
            'police_case_number' => $data['police_case_number'] ?? null,
            'evidence_paths'     => $evidencePaths,
            'created_by'         => Auth::id(),
        ]);

        if ($data['severity'] === 'critical') {
            $booking->vehicle?->update(['status' => 'out_of_service']);
        }

        // Notify fleet managers and administrators about the incident
        $notification = new FleetIncidentReportedNotification($incident, $booking);
        $this->safeNotifyAll(User::role(['fleet_manager', 'administrator'])->get(), $notification);

        return back()->with('success', 'Incident report submitted.');
    }

    // ── Pre/Post trip inspection ──────────────────────────────────────────────

    public function storeInspection(Request $request, FleetBooking $booking): RedirectResponse
    {
        $data = $request->validate([
            'inspection_type'   => 'required|in:pre_trip,post_trip',
            'odometer_reading'  => 'nullable|numeric',
            'fuel_level'        => 'nullable|in:empty,quarter,half,three_quarter,full',
            'condition_overall' => 'required|in:excellent,good,fair,poor',
            'damage_markers'    => 'nullable|array',
            'lights_ok'         => 'boolean',
            'tyres_ok'          => 'boolean',
            'brakes_ok'         => 'boolean',
            'wipers_ok'         => 'boolean',
            'fluids_ok'         => 'boolean',
            'first_aid_ok'      => 'boolean',
            'fire_ext_ok'       => 'boolean',
            'spare_tyre_ok'     => 'boolean',
            'interior_ok'       => 'boolean',
            'notes'             => 'nullable|string',
        ]);

        $data['booking_id']   = $booking->id;
        $data['vehicle_id']   = $booking->vehicle_id;
        $data['inspector_id'] = Auth::id();
        $data['inspected_at'] = now();
        $data['created_by']   = Auth::id();

        $inspection = FleetBookingInspection::create($data);

        // After post-trip, compare with pre-trip and alert fleet managers if differences found
        if ($data['inspection_type'] === 'post_trip') {
            $preTrip = FleetBookingInspection::where('booking_id', $booking->id)
                ->where('inspection_type', 'pre_trip')
                ->first();

            if ($preTrip && $this->inspectionsHaveDifferences($preTrip, $inspection)) {
                $this->safeNotifyAll(
                    User::role(['fleet_manager', 'administrator'])->get(),
                    new FleetBookingStatusNotification($booking->fresh(), 'inspection_difference')
                );
            }
        }

        return back()->with('success', ucfirst(str_replace('_', '-', $data['inspection_type'])) . ' inspection saved.');
    }

    // ── Reset approval steps ──────────────────────────────────────────────────

    public function resetApprovalSteps(FleetBooking $booking): RedirectResponse
    {
        if ($booking->workflow_state !== 'pending') {
            return back()->with('error', 'Approval steps can only be reset for bookings still in Pending state.');
        }

        $isLocal = (bool) $booking->is_local_trip;

        $approvals = FleetBookingApproval::where('booking_id', $booking->id)
            ->orderBy('step_number')
            ->get();

        $lastApprovedStep = $approvals->where('status', 'approved')->max('step_number') ?? 0;

        $firstActive = true;
        $allSkipped  = true;

        foreach ($approvals as $approval) {
            if ($approval->status === 'approved') {
                $allSkipped = false;
                continue;
            }

            if ($approval->step_number <= $lastApprovedStep) {
                continue;
            }

            $config  = FleetBookingApprovalConfig::find($approval->config_step_id);
            if (! $config) continue;

            $skipped = $isLocal && $config->check_local_trip;

            if ($skipped) {
                $approval->update(['status' => 'skipped', 'approver_id' => null, 'action_at' => null]);
            } elseif ($firstActive) {
                $approval->update([
                    'status'      => 'pending',
                    'approver_id' => $this->resolveApprover($config, $booking),
                    'action_at'   => null,
                    'comments'    => null,
                ]);
                $firstActive = false;
                $allSkipped  = false;
            } else {
                $approval->update([
                    'status'      => 'waiting',
                    'approver_id' => $this->resolveApprover($config, $booking),
                    'action_at'   => null,
                    'comments'    => null,
                ]);
                $allSkipped = false;
            }
        }

        if ($allSkipped) {
            $this->advanceApprovalWorkflow($booking);
            return back()->with('success', 'Approval steps reset — booking auto-approved (all remaining steps bypassed).');
        }

        $pending = FleetBookingApproval::where('booking_id', $booking->id)
            ->where('status', 'pending')
            ->orderBy('step_number')
            ->first();

        if ($pending) {
            $this->sendApprovalNotification($booking, $pending);
        }

        return back()->with('success', 'Approval steps reset and re-evaluated. Approvers have been notified.');
    }

    // ── Approval workflow helpers ─────────────────────────────────────────────

    private function initiateApprovalWorkflow(FleetBooking $booking): void
    {
        // Only positive step_numbers with a real approver type are actual approval steps.
        // Negative steps are workflow-state markers; approver_type='none' are terminal state labels.
        $configs = FleetBookingApprovalConfig::where('is_active', true)
            ->where('step_number', '>', 0)
            ->where('approver_type', '!=', 'none')
            ->orderBy('step_number')
            ->get();

        if ($configs->isEmpty()) {
            $booking->update(['workflow_state' => 'approved', 'approved_at' => now()]);
            return;
        }

        $isLocal       = (bool) $booking->is_local_trip;
        $allSkipped    = true;
        $firstActive   = true; // first non-skipped step gets 'pending'; the rest get 'waiting'

        foreach ($configs as $config) {
            // check_local_trip = true  →  skip this step when the booking is a local trip.
            // check_local_trip = false →  step always runs regardless of trip type.
            $skipped    = $isLocal && $config->check_local_trip;
            $approverId = $skipped ? null : $this->resolveApprover($config, $booking);

            if ($skipped) {
                $status = 'skipped';
            } elseif ($firstActive) {
                $status     = 'pending'; // only step 1 (first active) is immediately actionable
                $firstActive = false;
                $allSkipped  = false;
            } else {
                $status     = 'waiting'; // steps 2+ wait until the previous step is approved
                $allSkipped  = false;
            }

            FleetBookingApproval::create([
                'booking_id'     => $booking->id,
                'config_step_id' => $config->id,
                'step_number'    => $config->step_number,
                'approver_id'    => $approverId,
                'status'         => $status,
            ]);
        }

        if ($allSkipped) {
            $booking->update(['workflow_state' => 'approved', 'approved_at' => now()]);
            return;
        }

        // Notify the approver of the first (pending) step
        $first = FleetBookingApproval::where('booking_id', $booking->id)
            ->where('status', 'pending')
            ->orderBy('step_number')
            ->first();

        if ($first?->approver_id) {
            $this->sendApprovalNotification($booking, $first);
        }
    }

    private function advanceApprovalWorkflow(FleetBooking $booking): void
    {
        $booking->refresh();

        // Activate the next waiting step (sequential: only one step is pending at a time)
        $next = FleetBookingApproval::where('booking_id', $booking->id)
            ->where('status', 'waiting')
            ->orderBy('step_number')
            ->first();

        if ($next) {
            // Re-check delegation: if the stored approver has since delegated away, redirect
            $approverId = $next->approver_id
                ? $this->resolveDelegateId($next->approver_id)
                : $next->approver_id;

            $next->update(['status' => 'pending', 'approver_id' => $approverId]);

            if ($approverId) {
                $this->sendApprovalNotification($booking, $next->fresh());
            }
            return;
        }

        // No more pending or waiting steps — check nothing is still pending
        $stillPending = FleetBookingApproval::where('booking_id', $booking->id)
            ->where('status', 'pending')
            ->exists();

        if ($stillPending) return;

        // All steps done or no steps pending
        $booking->update([
            'workflow_state' => 'approved',
            'approved_at'    => now(),
            'approved_by'    => Auth::id() ?: null,
            'updated_by'     => Auth::id() ?: null,
            'version'        => ($booking->version ?? 0) + 1,
        ]);

        $booking->vehicle?->update(['status' => 'booked']);

        // Notify the requester
        $requester = User::find($booking->requester_id);
        if ($requester) {
            $this->safeNotify($requester, new FleetBookingStatusNotification($booking, 'approved'));
        }

        // Notify the driver (if different from requester)
        $driverUserId = $booking->driver?->user_id;
        if ($driverUserId && $driverUserId !== $booking->requester_id) {
            $driver = User::find($driverUserId);
            if ($driver) {
                $this->safeNotify($driver, new FleetBookingStatusNotification($booking, 'approved_driver'));
            }
        }

        // Notify each passenger
        if (!empty($booking->passenger_ids)) {
            foreach ($booking->passenger_ids as $passengerId) {
                $passenger = User::find($passengerId);
                if ($passenger) {
                    $this->safeNotify($passenger, new FleetBookingStatusNotification($booking, 'approved_passenger'));
                }
            }
        }
    }

    private function resolveApprover(FleetBookingApprovalConfig $config, FleetBooking $booking): ?int
    {
        $userId = match ($config->approver_type) {
            'role'         => User::role($config->role_name)->first()?->id,
            'employee'     => DB::table('hr_people')
                                ->where('id', $config->employee_id)
                                ->value('user_id'),
            'line_manager' => DB::table('hr_people as p')
                                ->join('hr_people as m', 'm.id', '=', 'p.manager_id')
                                ->where('p.user_id', $booking->requester_id)
                                ->value('m.user_id')
                                // Fallback: first fleet manager when no line manager is set
                                ?? User::role(['fleet_manager', 'administrator'])->first()?->id,
            default        => null,
        };

        if (!$userId) return null;

        // If this approver has an active delegation, redirect to their delegate
        $delegation = DB::table('fleet_booking_approval_delegations')
            ->where('delegator_id', $userId)
            ->where('is_active', true)
            ->where('start_date', '<=', now()->toDateString())
            ->where('end_date', '>=', now()->toDateString())
            ->whereNull('deleted_at')
            ->first();

        return $delegation ? (int) $delegation->delegate_id : (int) $userId;
    }

    private function resolveDelegateId(int $userId): int
    {
        $delegation = DB::table('fleet_booking_approval_delegations')
            ->where('delegator_id', $userId)
            ->where('is_active', true)
            ->where('start_date', '<=', now()->toDateString())
            ->where('end_date', '>=', now()->toDateString())
            ->whereNull('deleted_at')
            ->first();

        return $delegation ? (int) $delegation->delegate_id : $userId;
    }

    private function sendApprovalNotification(FleetBooking $booking, FleetBookingApproval $step): void
    {
        $config = FleetBookingApprovalConfig::find($step->config_step_id);

        // Role-based steps: notify every user that holds the role so any of them can action it
        if ($config && $config->approver_type === 'role' && $config->role_name) {
            $this->safeNotifyAll(
                User::role($config->role_name)->get(),
                new FleetBookingApprovalNotification($booking, $step)
            );
            return;
        }

        // Line manager / specific employee: notify the resolved individual
        $approver = User::find($step->approver_id);
        if (!$approver) return;
        $this->safeNotify($approver, new FleetBookingApprovalNotification($booking, $step));
    }

    // ── Format helpers ────────────────────────────────────────────────────────

    private function formatBooking(FleetBooking $b): array
    {
        $person       = $b->driver?->user?->person;
        $driverName   = trim(($person?->first_name ?? '') . ' ' . ($person?->surname ?? '')) ?: ($b->driver?->user?->email ?? '');

        $rPerson      = $b->requester?->person;
        $requesterName = trim(($rPerson?->first_name ?? '') . ' ' . ($rPerson?->surname ?? '')) ?: ($b->requester?->email ?? '');

        return [
            'id'                       => $b->id,
            'reference'                => $b->reference,
            'vehicle_id'               => $b->vehicle_id,
            'vehicle'                  => trim($b->vehicle?->registration_number . ' ' . $b->vehicle?->make . ' ' . $b->vehicle?->model),
            'driver_id'                => $b->driver_id,
            'driver'                   => $driverName,
            'requester_id'             => $b->requester_id,
            'requester'                => $requesterName,
            'purpose'                  => $b->purpose,
            'destination'              => $b->destination,
            'usage_type'               => $b->usage_type,
            'is_local_trip'            => $b->is_local_trip,
            'project'                  => $b->project,
            'passenger_count'          => $b->passenger_count,
            'departure_at'             => $b->departure_at?->format('d M Y H:i'),
            'expected_return_at'       => $b->expected_return_at?->format('d M Y H:i'),
            'actual_return_at'         => $b->actual_return_at?->format('d M Y H:i'),
            'start_odometer'           => $b->start_odometer,
            'end_odometer'             => $b->end_odometer,
            'km_travelled'             => $b->km_travelled,
            'workflow_state'           => $b->workflow_state,
            'collection_location'      => $b->collection_location,
            'collected_at'             => $b->collected_at?->format('d M Y H:i'),
            'supporting_document_url'  => $b->supporting_document_file['data_url'] ?? null,
            'collection_document_url'  => $b->collection_document_file['data_url'] ?? null,
            'collection_image_url'     => $b->collection_image_file['data_url'] ?? null,
            'vehicle_snapshot'         => [
                'make'      => $b->vehicle_make_snapshot,
                'model'     => $b->vehicle_model_snapshot,
                'year'      => $b->vehicle_year_snapshot,
                'reg'       => $b->vehicle_reg_snapshot,
                'odometer'  => $b->vehicle?->odometer_reading,
            ],
            'notes'                    => $b->notes,
            'approvals'                => $b->approvals->map(fn($a) => [
                'id'          => $a->id,
                'step_number' => $a->step_number,
                'status'      => $a->status,
                'comments'    => $a->comments,
                'action_at'   => $a->action_at?->format('d M Y H:i'),
            ]),
            'tollgates_count'   => $b->tollgates->count(),
            'incidents_count'   => $b->incidents->count(),
            'inspections'       => $b->inspections->map(function ($i) {
                $p = $i->inspector?->person;
                $inspectorName = $p
                    ? trim(($p->first_name ?? '') . ' ' . ($p->surname ?? ''))
                    : ($i->inspector?->email ?? 'Unknown');

                $damageMarkers = collect(is_array($i->damage_markers) ? $i->damage_markers : json_decode($i->damage_markers ?? '[]', true))
                    ->map(fn ($m) => [
                        'area'  => $m['area']  ?? $m['id'] ?? '',
                        'label' => $m['label'] ?? '',
                        'photo' => $m['photo'] ?? null,   // base64 data-URL from canvas capture
                    ])->values()->all();

                return [
                    'id'               => $i->id,
                    'inspection_type'  => $i->inspection_type,
                    'inspected_at'     => $i->inspected_at?->format('d M Y H:i'),
                    'inspector_name'   => $inspectorName,
                    'odometer_reading' => $i->odometer_reading,
                    'fuel_level'       => $i->fuel_level,
                    'condition_overall'=> $i->condition_overall,
                    'lights_ok'        => $i->lights_ok,
                    'tyres_ok'         => $i->tyres_ok,
                    'brakes_ok'        => $i->brakes_ok,
                    'wipers_ok'        => $i->wipers_ok,
                    'fluids_ok'        => $i->fluids_ok,
                    'first_aid_ok'     => $i->first_aid_ok,
                    'fire_ext_ok'      => $i->fire_ext_ok,
                    'spare_tyre_ok'    => $i->spare_tyre_ok,
                    'interior_ok'      => $i->interior_ok,
                    'notes'            => $i->notes,
                    'damage_markers'   => $damageMarkers,
                ];
            })->values(),
        ];
    }

    private function driverName(FleetDriver $d): string
    {
        $p = $d->user?->person;
        return trim(($p?->first_name ?? '') . ' ' . ($p?->surname ?? '')) ?: ($d->user?->email ?? '');
    }

    // ── FR-4.5 Smart booking suggestions — rank available vehicles ────────────

    private function scoreVehicle(FleetVehicle $v, ?string $purpose): array
    {
        $score  = 0;
        $reasons = [];

        // 1. Fuel efficiency (lower L/100km is better) — weight 30
        $avgEff = DB::table('fleet_fuel_logs')
            ->where('vehicle_id', $v->id)
            ->where('filled_at', '>=', now()->subDays(60))
            ->where('litres', '>', 0)
            ->avg('litres');

        if ($avgEff !== null && $avgEff < 8) {
            $score += 30;
            $reasons[] = 'Fuel efficient';
        } elseif ($avgEff !== null) {
            $score += max(0, 30 - (int)(($avgEff - 8) * 4));
        }

        // 2. Rotation fairness (least recently used) — weight 30
        $lastBooking = DB::table('fleet_bookings')
            ->where('vehicle_id', $v->id)
            ->where('workflow_state', 'returned')
            ->orderByDesc('actual_return_at')
            ->value('actual_return_at');

        $daysSinceUse = $lastBooking ? now()->diffInDays($lastBooking) : 999;
        $rotationScore = min(30, (int)($daysSinceUse / 7) * 10);
        $score += $rotationScore;
        if ($rotationScore >= 20) $reasons[] = 'Not used recently';

        // 3. Recent service status — weight 25
        $lastService = DB::table('fleet_maintenance')
            ->where('vehicle_id', $v->id)
            ->where('status', 'completed')
            ->orderByDesc('completed_date')
            ->value('completed_date');

        $daysSinceService = $lastService ? now()->diffInDays($lastService) : 999;
        if ($daysSinceService <= 30) {
            $score += 25;
            $reasons[] = 'Recently serviced';
        } elseif ($daysSinceService <= 90) {
            $score += 15;
        }

        // 4. No high risk prediction — weight 15
        $riskScore = DB::table('fleet_vehicle_predictions')
            ->where('vehicle_id', $v->id)
            ->where('predicted_at', '>=', now()->subDay())
            ->value('risk_score');

        if ($riskScore === null || $riskScore < 40) {
            $score += 15;
            $reasons[] = 'Low maintenance risk';
        } elseif ($riskScore < 70) {
            $score += 7;
        }

        // 5. Purpose / vehicle type match — bonus 10
        if ($purpose) {
            $lower = strtolower($purpose);
            $type  = strtolower($v->vehicle_type ?? '');
            $matchesExecutive = str_contains($lower, 'executive') || str_contains($lower, 'client');
            $matchesCargo     = str_contains($lower, 'cargo') || str_contains($lower, 'delivery') || str_contains($lower, 'transport');
            $isExecVehicle    = in_array($type, ['sedan', 'suv', 'executive']);
            $isCargoVehicle   = in_array($type, ['van', 'bakkie', 'truck', 'pickup']);

            if (($matchesExecutive && $isExecVehicle) || ($matchesCargo && $isCargoVehicle)) {
                $score += 10;
                $reasons[] = 'Matches trip purpose';
            }
        }

        $isRecommended = $score >= 60;
        $recommendationReason = $isRecommended
            ? implode(' · ', array_slice($reasons, 0, 2))
            : null;

        return [
            'id'                     => $v->id,
            'registration_number'    => $v->registration_number,
            'make'                   => $v->make,
            'model'                  => $v->model,
            'year'                   => $v->year,
            'fuel_type'              => $v->fuel_type,
            'vehicle_type'           => $v->vehicle_type,
            'seating_capacity'       => $v->seating_capacity,
            'odometer_reading'       => $v->odometer_reading,
            'status'                 => $v->status,
            'recommendation_score'   => $score,
            'is_recommended'         => $isRecommended,
            'recommendation_reason'  => $recommendationReason,
        ];
    }

    private function inspectionsHaveDifferences(FleetBookingInspection $pre, FleetBookingInspection $post): bool
    {
        $checkFields = [
            'lights_ok', 'tyres_ok', 'brakes_ok', 'wipers_ok', 'fluids_ok',
            'first_aid_ok', 'fire_ext_ok', 'spare_tyre_ok', 'interior_ok',
        ];

        foreach ($checkFields as $field) {
            if ($pre->$field && !$post->$field) return true;
        }

        $conditionOrder = ['excellent' => 4, 'good' => 3, 'fair' => 2, 'poor' => 1];
        $preScore  = $conditionOrder[$pre->condition_overall]  ?? 0;
        $postScore = $conditionOrder[$post->condition_overall] ?? 0;
        if ($postScore < $preScore) return true;

        $preDamage  = collect($pre->damage_markers  ?? [])->pluck('id')->sort()->values();
        $postDamage = collect($post->damage_markers ?? [])->pluck('id')->sort()->values();
        if ($postDamage->diff($preDamage)->isNotEmpty()) return true;

        return false;
    }
}
