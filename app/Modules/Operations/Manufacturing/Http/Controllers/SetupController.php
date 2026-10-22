<?php

namespace App\Modules\Operations\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Models\Assets;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\Manufacturing\Models\ApprovalLimit;
use App\Modules\Operations\Manufacturing\Models\Department;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\FactoryAssignment;
use App\Modules\Operations\Manufacturing\Models\FactoryPolicy;
use App\Modules\Operations\Manufacturing\Models\NumberSequence;
use App\Modules\Operations\Manufacturing\Models\ProductionLine;
use App\Modules\Operations\Manufacturing\Models\Shift;
use App\Modules\Operations\Manufacturing\Models\Shutdown;
use App\Modules\Operations\Manufacturing\Models\Uom;
use App\Modules\Operations\Manufacturing\Models\UomConversion;
use App\Modules\Operations\Manufacturing\Models\WorkCentre;
use App\Modules\Operations\Manufacturing\Models\Workstation;
use App\Modules\Operations\Manufacturing\Services\FactoryAccess;
use App\Modules\Operations\Manufacturing\Services\ManufacturingReferenceData;
use App\Modules\Operations\Manufacturing\Services\NumberSequences;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * MFG-001 — the manufacturing environment: factories, work centres, shifts, units, numbering and
 * policies.
 *
 * One controller because it is one job done by one person during setup, and six controllers would
 * be six places for the same `configure` authorisation to be forgotten. Every action authorises
 * against the Factory policy, which is what the hub cards gate on too.
 */
class SetupController extends Controller
{
    use \App\Modules\Operations\Manufacturing\Concerns\ScopesValidationToTenant;
    use \App\Modules\HR\Support\PicksTenantPeople;

    // ── Factories, departments and lines ─────────────────────────────────────────────────────

    public function factories(Request $request): Response
    {
        $this->authorize('configure', Factory::class);

        $assignments = FactoryAssignment::query()->with('department:id,uuid,name')->get()->groupBy('factory_id');
        $people = collect($this->tenantPeople($request))->keyBy('id');

        $factories = Factory::query()
            ->with([
                'departments' => fn ($q) => $q->orderBy('code'),
                'productionLines' => fn ($q) => $q->orderBy('code'),
            ])
            ->withCount('workCentres')
            ->orderBy('code')
            ->get()
            ->map(fn (Factory $f) => [
                'id' => $f->uuid,
                // Who is confined to this plant. An empty list is not "nobody may see it": a
                // tenant with no assignments is unrestricted, and the screen says so.
                'access' => ($assignments[$f->id] ?? collect())->map(fn (FactoryAssignment $a) => [
                    'id' => $a->uuid,
                    'user_id' => $a->user_id,
                    'name' => $people[$a->user_id]['name'] ?? "#{$a->user_id}",
                    'department' => $a->department?->name,
                    'whole_factory' => $a->department_id === null,
                ])->values()->all(),
                'code' => $f->code,
                'name' => $f->name,
                'description' => $f->description,
                'address' => $f->address,
                'timezone' => $f->timezone,
                'currency' => $f->currency,
                'tax_code' => $f->tax_code,
                'tax_rate' => $f->tax_rate !== null ? (float) $f->tax_rate : null,
                'tax_registration_number' => $f->tax_registration_number,
                'measurement_system' => $f->measurement_system,
                'default_warehouse_id' => $f->default_warehouse_id,
                'is_active' => (bool) $f->is_active,
                'work_centres_count' => (int) $f->work_centres_count,
                'departments' => $f->departments->map(fn (Department $d) => [
                    'id' => $d->uuid, 'code' => $d->code, 'name' => $d->name,
                    'manager_id' => $d->manager_id, 'is_active' => (bool) $d->is_active,
                ])->all(),
                'production_lines' => $f->productionLines->map(fn (ProductionLine $l) => [
                    'id' => $l->uuid, 'code' => $l->code, 'name' => $l->name,
                    'is_active' => (bool) $l->is_active,
                ])->all(),
            ]);

        return Inertia::render('Modules/Manufacturing/Setup/Factories', [
            'factories' => $factories,
            'people' => array_map(fn ($p) => ['id' => $p['id'], 'name' => $p['name']], array_values($people->all())),
            'can_see_all' => $request->user()->can(\App\Modules\Operations\Manufacturing\Support\RoleCatalog::ALL_FACTORIES),
            'measurement_systems' => Factory::MEASUREMENT_SYSTEMS,
            'timezones' => ['Africa/Johannesburg', 'Africa/Gaborone', 'Africa/Maputo', 'Africa/Harare', 'UTC'],
            /*
             * Warehouses come from finance. A factory is not a warehouse -- it only names the one
             * its stock lands in by default.
             */
            'warehouses' => FinWarehouse::query()->where('is_active', true)->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn ($w) => ['id' => $w->id, 'code' => $w->code, 'name' => $w->name]),
        ]);
    }

    public function storeFactory(Request $request): RedirectResponse
    {
        $this->authorize('configure', Factory::class);

        $data = $request->validate($this->factoryRules());

        // MFG-025: the package caps how many factories a company runs.
        $package = app(\App\Modules\Operations\Manufacturing\Services\ManufacturingPackage::class);
        $cap = $package->maxFactories();
        if ($cap !== null && Factory::query()->where('is_active', true)->count() >= $cap) {
            return back()->with('error', "Your {$package->definition()['label']} manufacturing package includes {$cap} factor".($cap === 1 ? 'y' : 'ies').'. Upgrade the package to add another.');
        }

        Factory::create($data + ['created_by' => $request->user()->id, 'is_active' => true]);

        return back()->with('success', 'Factory created.');
    }

    public function updateFactory(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('configure', Factory::class);

        $factory = Factory::query()->where('uuid', $uuid)->firstOrFail();
        $factory->update($request->validate($this->factoryRules($factory->id)));

        return back()->with('success', 'Factory updated.');
    }

    public function destroyFactory(string $uuid): RedirectResponse
    {
        $this->authorize('configure', Factory::class);

        $factory = Factory::query()->where('uuid', $uuid)->firstOrFail();

        /*
         * A factory with work centres is referenced by everything planned against them. Removing
         * it would orphan that history, so deactivating is the honest action and the screen says
         * which one it took.
         */
        if ($factory->workCentres()->exists()) {
            $factory->update(['is_active' => false]);

            return back()->with('success',
                'Factory deactivated rather than deleted: its work centres carry production history.');
        }

        $factory->delete();

        return back()->with('success', 'Factory deleted.');
    }

    public function storeDepartment(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('configure', Factory::class);

        $factory = Factory::query()->where('uuid', $uuid)->firstOrFail();

        $data = $request->validate([
            'code' => ['required', 'string', 'max:32',
                $this->uniqueCode('mfg_departments', ['factory_id' => $factory->id])],
            'name' => ['required', 'string', 'max:255'],
            'company_unit_id' => ['nullable', 'integer', $this->tenantExists('company_units', 'id')],
            'manager_id' => ['nullable', 'integer', $this->tenantExists('users', 'id')],
        ]);

        $factory->departments()->create($data + [
            'company_id' => $factory->company_id,
            'is_active' => true,
        ]);

        return back()->with('success', 'Department added.');
    }

    public function storeAccess(Request $request, string $uuid, FactoryAccess $access): RedirectResponse
    {
        $this->authorize('configure', Factory::class);

        $factory = Factory::query()->where('uuid', $uuid)->firstOrFail();

        $data = $request->validate([
            'user_id' => ['required', 'integer', $this->tenantExists('users', 'id')],
            'department_uuid' => ['nullable', 'string', $this->tenantExists('mfg_departments', 'uuid', true)],
        ]);

        $departmentId = null;

        if (! empty($data['department_uuid'])) {
            $department = Department::query()->where('uuid', $data['department_uuid'])->firstOrFail();

            // A department of another plant would "assign" somebody to a factory they were not
            // given, by a route that reads as though it were narrower.
            if ((int) $department->factory_id !== (int) $factory->id) {
                return back()->with('error', "{$department->name} is not a department of {$factory->name}.");
            }

            $departmentId = $department->id;
        }

        $exists = FactoryAssignment::query()
            ->where('factory_id', $factory->id)->where('user_id', $data['user_id'])
            ->when($departmentId === null, fn ($q) => $q->whereNull('department_id'))
            ->when($departmentId !== null, fn ($q) => $q->where('department_id', $departmentId))
            ->exists();

        if ($exists) {
            return back()->with('error', 'That person is already placed there.');
        }

        FactoryAssignment::create([
            'factory_id' => $factory->id,
            'user_id' => $data['user_id'],
            'department_id' => $departmentId,
            'granted_by' => $request->user()->id,
        ]);

        $access->flush();

        return back()->with('success', 'Access granted.');
    }

    public function destroyAccess(string $uuid, string $accessUuid, FactoryAccess $access): RedirectResponse
    {
        $this->authorize('configure', Factory::class);

        $factory = Factory::query()->where('uuid', $uuid)->firstOrFail();

        FactoryAssignment::query()
            ->where('factory_id', $factory->id)->where('uuid', $accessUuid)
            ->firstOrFail()->delete();

        $access->flush();

        return back()->with('success', 'Access removed.');
    }

    public function storeProductionLine(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('configure', Factory::class);

        $factory = Factory::query()->where('uuid', $uuid)->firstOrFail();

        $data = $request->validate([
            'code' => ['required', 'string', 'max:32',
                $this->uniqueCode('mfg_production_lines', ['factory_id' => $factory->id])],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $factory->productionLines()->create($data + [
            'company_id' => $factory->company_id,
            'is_active' => true,
        ]);

        return back()->with('success', 'Production line added.');
    }

    // ── Work centres ─────────────────────────────────────────────────────────────────────────

    public function workCentres(Request $request): Response
    {
        $this->authorize('configure', Factory::class);

        $centres = WorkCentre::query()
            ->with([
                'factory:id,uuid,code,name',
                'productionLine:id,uuid,name',
                'department:id,uuid,name',
                'workstations' => fn ($q) => $q->orderBy('code'),
                'machines:id,name,asset_tag',
            ])
            ->orderBy('code')
            ->get()
            ->map(fn (WorkCentre $c) => [
                'id' => $c->uuid,
                'code' => $c->code,
                'name' => $c->name,
                'factory' => $c->factory?->name,
                'factory_id' => $c->factory?->uuid,
                'production_line' => $c->productionLine?->name,
                'production_line_id' => $c->production_line_id,
                'department' => $c->department?->name,
                'department_id' => $c->department_id,
                'capacity_per_hour' => $c->capacity_per_hour !== null ? (float) $c->capacity_per_hour : null,
                'capacity_uom_id' => $c->capacity_uom_id,
                'cost_per_hour' => $c->cost_per_hour !== null ? (float) $c->cost_per_hour : null,
                'setup_minutes' => (int) $c->setup_minutes,
                'concurrent_jobs' => (int) $c->concurrent_jobs,
                // A centre that has not been rated cannot be planned or costed against.
                'is_rated' => $c->isRated(),
                'is_active' => (bool) $c->is_active,
                'workstations' => $c->workstations->map(fn (Workstation $w) => [
                    'id' => $w->uuid, 'code' => $w->code, 'name' => $w->name,
                    'is_active' => (bool) $w->is_active,
                ])->all(),
                'machines' => $c->machines->map(fn ($m) => [
                    'id' => $m->id, 'name' => $m->name, 'asset_tag' => $m->asset_tag,
                    'is_primary' => (bool) $m->pivot->is_primary,
                ])->all(),
            ]);

        return Inertia::render('Modules/Manufacturing/Setup/WorkCentres', [
            'work_centres' => $centres,
            'factories' => $this->factoryOptions(),
            /*
             * factory_id is the factory's uuid, not its internal id: the picker above is keyed on
             * uuid, and an option list carrying the other identifier cannot be filtered by it.
             */
            'production_lines' => ProductionLine::query()->with('factory:id,uuid')
                ->where('is_active', true)->orderBy('code')->get()
                ->map(fn ($l) => ['id' => $l->id, 'factory_id' => $l->factory?->uuid,
                    'code' => $l->code, 'name' => $l->name]),
            'departments' => Department::query()->with('factory:id,uuid')
                ->where('is_active', true)->orderBy('code')->get()
                ->map(fn ($d) => ['id' => $d->id, 'factory_id' => $d->factory?->uuid,
                    'code' => $d->code, 'name' => $d->name]),
            'units' => $this->unitOptions(),
            // Machines come from the asset register; this module keeps no second machine list.
            'assets' => Assets::query()->orderBy('name')->limit(300)
                ->get(['id', 'name', 'asset_tag'])
                ->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'asset_tag' => $a->asset_tag]),
        ]);
    }

    public function storeWorkCentre(Request $request): RedirectResponse
    {
        $this->authorize('configure', Factory::class);

        $request->validate(['factory_uuid' => ['required', 'string', $this->tenantExists('mfg_factories', 'uuid', true)]]);
        $factory = Factory::query()->where('uuid', $request->input('factory_uuid'))->firstOrFail();

        $data = $request->validate($this->workCentreRules($factory->id));
        unset($data['factory_uuid']);

        WorkCentre::create($data + [
            'company_id' => $factory->company_id,
            'factory_id' => $factory->id,
            'is_active' => true,
        ]);

        return back()->with('success', 'Work centre created.');
    }

    public function updateWorkCentre(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('configure', Factory::class);

        $centre = WorkCentre::query()->where('uuid', $uuid)->firstOrFail();

        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:32',
                $this->uniqueCode('mfg_work_centres', ['factory_id' => $centre->factory_id], $centre->id)],
            'name' => ['sometimes', 'string', 'max:255'],
            'production_line_id' => ['nullable', 'integer', $this->tenantExists('mfg_production_lines', 'id', true)],
            'department_id' => ['nullable', 'integer', $this->tenantExists('mfg_departments', 'id', true)],
            'capacity_per_hour' => ['nullable', 'numeric', 'gt:0'],
            'capacity_uom_id' => ['nullable', 'integer', $this->tenantExists('mfg_uoms', 'id')],
            'cost_per_hour' => ['nullable', 'numeric', 'min:0'],
            'setup_minutes' => ['nullable', 'integer', 'min:0'],
            'concurrent_jobs' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $centre->update($data);

        return back()->with('success', 'Work centre updated.');
    }

    public function destroyWorkCentre(string $uuid): RedirectResponse
    {
        $this->authorize('configure', Factory::class);

        WorkCentre::query()->where('uuid', $uuid)->firstOrFail()->delete();

        return back()->with('success', 'Work centre removed.');
    }

    public function storeWorkstation(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('configure', Factory::class);

        $centre = WorkCentre::query()->where('uuid', $uuid)->firstOrFail();

        $data = $request->validate([
            'code' => ['required', 'string', 'max:32',
                $this->uniqueCode('mfg_workstations', ['work_centre_id' => $centre->id])],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $centre->workstations()->create($data + [
            'company_id' => $centre->company_id,
            'is_active' => true,
        ]);

        return back()->with('success', 'Workstation added.');
    }

    public function attachMachine(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('configure', Factory::class);

        $centre = WorkCentre::query()->where('uuid', $uuid)->firstOrFail();

        $data = $request->validate([
            'asset_id' => ['required', 'integer', $this->tenantExists('assets', 'id')],
            'is_primary' => ['nullable', 'boolean'],
        ]);

        $centre->machines()->syncWithoutDetaching([
            $data['asset_id'] => [
                'company_id' => $centre->company_id,
                'is_primary' => (bool) ($data['is_primary'] ?? false),
            ],
        ]);

        return back()->with('success', 'Machine associated with the work centre.');
    }

    public function detachMachine(string $uuid, int $assetId): RedirectResponse
    {
        $this->authorize('configure', Factory::class);

        WorkCentre::query()->where('uuid', $uuid)->firstOrFail()->machines()->detach($assetId);

        return back()->with('success', 'Machine removed from the work centre.');
    }

    // ── Shifts and shutdowns ─────────────────────────────────────────────────────────────────

    public function shifts(Request $request, \App\Modules\Operations\Manufacturing\Services\FactoryCalendar $calendar): Response
    {
        $this->authorize('configure', Factory::class);

        /*
         * The working calendar: shifts, holidays and shutdowns combined into what a plant can
         * actually run. Two weeks from the chosen date, for one factory at a time -- the factory
         * is looked up through the access scope, so nobody previews another plant's capacity.
         */
        $input = $request->validate([
            'calendar_factory' => ['nullable', 'string'],
            'calendar_from' => ['nullable', 'date'],
        ]);

        $calendarFactory = isset($input['calendar_factory'])
            ? Factory::query()->where('uuid', $input['calendar_factory'])->first()
            : Factory::query()->where('is_active', true)->orderBy('code')->first();

        $from = isset($input['calendar_from'])
            ? \Illuminate\Support\Carbon::parse($input['calendar_from'])
            : \Illuminate\Support\Carbon::now($calendarFactory?->timezone ?: config('app.timezone'))->startOfDay();

        $days = $calendarFactory
            ? $calendar->days($calendarFactory, $from, $from->copy()->addDays(13))
            : [];

        return Inertia::render('Modules/Manufacturing/Setup/Shifts', [
            'calendar' => [
                'factory_id' => $calendarFactory?->uuid,
                'from' => $from->toDateString(),
                'days' => $days,
                'total_minutes' => array_sum(array_column($days, 'minutes')),
            ],
            'shifts' => Shift::query()->with('factory:id,uuid,name')->orderBy('code')->get()
                ->map(fn (Shift $s) => [
                    'id' => $s->uuid,
                    'code' => $s->code,
                    'name' => $s->name,
                    'factory' => $s->factory?->name,
                    'factory_id' => $s->factory?->uuid,
                    'starts_at' => substr((string) $s->getAttributes()['starts_at'], 0, 5),
                    'ends_at' => substr((string) $s->getAttributes()['ends_at'], 0, 5),
                    'crosses_midnight' => (bool) $s->crosses_midnight,
                    'break_minutes' => (int) $s->break_minutes,
                    'days_of_week' => $s->days_of_week ?? [],
                    'working_minutes' => $s->workingMinutes(),
                    'overtime_after_minutes' => $s->overtime_after_minutes,
                    'overtime_multiplier' => (float) $s->overtime_multiplier,
                    'observes_public_holidays' => (bool) $s->observes_public_holidays,
                    'is_active' => (bool) $s->is_active,
                ]),
            'shutdowns' => Shutdown::query()
                ->with(['factory:id,uuid,name,timezone', 'productionLine:id,uuid,name'])
                ->orderByDesc('starts_at')->limit(50)->get()
                ->map(fn (Shutdown $s) => [
                    'id' => $s->uuid,
                    'factory' => $s->factory?->name,
                    'line' => $s->productionLine?->name,
                    // Null line means the factory stops, which the screen must not read as "none".
                    'whole_factory' => $s->production_line_id === null,
                    'reason' => $s->reason,
                    'kind' => $s->kind,
                    'starts_at' => $s->starts_at?->toIso8601String(),
                    'ends_at' => $s->ends_at?->toIso8601String(),
                    // Shown on the plant's own clock, not the viewer's.
                    'timezone' => $s->factory?->timezone,
                ]),
            'factories' => $this->factoryOptions(),
            'production_lines' => ProductionLine::query()->with('factory:id,uuid')
                ->where('is_active', true)->orderBy('code')->get()
                ->map(fn ($l) => ['id' => $l->id, 'factory_id' => $l->factory?->uuid,
                    'code' => $l->code, 'name' => $l->name]),
            'shutdown_kinds' => Shutdown::KINDS,
        ]);
    }

    public function storeShift(Request $request): RedirectResponse
    {
        $this->authorize('configure', Factory::class);

        $request->validate(['factory_uuid' => ['required', 'string', $this->tenantExists('mfg_factories', 'uuid', true)]]);
        $factoryForCode = Factory::query()->where('uuid', $request->input('factory_uuid'))->firstOrFail();

        $data = $request->validate([
            'factory_uuid' => ['required', 'string', $this->tenantExists('mfg_factories', 'uuid', true)],
            'code' => ['required', 'string', 'max:32',
                $this->uniqueCode('mfg_shifts', ['factory_id' => $factoryForCode->id])],
            'name' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i'],
            'crosses_midnight' => ['nullable', 'boolean'],
            'break_minutes' => ['nullable', 'integer', 'min:0'],
            'days_of_week' => ['required', 'array', 'min:1'],
            'days_of_week.*' => ['integer', 'between:1,7'],
            'overtime_after_minutes' => ['nullable', 'integer', 'min:0'],
            'overtime_multiplier' => ['nullable', 'numeric', 'min:1'],
            'observes_public_holidays' => ['nullable', 'boolean'],
        ]);

        $factory = Factory::query()->where('uuid', $data['factory_uuid'])->firstOrFail();
        unset($data['factory_uuid']);

        Shift::create($data + [
            'company_id' => $factory->company_id,
            'factory_id' => $factory->id,
            'break_minutes' => $data['break_minutes'] ?? 0,
            'overtime_multiplier' => $data['overtime_multiplier'] ?? 1.5,
            'is_active' => true,
        ]);

        return back()->with('success', 'Shift created.');
    }

    public function updateShift(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('configure', Factory::class);

        $shift = Shift::query()->where('uuid', $uuid)->firstOrFail();

        $shift->update($request->validate([
            'code' => ['sometimes', 'string', 'max:32',
                $this->uniqueCode('mfg_shifts', ['factory_id' => $shift->factory_id], $shift->id)],
            'name' => ['sometimes', 'string', 'max:255'],
            'starts_at' => ['sometimes', 'date_format:H:i'],
            'ends_at' => ['sometimes', 'date_format:H:i'],
            'crosses_midnight' => ['nullable', 'boolean'],
            'break_minutes' => ['nullable', 'integer', 'min:0'],
            'days_of_week' => ['sometimes', 'array', 'min:1'],
            'days_of_week.*' => ['integer', 'between:1,7'],
            'overtime_after_minutes' => ['nullable', 'integer', 'min:0'],
            'overtime_multiplier' => ['nullable', 'numeric', 'min:1'],
            'observes_public_holidays' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]));

        return back()->with('success', 'Shift updated.');
    }

    public function destroyShift(string $uuid): RedirectResponse
    {
        $this->authorize('configure', Factory::class);

        Shift::query()->where('uuid', $uuid)->firstOrFail()->delete();

        return back()->with('success', 'Shift removed.');
    }

    public function storeShutdown(Request $request): RedirectResponse
    {
        $this->authorize('configure', Factory::class);

        $data = $request->validate([
            'factory_uuid' => ['required', 'string', $this->tenantExists('mfg_factories', 'uuid', true)],
            'production_line_id' => ['nullable', 'integer', $this->tenantExists('mfg_production_lines', 'id', true)],
            'reason' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::in(Shutdown::KINDS)],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ]);

        $factory = Factory::query()->where('uuid', $data['factory_uuid'])->firstOrFail();
        unset($data['factory_uuid']);

        /*
         * A time typed into the form is the factory's wall-clock time -- the browser sends no zone.
         * Read it in the factory's own timezone, or a shutdown entered as 06:00 in Johannesburg is
         * stored as 06:00 UTC and stops the line two hours late.
         */
        $tz = $factory->timezone ?: config('app.timezone');
        $data['starts_at'] = \Illuminate\Support\Carbon::parse($data['starts_at'], $tz);
        $data['ends_at'] = \Illuminate\Support\Carbon::parse($data['ends_at'], $tz);

        Shutdown::create($data + [
            'company_id' => $factory->company_id,
            'factory_id' => $factory->id,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Shutdown recorded.');
    }

    public function destroyShutdown(string $uuid): RedirectResponse
    {
        $this->authorize('configure', Factory::class);

        Shutdown::query()->where('uuid', $uuid)->firstOrFail()->delete();

        return back()->with('success', 'Shutdown removed.');
    }

    // ── Units of measure ─────────────────────────────────────────────────────────────────────

    public function units(Request $request): Response
    {
        $this->authorize('configure', Factory::class);

        return Inertia::render('Modules/Manufacturing/Setup/Units', [
            'units' => Uom::query()->orderBy('dimension')->orderByDesc('is_base')->orderBy('code')
                ->get()
                ->map(fn (Uom $u) => [
                    'id' => $u->id,
                    'uuid' => $u->uuid,
                    'code' => $u->code,
                    'name' => $u->name,
                    'dimension' => $u->dimension,
                    'is_base' => (bool) $u->is_base,
                    'factor_to_base' => (float) $u->factor_to_base,
                    'decimal_places' => (int) $u->decimal_places,
                    'is_active' => (bool) $u->is_active,
                ]),
            'dimensions' => Uom::DIMENSIONS,
            'conversions' => UomConversion::query()
                ->with(['fromUom:id,code', 'toUom:id,code'])
                ->get()
                ->map(fn (UomConversion $c) => [
                    'id' => $c->id,
                    'stock_item_id' => $c->stock_item_id,
                    'from' => $c->fromUom?->code,
                    'to' => $c->toUom?->code,
                    'factor' => (float) $c->factor,
                ]),
            'stock_items' => FinStockItem::query()->orderBy('code')->limit(300)
                ->get(['id', 'code', 'name'])
                ->map(fn ($i) => ['id' => $i->id, 'code' => $i->code, 'name' => $i->name]),
        ]);
    }

    public function storeUnit(Request $request): RedirectResponse
    {
        $this->authorize('configure', Factory::class);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:16', $this->uniqueCode('mfg_uoms', [], null, false)],
            'name' => ['required', 'string', 'max:255'],
            'dimension' => ['required', Rule::in(Uom::DIMENSIONS)],
            'factor_to_base' => ['required', 'numeric', 'gt:0'],
            'decimal_places' => ['nullable', 'integer', 'between:0,6'],
        ]);

        /*
         * A dimension has exactly one base unit, and the database enforces it with a partial
         * unique index. Saying so here means the user gets a sentence rather than a constraint
         * violation.
         */
        $hasBase = Uom::query()->where('dimension', $data['dimension'])->where('is_base', true)->exists();

        if (! $hasBase) {
            return back()->with('error',
                "There is no base unit for {$data['dimension']} yet. Install the standard units "
                .'first, or the conversions for this dimension have nothing to anchor to.');
        }

        Uom::create($data + [
            'is_base' => false,
            'decimal_places' => $data['decimal_places'] ?? 3,
            'is_active' => true,
        ]);

        return back()->with('success', 'Unit of measure added.');
    }

    public function updateUnit(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('configure', Factory::class);

        $unit = Uom::query()->where('uuid', $uuid)->firstOrFail();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'factor_to_base' => ['sometimes', 'numeric', 'gt:0'],
            'decimal_places' => ['nullable', 'integer', 'between:0,6'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        // The base unit's factor is 1 by definition; changing it would rescale every conversion.
        if ($unit->is_base && isset($data['factor_to_base']) && (float) $data['factor_to_base'] !== 1.0) {
            return back()->with('error',
                "{$unit->code} is the base unit for {$unit->dimension}, so its factor is 1 by "
                .'definition. Changing it would rescale every quantity in that dimension.');
        }

        $unit->update($data);

        return back()->with('success', 'Unit updated.');
    }

    public function installReferenceData(Request $request, ManufacturingReferenceData $reference): RedirectResponse
    {
        $this->authorize('configure', Factory::class);

        $companyId = $request->user()->company_id;

        if (! $companyId) {
            return back()->with('error',
                'No company is in context, so there is nothing to install the reference data against.');
        }

        $result = $reference->installFor((int) $companyId);

        return back()->with('success', sprintf(
            'Installed %d units of measure and %d document sequences.%s',
            $result['units'],
            $result['sequences'],
            $result['policy'] ? ' Default policy created.' : '',
        ));
    }

    public function storeConversion(Request $request): RedirectResponse
    {
        $this->authorize('configure', Factory::class);

        $data = $request->validate([
            'stock_item_id' => ['required', 'integer', $this->tenantExists('fin_stock_items', 'id')],
            'from_uom_id' => ['required', 'integer', $this->tenantExists('mfg_uoms', 'id')],
            'to_uom_id' => ['required', 'integer', 'different:from_uom_id', $this->tenantExists('mfg_uoms', 'id')],
            'factor' => ['required', 'numeric', 'gt:0'],
        ]);

        $from = Uom::query()->findOrFail($data['from_uom_id']);
        $to = Uom::query()->findOrFail($data['to_uom_id']);

        /*
         * kg to g is already answered by the dimension. A per-item row for it would be a second,
         * competing answer, and the converter prefers the item's -- so one typo here would
         * silently re-scale every recipe using that item.
         */
        if ($from->isCompatibleWith($to)) {
            return back()->with('error',
                "{$from->code} and {$to->code} are both {$from->dimension}, so they already convert "
                .'exactly. A per-item conversion is only for units that cannot be derived, such as '
                .'a pack or a drum.');
        }

        UomConversion::create($data);

        return back()->with('success', 'Item conversion recorded.');
    }

    public function destroyConversion(int $id): RedirectResponse
    {
        $this->authorize('configure', Factory::class);

        UomConversion::query()->where('id', $id)->firstOrFail()->delete();

        return back()->with('success', 'Conversion removed.');
    }

    // ── Numbering ────────────────────────────────────────────────────────────────────────────

    public function numbering(Request $request, NumberSequences $sequences): Response
    {
        $this->authorize('configure', Factory::class);

        return Inertia::render('Modules/Manufacturing/Setup/Numbering', [
            'sequences' => NumberSequence::query()->with('factory:id,uuid,name')
                ->orderBy('document_type')->get()
                ->map(fn (NumberSequence $s) => [
                    'id' => $s->uuid,
                    'document_type' => $s->document_type,
                    'factory' => $s->factory?->name,
                    'factory_id' => $s->factory?->uuid,
                    // Null factory means company-wide, not "unassigned".
                    'is_company_wide' => $s->factory_id === null,
                    'prefix' => $s->prefix,
                    'suffix' => $s->suffix,
                    'padding' => (int) $s->padding,
                    'next_number' => (int) $s->next_number,
                    'reset_period' => $s->reset_period,
                    'is_active' => (bool) $s->is_active,
                    // What the next document will actually be called, period segment included.
                    'preview' => $sequences->preview((int) $s->company_id, $s->document_type,
                        $s->factory_id ? (int) $s->factory_id : null),
                ]),
            'document_types' => array_keys(ManufacturingReferenceData::documentTypes()),
            'reset_periods' => NumberSequence::RESET_PERIODS,
            'factories' => $this->factoryOptions(),
        ]);
    }

    public function storeSequence(Request $request): RedirectResponse
    {
        $this->authorize('configure', Factory::class);

        $data = $request->validate([
            'document_type' => ['required', 'string', 'max:48'],
            'factory_uuid' => ['nullable', 'string', $this->tenantExists('mfg_factories', 'uuid', true)],
            'prefix' => ['nullable', 'string', 'max:16'],
            'suffix' => ['nullable', 'string', 'max:16'],
            'padding' => ['required', 'integer', 'between:1,12'],
            'next_number' => ['required', 'integer', 'min:1'],
            'reset_period' => ['required', Rule::in(NumberSequence::RESET_PERIODS)],
        ]);

        $factoryId = null;

        if (! empty($data['factory_uuid'])) {
            $factoryId = Factory::query()->where('uuid', $data['factory_uuid'])->firstOrFail()->id;
        }

        unset($data['factory_uuid']);

        $exists = NumberSequence::query()
            ->where('document_type', $data['document_type'])
            ->when($factoryId === null, fn ($q) => $q->whereNull('factory_id'))
            ->when($factoryId !== null, fn ($q) => $q->where('factory_id', $factoryId))
            ->exists();

        if ($exists) {
            return back()->with('error',
                'A sequence already exists for that document type and scope. Two would hand out '
                .'the same number from both.');
        }

        NumberSequence::create($data + [
            'factory_id' => $factoryId,
            'prefix' => $data['prefix'] ?? '',
            'suffix' => $data['suffix'] ?? '',
            'is_active' => true,
        ]);

        return back()->with('success', 'Number sequence created.');
    }

    public function updateSequence(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('configure', Factory::class);

        $sequence = NumberSequence::query()->where('uuid', $uuid)->firstOrFail();

        $data = $request->validate([
            'prefix' => ['nullable', 'string', 'max:16'],
            'suffix' => ['nullable', 'string', 'max:16'],
            'padding' => ['sometimes', 'integer', 'between:1,12'],
            'next_number' => ['sometimes', 'integer', 'min:1'],
            'reset_period' => ['sometimes', Rule::in(NumberSequence::RESET_PERIODS)],
            'is_active' => ['nullable', 'boolean'],
        ]);

        /*
         * Winding a sequence backwards would re-issue numbers that are already on documents, and
         * the duplicate only surfaces when somebody reconciles two job cards with the same id.
         */
        if (isset($data['next_number']) && (int) $data['next_number'] < (int) $sequence->next_number) {
            return back()->with('error', sprintf(
                'This sequence has already issued up to %d. Setting it back to %d would re-issue '
                .'numbers that are on documents already.',
                (int) $sequence->next_number - 1,
                (int) $data['next_number'],
            ));
        }

        $sequence->update($data);

        return back()->with('success', 'Number sequence updated.');
    }

    // ── Policies, tolerances and approval limits ─────────────────────────────────────────────

    public function policies(Request $request): Response
    {
        $this->authorize('configure', Factory::class);

        return Inertia::render('Modules/Manufacturing/Setup/Policies', [
            'policies' => FactoryPolicy::query()->with('factory:id,uuid,name')->get()
                ->map(fn (FactoryPolicy $p) => [
                    'id' => $p->id,
                    'factory' => $p->factory?->name,
                    'factory_id' => $p->factory?->uuid,
                    // Null factory is the company default that factories inherit.
                    'is_company_default' => $p->factory_id === null,
                    'scrap_tolerance_percent' => $p->scrap_tolerance_percent !== null
                        ? (float) $p->scrap_tolerance_percent : null,
                    'yield_tolerance_percent' => $p->yield_tolerance_percent !== null
                        ? (float) $p->yield_tolerance_percent : null,
                    'cost_variance_tolerance_percent' => $p->cost_variance_tolerance_percent !== null
                        ? (float) $p->cost_variance_tolerance_percent : null,
                    'require_qc_before_putaway' => (bool) $p->require_qc_before_putaway,
                    'allow_negative_stock' => (bool) $p->allow_negative_stock,
                    'allow_over_issue' => (bool) $p->allow_over_issue,
                    'require_bom_approval' => (bool) $p->require_bom_approval,
                    'escalate_after_hours' => (int) $p->escalate_after_hours,
                ]),
            'limits' => ApprovalLimit::query()->with('factory:id,uuid,name')
                ->orderBy('document_type')->orderBy('min_amount')->get()
                ->map(fn (ApprovalLimit $l) => [
                    'id' => $l->uuid,
                    'document_type' => $l->document_type,
                    'factory' => $l->factory?->name,
                    'min_amount' => (float) $l->min_amount,
                    // Null max means "and everything above", not "no limit configured".
                    'max_amount' => $l->max_amount !== null ? (float) $l->max_amount : null,
                    'required_permission' => $l->required_permission,
                    'escalate_after_hours' => $l->escalate_after_hours,
                    'is_active' => (bool) $l->is_active,
                ]),
            'factories' => $this->factoryOptions(),
            // Bands are checked when a production order is approved; offering other documents
            // would let somebody configure a band that is never enforced.
            'limit_document_types' => ApprovalLimit::DOCUMENT_TYPES,
            'approvers' => $this->approverOptions(ApprovalLimit::query()->pluck('required_permission')->all()),
        ]);
    }

    /**
     * The permissions a band can ask for, in words, with how many people in the organisation hold
     * each: a band nobody can approve stops every order in its range.
     */
    private function approverOptions(array $inUse): array
    {
        $names = array_flip((new \ReflectionClass(\App\Modules\Operations\Manufacturing\Support\RoleCatalog::class))->getConstants());
        $companyId = app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id();

        return collect([...ApprovalLimit::APPROVER_PERMISSIONS, ...$inUse])->unique()->values()
            ->map(function (string $permission) use ($names, $companyId) {
                try {
                    $holders = \App\Core\User\Models\User::permission($permission)->where('company_id', $companyId)->where('status', 1)->count();
                } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist) {
                    $holders = 0;
                }

                return ['value' => $permission, 'key' => strtolower($names[$permission] ?? ''), 'holders' => $holders];
            })->all();
    }

    public function saveFactoryPolicy(Request $request): RedirectResponse
    {
        $this->authorize('configure', Factory::class);

        $data = $request->validate([
            'factory_uuid' => ['nullable', 'string', $this->tenantExists('mfg_factories', 'uuid', true)],
            'scrap_tolerance_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'yield_tolerance_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cost_variance_tolerance_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'require_qc_before_putaway' => ['nullable', 'boolean'],
            'allow_negative_stock' => ['nullable', 'boolean'],
            'allow_over_issue' => ['nullable', 'boolean'],
            'require_bom_approval' => ['nullable', 'boolean'],
            'escalate_after_hours' => ['nullable', 'integer', 'between:1,720'],
        ]);

        $factoryId = null;

        if (! empty($data['factory_uuid'])) {
            $factoryId = Factory::query()->where('uuid', $data['factory_uuid'])->firstOrFail()->id;
        }

        unset($data['factory_uuid']);

        $policy = FactoryPolicy::query()
            ->when($factoryId === null, fn ($q) => $q->whereNull('factory_id'))
            ->when($factoryId !== null, fn ($q) => $q->where('factory_id', $factoryId))
            ->first();

        if ($policy) {
            $policy->update($data);
        } else {
            FactoryPolicy::create($data + ['factory_id' => $factoryId]);
        }

        return back()->with('success', $factoryId === null
            ? 'Company default policy saved.'
            : 'Factory policy saved.');
    }

    public function storeApprovalLimit(Request $request): RedirectResponse
    {
        $this->authorize('configure', Factory::class);

        $data = $request->validate([
            'factory_uuid' => ['nullable', 'string', $this->tenantExists('mfg_factories', 'uuid', true)],
            'document_type' => ['required', Rule::in(ApprovalLimit::DOCUMENT_TYPES)],
            'min_amount' => ['required', 'numeric', 'min:0'],
            'max_amount' => ['nullable', 'numeric', 'gt:min_amount'],
            'required_permission' => ['required', Rule::in(\App\Modules\Operations\Manufacturing\Support\RoleCatalog::all())],
            'escalate_after_hours' => ['nullable', 'integer', 'between:1,720'],
        ]);

        $factoryId = null;

        if (! empty($data['factory_uuid'])) {
            $factoryId = Factory::query()->where('uuid', $data['factory_uuid'])->firstOrFail()->id;
        }

        unset($data['factory_uuid']);

        // Two bands over the same amounts would leave it to chance which one an order meets.
        $min = (float) $data['min_amount'];
        $max = isset($data['max_amount']) ? (float) $data['max_amount'] : null;
        $clash = ApprovalLimit::query()->where('document_type', $data['document_type'])->where('is_active', true)
            ->when($factoryId, fn ($q) => $q->where('factory_id', $factoryId), fn ($q) => $q->whereNull('factory_id'))
            ->get()->first(fn (ApprovalLimit $l) => ($max === null || $max > (float) $l->min_amount)
                && ($l->max_amount === null || $min < (float) $l->max_amount));
        if ($clash) {
            throw ValidationException::withMessages(['min_amount' => sprintf('This overlaps the band from %s %s; adjust the amounts so each order value falls in one band.',
                number_format((float) $clash->min_amount, 2), $clash->max_amount === null ? 'and above' : 'up to '.number_format((float) $clash->max_amount, 2))]);
        }

        ApprovalLimit::create($data + ['factory_id' => $factoryId, 'is_active' => true]);

        return back()->with('success', 'Approval band added.');
    }

    public function destroyApprovalLimit(string $uuid): RedirectResponse
    {
        $this->authorize('configure', Factory::class);

        ApprovalLimit::query()->where('uuid', $uuid)->firstOrFail()->delete();

        return back()->with('success', 'Approval band removed.');
    }

    /**
     * A code unique inside its parent, for this tenant, ignoring removed rows.
     *
     * The database holds the same constraint, but a violation there is a 500 with a SQL state; the
     * user needs "that code is taken", beside the field, in their own words.
     */
    private function uniqueCode(string $table, array $scope = [], ?int $ignoreId = null, bool $softDeletes = true): \Illuminate\Validation\Rules\Unique
    {
        $rule = Rule::unique($table, 'code')->where('company_id', request()->user()->company_id);

        foreach ($scope as $column => $value) {
            $rule->where($column, $value);
        }

        if ($softDeletes) {
            $rule->whereNull('deleted_at');
        }

        return $ignoreId ? $rule->ignore($ignoreId) : $rule;
    }

    // ── Shared option lists and rules ────────────────────────────────────────────────────────

    private function factoryOptions()
    {
        return Factory::query()->where('is_active', true)->orderBy('code')
            ->get(['id', 'uuid', 'code', 'name'])
            ->map(fn (Factory $f) => ['id' => $f->uuid, 'code' => $f->code, 'name' => $f->name]);
    }

    private function unitOptions()
    {
        return Uom::query()->where('is_active', true)->orderBy('dimension')->orderBy('code')
            ->get(['id', 'code', 'name', 'dimension'])
            ->map(fn (Uom $u) => ['id' => $u->id, 'code' => $u->code, 'name' => $u->name,
                'dimension' => $u->dimension]);
    }

    private function factoryRules(?int $ignoreId = null): array
    {
        return [
            'code' => [
                'required', 'string', 'max:32',
                Rule::unique('mfg_factories', 'code')
                    ->where('company_id', request()->user()->company_id)
                    ->whereNull('deleted_at')
                    ->ignore($ignoreId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'timezone' => ['required', 'string', 'timezone'],
            'currency' => ['required', 'string', 'size:3'],
            'tax_code' => ['nullable', 'string', 'max:16'],
            // A percentage. NULL is "not configured", which is not the same as zero-rated.
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax_registration_number' => ['nullable', 'string', 'max:64'],
            'measurement_system' => ['required', Rule::in(Factory::MEASUREMENT_SYSTEMS)],
            'default_warehouse_id' => ['nullable', 'integer', $this->tenantExists('fin_warehouses', 'id')],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    private function workCentreRules(int $factoryId): array
    {
        return [
            'factory_uuid' => ['required', 'string', $this->tenantExists('mfg_factories', 'uuid', true)],
            'code' => ['required', 'string', 'max:32',
                $this->uniqueCode('mfg_work_centres', ['factory_id' => $factoryId])],
            'name' => ['required', 'string', 'max:255'],
            'production_line_id' => ['nullable', 'integer', $this->tenantExists('mfg_production_lines', 'id', true)],
            'department_id' => ['nullable', 'integer', $this->tenantExists('mfg_departments', 'id', true)],
            'capacity_per_hour' => ['nullable', 'numeric', 'gt:0'],
            'capacity_uom_id' => ['nullable', 'integer', $this->tenantExists('mfg_uoms', 'id')],
            'cost_per_hour' => ['nullable', 'numeric', 'min:0'],
            'setup_minutes' => ['nullable', 'integer', 'min:0'],
            'concurrent_jobs' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
