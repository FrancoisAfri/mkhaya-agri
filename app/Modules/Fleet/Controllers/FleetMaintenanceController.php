<?php

namespace App\Modules\Fleet\Controllers;

use App\Core\User\Trait\SafeNotifies;
use App\Http\Controllers\Controller;
use App\Modules\Fleet\Models\FleetMaintenance;
use App\Modules\Fleet\Models\FleetVehicle;
use App\Modules\Fleet\Models\FleetVehicleReminder;
use App\Modules\Fleet\Notifications\FleetVehicleReminderNotification;
use App\Modules\Fleet\Services\FleetPolicyGuard;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class FleetMaintenanceController extends Controller
{
    use SafeNotifies;

    public function __construct(private readonly FleetPolicyGuard $policyGuard) {}
public function index(Request $request): Response
{
    $query = FleetMaintenance::with('vehicle')
        ->orderBy('scheduled_date');

    // Filtre par recherche (véhicule, catégorie, description, prestataire)
    if ($search = $request->query('search')) {
        $query->where(function ($q) use ($search) {
            $q->whereHas('vehicle', function ($v) use ($search) {
                $v->where('registration_number', 'ilike', "%{$search}%")
                  ->orWhere('make', 'ilike', "%{$search}%")
                  ->orWhere('model', 'ilike', "%{$search}%");
            })
            ->orWhere('category', 'ilike', "%{$search}%")
            ->orWhere('description', 'ilike', "%{$search}%")
            ->orWhere('service_provider', 'ilike', "%{$search}%");
        });
    }

    // Filtre par statut
    if ($status = $request->query('status')) {
        $query->where('status', $status);
    }

    // Filtre par priorité
    if ($priority = $request->query('priority')) {
        $query->where('priority', $priority);
    }

    $maintenance = $query->paginate(15)->through(fn($m) => [
        'id'                     => $m->id,
        'vehicle_id'             => $m->vehicle_id,
        'vehicle'                => $m->vehicle?->registration_number . ' ' . $m->vehicle?->make,
        'maintenance_type'       => $m->maintenance_type,
        'category'               => $m->category,
        'description'            => $m->description,
        'scheduled_date'         => $m->scheduled_date?->format('Y-m-d'),
        'completed_date'         => $m->completed_date?->format('Y-m-d'),
        'odometer_at_service'    => $m->odometer_at_service,
        'next_service_km'        => $m->next_service_km,
        'next_service_date'      => $m->next_service_date?->format('Y-m-d'),
        'service_provider'       => $m->service_provider,
        'total_cost'             => $m->cost,
        'labour_cost'            => $m->labour_cost,
        'parts_cost'             => $m->parts_cost,
        'invoice_number'         => $m->invoice_number,
        'status'                 => $m->status,
        'priority'               => $m->priority,
        'notes'                  => $m->notes,
    ]);

    $vehicles = FleetVehicle::whereNotIn('status', ['disposed'])
        ->orderBy('registration_number')
        ->get(['id', 'registration_number', 'make', 'model', 'odometer_reading'])
        ->map(fn($v) => [
            'id'           => $v->id,
            'registration' => $v->registration_number,
            'make'         => $v->make,
            'model'        => $v->model,
        ]);

    $stats = [
        'scheduled'   => FleetMaintenance::where('status', 'scheduled')->count(),
        'in_progress' => FleetMaintenance::where('status', 'in_progress')->count(),
        'completed'   => FleetMaintenance::where('status', 'completed')->count(),
        'overdue'     => FleetMaintenance::overdue()->count(),
    ];

    return Inertia::render('Modules/Fleet/Maintenance/Index', [
        'maintenance' => $maintenance,
        'vehicles'    => $vehicles,
        'stats'       => $stats,
        'filters'     => $request->only(['search', 'status', 'priority']),
    ]);
}

   public function store(Request $request): RedirectResponse
{
    $data = $request->validate([
        'vehicle_id'              => 'required|exists:fleet_vehicles,id',
        'maintenance_type'        => 'nullable|in:scheduled,unscheduled,emergency,inspection',
        'category'                => 'nullable|in:service,tyre_replacement,brake_service,oil_change,battery_replacement,electrical,bodywork,inspection,other',
        'description'             => 'nullable|string',
        'scheduled_date'          => 'required|date',
        'completed_date'          => 'nullable|date',
        'odometer_at_service'     => 'nullable|numeric|min:0',
        'next_service_km'         => 'nullable|numeric|min:0',
        'next_service_date'       => 'nullable|date',
        'service_provider'        => 'nullable|string|max:100',
        'service_provider_contact'=> 'nullable|string|max:60',
        'cost'                    => 'nullable|numeric|min:0',
        'labour_cost'             => 'nullable|numeric|min:0',
        'parts_cost'              => 'nullable|numeric|min:0',
        'invoice_number'          => 'nullable|string|max:60',
        'status'                  => 'nullable|in:scheduled,in_progress,completed,cancelled',
        'priority'                => 'required|in:low,normal,high,emergency',
        'notes'                   => 'nullable|string',
    ]);

    $data['uuid']       = (string) Str::uuid();
    $data['status']     = $data['status'] ?? 'scheduled';
    $data['maintenance_type'] = $data['maintenance_type'] ?? 'scheduled';

    // Auto-promote to completed when a completed_date is provided
    if (!empty($data['completed_date']) && in_array($data['status'], ['scheduled', 'in_progress'])) {
        $data['status'] = 'completed';
    }

    // Calculer le coût total si non fourni
    if (empty($data['cost']) && (!empty($data['labour_cost']) || !empty($data['parts_cost']))) {
        $data['cost'] = ($data['labour_cost'] ?? 0) + ($data['parts_cost'] ?? 0);
    }

    $data['created_by'] = Auth::id();

    $this->policyGuard->forMaintenanceScheduled(
        (int) $data['vehicle_id'],
        $data['maintenance_type'],
        isset($data['cost']) ? (float) $data['cost'] : null,
        Auth::id()
    );

    if ($data['priority'] === 'emergency') {
        FleetVehicle::find($data['vehicle_id'])?->update(['status' => 'maintenance']);
    }

    $record = FleetMaintenance::create($data);

    $this->scheduleNextServiceReminder($record);

    return redirect()->back()->with('success', 'Maintenance job scheduled.');
}

    public function update(Request $request, FleetMaintenance $maintenance): RedirectResponse
    {
        $data = $request->validate([
            'maintenance_type'       => 'required|in:scheduled,unscheduled,emergency,inspection',
            'category'               => 'nullable|in:oil_change,tyres,brakes,electrical,bodywork,other',
            'description'            => 'required|string',
            'scheduled_date'         => 'nullable|date',
            'completed_date'         => 'nullable|date',
            'odometer_at_service'    => 'nullable|numeric|min:0',
            'next_service_km'        => 'nullable|numeric|min:0',
            'next_service_date'      => 'nullable|date',
            'service_provider'       => 'nullable|string|max:100',
            'service_provider_contact'=> 'nullable|string|max:60',
            'cost'                   => 'nullable|numeric|min:0',
            'labour_cost'            => 'nullable|numeric|min:0',
            'parts_cost'             => 'nullable|numeric|min:0',
            'invoice_number'         => 'nullable|string|max:60',
            'status'                 => 'required|in:scheduled,in_progress,completed,cancelled',
            'priority'               => 'required|in:low,normal,high,emergency',
            'notes'                  => 'nullable|string',
        ]);

        $data['updated_by'] = Auth::id();

        // Auto-promote to completed when a completed_date is provided
        if (!empty($data['completed_date']) && in_array($data['status'], ['scheduled', 'in_progress'])) {
            $data['status'] = 'completed';
        }

        if ($data['status'] === 'completed' && $maintenance->status !== 'completed') {
            $maintenance->vehicle?->update(['status' => 'available']);
        }

        $hadNextServiceDate = $maintenance->next_service_date;
        $maintenance->update($data);
        $maintenance->refresh();

        // Create reminder if next_service_date was newly set or changed
        if ($maintenance->next_service_date && (string) $maintenance->next_service_date !== (string) $hadNextServiceDate) {
            $this->scheduleNextServiceReminder($maintenance);
        }

        return back()->with('success', 'Maintenance record updated.');
    }

    public function destroy(FleetMaintenance $maintenance): RedirectResponse
    {
        $maintenance->update(['updated_by' => Auth::id()]);
        $maintenance->delete();
        return back()->with('success', 'Maintenance record removed.');
    }

    private function scheduleNextServiceReminder(FleetMaintenance $record): void
    {
        if (!$record->next_service_date) {
            return;
        }

        $alertDays = (int) (DB::table('fleet_settings')->where('key', 'maintenance_alert_days')->value('value') ?? 7);
        $vehicle   = $record->vehicle;

        if (!$vehicle || !Auth::check()) {
            return;
        }

        $nextServiceDate = $record->next_service_date;
        $reminderDate    = $nextServiceDate->copy()->subDays($alertDays)->startOfDay();

        $categoryLabel = $record->category ? ucwords(str_replace('_', ' ', $record->category)) : 'Service';
        $name          = "Next {$categoryLabel} Due — {$vehicle->registration_number}";
        $description   = "Next service scheduled for {$nextServiceDate->format('d M Y')}.";
        if ($record->next_service_km) {
            $description .= " Target odometer: " . number_format((float) $record->next_service_km) . " km.";
        }

        $reminder = FleetVehicleReminder::create([
            'vehicle_id'  => $vehicle->id,
            'name'        => $name,
            'description' => $description,
            'start_date'  => $reminderDate,
            'end_date'    => $nextServiceDate,
            'notified_at' => $reminderDate,
            'created_by'  => Auth::id(),
            'updated_by'  => Auth::id(),
        ]);

        $notification = new FleetVehicleReminderNotification($reminder, $vehicle->registration_number);

        /** @var \App\Core\User\Models\User $user */
        $user = Auth::user();

        if ($reminderDate->isFuture()) {
            $this->safeNotify($user, $notification->delay($reminderDate));
        } else {
            $this->safeNotify($user, $notification);
        }
    }
}
