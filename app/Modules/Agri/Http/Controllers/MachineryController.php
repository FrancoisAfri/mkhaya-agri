<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Modules\Agri\Models\FarmMachine;
use App\Modules\Agri\Models\MachineService;
use App\Modules\Agri\Models\MachineUsageLog;
use App\Modules\Agri\Services\Machinery;
use App\Modules\Agri\Support\RoleCatalog as R;
use App\Modules\Fleet\Models\FleetVehicle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module F: farm machinery — register, hours and fuel, services, disposal. A road-registered
 * tractor or bakkie can be linked to its Fleet record so licensing and road use stay there.
 */
class MachineryController extends AgriController
{
    public function __construct(private readonly Machinery $machinery) {}

    public function index(Request $request): Response
    {
        $this->allow($request, R::VIEW_MACHINERY);
        $farm = $this->farmFilter($request);
        $machines = FarmMachine::query()->when($farm, fn ($q) => $q->where('farm_id', $farm->id))->with('farm')
            ->withSum('usage as hours_logged', 'hours')->withSum('usage as fuel_litres', 'fuel_litres')
            ->orderByRaw("CASE status WHEN 'disposed' THEN 1 ELSE 0 END")->orderBy('code')->get()
            ->map(fn (FarmMachine $m) => $this->row($m));
        $ids = FarmMachine::query()->when($farm, fn ($q) => $q->where('farm_id', $farm->id))->pluck('id');

        return Inertia::render('Modules/Agri/Machinery/Index', [
            'machines' => $machines,
            'usage' => MachineUsageLog::query()->whereIn('machine_id', $ids)->with('machine:id,code,name')->orderByDesc('used_on')->limit(60)->get()
                ->map(fn ($u) => ['machine' => $u->machine?->code, 'used_on' => $u->used_on->toDateString(), 'hours' => (float) $u->hours,
                    'reading' => self::num($u->hours_reading_after), 'fuel_litres' => self::num($u->fuel_litres), 'fuel_cost' => self::num($u->fuel_cost), 'notes' => $u->notes]),
            'services' => MachineService::query()->whereIn('machine_id', $ids)->orderByDesc('serviced_on')->limit(60)->get()
                ->map(fn ($s) => ['machine_id' => $s->machine_id, 'serviced_on' => $s->serviced_on->toDateString(), 'type' => $s->service_type,
                    'hours' => self::num($s->hours_at_service), 'description' => $s->description, 'provider' => $s->provider, 'cost' => self::num($s->cost)]),
            'filters' => ['farm' => $farm?->uuid],
            'options' => [
                'farms' => $this->farmOptions(),
                'types' => FarmMachine::TYPES,
                'service_types' => MachineService::TYPES,
                'people' => $this->tenantPeopleOptions(),
                'fleet' => Schema::hasTable('fleet_vehicles') ? FleetVehicle::query()->orderBy('registration_number')->limit(500)
                    ->get(['id', 'registration_number', 'fleet_number', 'make', 'model'])
                    ->map(fn ($v) => ['id' => $v->id, 'name' => trim("{$v->registration_number} {$v->make} {$v->model}")]) : [],
            ],
            'can' => ['manage' => $request->user()->can(R::MANAGE_MACHINERY), 'log' => $request->user()->can(R::MANAGE_MACHINERY) || $request->user()->can(R::RECORD_ACTIVITIES)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_MACHINERY);
        $data = $request->validate($this->rules() + ['farm_id' => ['required', 'string'], 'code' => ['required', 'string', 'max:30'],
            'hours_reading' => ['nullable', 'numeric', 'gte:0']]);
        $farm = $this->workingFarm($request, $data['farm_id']);
        $m = $this->machinery->register($farm, collect($data)->except('farm_id')->all() + ['hours_reading' => $data['hours_reading'] ?? 0]);

        return back()->with('success', "{$m->code} {$m->name} registered.");
    }

    public function update(Request $request, FarmMachine $machine): RedirectResponse
    {
        $this->allow($request, R::MANAGE_MACHINERY);
        $this->assertWritable($request, $machine->farm_id);
        $data = $request->validate($this->rules() + ['next_service_hours' => ['nullable', 'numeric', 'gte:0'], 'next_service_on' => ['nullable', 'date']]);
        $machine->update($data);

        return back()->with('success', 'Machine updated.');
    }

    public function logUse(Request $request, FarmMachine $machine): RedirectResponse
    {
        $this->allow($request, R::MANAGE_MACHINERY, R::RECORD_ACTIVITIES);
        $this->assertWritable($request, $machine->farm_id);
        $data = $request->validate([
            'used_on' => ['required', 'date', 'before_or_equal:today'], 'hours' => ['nullable', 'numeric', 'gte:0', 'max:24'],
            'hours_reading_after' => ['nullable', 'numeric', 'gte:0'], 'fuel_litres' => ['nullable', 'numeric', 'gte:0'],
            'fuel_cost' => ['nullable', 'numeric', 'gte:0'], 'operator_id' => ['nullable', 'integer'],
            'settled_by' => ['nullable', Rule::in(['bank', 'payable'])], 'notes' => ['nullable', 'string', 'max:300'],
        ]);
        $this->machinery->logUse($machine, $data, $request->user());

        return back()->with('success', 'Use logged.');
    }

    public function service(Request $request, FarmMachine $machine): RedirectResponse
    {
        $this->allow($request, R::MANAGE_MACHINERY);
        $this->assertWritable($request, $machine->farm_id);
        $data = $request->validate([
            'serviced_on' => ['required', 'date', 'before_or_equal:today'], 'service_type' => ['required', Rule::in(MachineService::TYPES)],
            'hours_at_service' => ['nullable', 'numeric', 'gte:0'], 'description' => ['required', 'string', 'max:300'],
            'provider' => ['nullable', 'string', 'max:150'], 'cost' => ['nullable', 'numeric', 'gte:0'],
            'next_service_on' => ['nullable', 'date', 'after:serviced_on'], 'settled_by' => ['nullable', Rule::in(['bank', 'payable'])],
        ]);
        $this->machinery->service($machine, $data, $request->user());

        return back()->with('success', 'Service recorded.');
    }

    public function dispose(Request $request, FarmMachine $machine): RedirectResponse
    {
        $this->allow($request, R::MANAGE_MACHINERY);
        $this->assertWritable($request, $machine->farm_id);
        $data = $request->validate(['disposed_on' => ['required', 'date', 'before_or_equal:today'], 'reason' => ['required', 'string', 'max:300'],
            'value' => ['nullable', 'numeric', 'gte:0']]);
        $this->machinery->dispose($machine, $data['disposed_on'], $data['reason'], isset($data['value']) ? (float) $data['value'] : null);

        return back()->with('success', "{$machine->code} disposed of. Its history is kept.");
    }

    private function row(FarmMachine $m): array
    {
        return ['id' => $m->uuid, 'code' => $m->code, 'name' => $m->name, 'machine_type' => $m->machine_type, 'farm' => $m->farm?->name,
            'farm_id' => $m->farm?->uuid, 'make' => $m->make, 'model' => $m->model, 'year' => $m->year, 'serial_number' => $m->serial_number,
            'registration' => $m->registration, 'fleet_vehicle_id' => $m->fleet_vehicle_id, 'hours_reading' => (float) $m->hours_reading,
            'service_interval_hours' => self::num($m->service_interval_hours), 'next_service_hours' => self::num($m->next_service_hours),
            'next_service_on' => $m->next_service_on?->toDateString(), 'hourly_rate' => self::num($m->hourly_rate),
            'purchase_cost' => self::num($m->purchase_cost), 'purchased_on' => $m->purchased_on?->toDateString(),
            'status' => $m->status, 'disposed_on' => $m->disposed_on?->toDateString(), 'disposal_reason' => $m->disposal_reason,
            'service_due' => $m->status !== 'disposed' && $m->serviceDue(14, 10),
            'hours_logged' => round((float) ($m->hours_logged ?? 0), 1), 'fuel_litres' => round((float) ($m->fuel_litres ?? 0), 1), 'notes' => $m->notes];
    }

    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'], 'machine_type' => ['required', Rule::in(FarmMachine::TYPES)],
            'make' => ['nullable', 'string', 'max:60'], 'model' => ['nullable', 'string', 'max:60'],
            'year' => ['nullable', 'integer', 'between:1900,'.(date('Y') + 1)], 'serial_number' => ['nullable', 'string', 'max:60'],
            'registration' => ['nullable', 'string', 'max:30'], 'fleet_vehicle_id' => ['nullable', 'integer'],
            'service_interval_hours' => ['nullable', 'numeric', 'gt:0'], 'hourly_rate' => ['nullable', 'numeric', 'gte:0'],
            'purchase_cost' => ['nullable', 'numeric', 'gte:0'], 'purchased_on' => ['nullable', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
