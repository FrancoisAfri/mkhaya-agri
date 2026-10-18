<?php

namespace App\Modules\Operations\WarehouseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\HR\Hr\Models\Attendance\AttendanceShift;
use App\Modules\HR\Hr\Models\Attendance\AttendanceWorkCalendar;
use App\Modules\HR\Support\PicksTenantPeople;
use App\Modules\Operations\Manufacturing\Concerns\ScopesValidationToTenant;
use App\Modules\Operations\WarehouseManagement\Concerns\RefusesPolitely;
use App\Modules\Operations\WarehouseManagement\Models\WarehouseStaff;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseDirectory;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** WMS-003: warehouse configuration, its people, and the optional link to a SHEQ site. */
class WarehouseController extends Controller
{
    use PicksTenantPeople, RefusesPolitely, ScopesValidationToTenant;

    public function __construct(private readonly WarehouseDirectory $directory) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', FinWarehouse::class);

        $staff = WarehouseStaff::query()->with('shift:id,name')->get()->groupBy('warehouse_id');
        $people = collect($this->tenantPeopleOptions($request))->keyBy('id');
        $cid = app(CurrentCompany::class)->id() ?? $request->user()?->company_id;

        return Inertia::render('Modules/Warehouse/Layout/Warehouses', [
            'warehouses' => FinWarehouse::query()->orderByDesc('is_active')->orderBy('code')->get()
                ->map(fn (FinWarehouse $w) => [
                    'id' => $w->uuid,
                    'code' => $w->code, 'name' => $w->name, 'address' => $w->address,
                    'is_active' => $w->is_active, 'is_default' => $w->is_default,
                    'warehouse_type' => $w->warehouse_type, 'timezone' => $w->timezone,
                    'work_calendar_id' => $w->work_calendar_id, 'manager_user_id' => $w->manager_user_id,
                    'manager' => $w->manager_user_id ? ($people[$w->manager_user_id]['name'] ?? null) : null,
                    'sheq_site_id' => $w->sheq_site_id, 'sync_from_site' => $w->sync_from_site,
                    'site_synced_at' => $w->site_synced_at?->toIso8601String(),
                    'capacity_basis' => $w->capacity_basis,
                    'capacity_value' => $w->capacity_value !== null ? (float) $w->capacity_value : null,
                    'opening_time' => $w->opening_time ? substr($w->opening_time, 0, 5) : null,
                    'closing_time' => $w->closing_time ? substr($w->closing_time, 0, 5) : null,
                    'dispatch_cutoff_time' => $w->dispatch_cutoff_time ? substr($w->dispatch_cutoff_time, 0, 5) : null,
                    'permitted_categories' => $w->permitted_categories ?? [],
                    'policies' => array_replace(WarehouseDirectory::POLICY_DEFAULTS, $w->policies ?? []),
                    'is_wms_managed' => $w->is_wms_managed,
                    'staff' => ($staff[$w->id] ?? collect())->map(fn (WarehouseStaff $s) => [
                        'id' => $s->uuid, 'user_id' => $s->user_id,
                        'name' => $people[$s->user_id]['name'] ?? '#'.$s->user_id,
                        'shift' => $s->shift?->name, 'activities' => $s->activities, 'is_active' => $s->is_active,
                    ])->values(),
                ]),
            'types' => WarehouseDirectory::TYPES,
            'capacity_bases' => WarehouseDirectory::CAPACITY_BASES,
            'activities' => WarehouseStaff::ACTIVITIES,
            'policy_defaults' => WarehouseDirectory::POLICY_DEFAULTS,
            'timezones' => \DateTimeZone::listIdentifiers(),
            'people' => array_values($people->all()),
            // Named by company explicitly: these models carry no tenant scope of their own, and a
            // platform administrator's connection has no tenant stamped for RLS to apply.
            'calendars' => AttendanceWorkCalendar::query()->when($cid, fn ($q) => $q->where('company_id', $cid))->orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['id' => $c->id, 'name' => $c->name]),
            'shifts' => AttendanceShift::query()->when($cid, fn ($q) => $q->where('company_id', $cid))->orderBy('name')->get(['id', 'name'])->map(fn ($s) => ['id' => $s->id, 'name' => $s->name]),
            'sites' => DB::table('sheq_sites')->whereNull('deleted_at')->where('is_active', true)
                ->when($cid, fn ($q) => $q->where('company_id', $cid))
                ->orderBy('name')->get(['id', 'name', 'timezone']),
            'categories' => DB::table('mfg_item_categories')
                ->when($cid, fn ($q) => $q->where('company_id', $cid))
                ->orderBy('name')->pluck('name')->unique()->values(),
            'can' => [
                'manage' => $request->user()->can('create', FinWarehouse::class),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', FinWarehouse::class);
        $data = $this->validated($request, null);

        return $this->attempt(fn () => $this->directory->save($data), 'Warehouse created.');
    }

    public function update(Request $request, string $uuid): RedirectResponse
    {
        $warehouse = $this->find($uuid);
        $this->authorize('update', $warehouse);
        $data = $this->validated($request, $warehouse);

        return $this->attempt(fn () => $this->directory->save($data, $warehouse), 'Warehouse saved.');
    }

    public function destroy(Request $request, string $uuid): RedirectResponse
    {
        $warehouse = $this->find($uuid);
        $this->authorize('delete', $warehouse);

        return $this->attempt(fn () => $this->directory->deactivate($warehouse, $request->input('reason')), 'Warehouse retired.');
    }

    public function sync(string $uuid): RedirectResponse
    {
        $warehouse = $this->find($uuid);
        $this->authorize('update', $warehouse);

        return $this->attempt(fn () => $this->directory->syncFromSite($warehouse), 'Synced from the site.');
    }

    public function storeStaff(Request $request, string $uuid): RedirectResponse
    {
        $warehouse = $this->find($uuid);
        $this->authorize('update', $warehouse);

        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::in($this->tenantPeopleIds($request))],
            'shift_id' => ['nullable', 'integer', $this->tenantExists('attendance_shifts', 'id', true)],
            'activities' => ['required', 'array', 'min:1'],
            'activities.*' => [Rule::in(WarehouseStaff::ACTIVITIES)],
        ]);

        return $this->attempt(fn () => $this->directory->assignStaff($warehouse, (int) $data['user_id'], $data['activities'], $data['shift_id'] ?? null), 'Person assigned.');
    }

    public function destroyStaff(string $uuid, string $staff): RedirectResponse
    {
        $warehouse = $this->find($uuid);
        $this->authorize('update', $warehouse);
        $row = WarehouseStaff::query()->where('warehouse_id', $warehouse->id)->where('uuid', $staff)->firstOrFail();

        return $this->attempt(fn () => $this->directory->removeStaff($row), 'Person removed.');
    }

    private function find(string $uuid): FinWarehouse
    {
        return FinWarehouse::query()->where('uuid', $uuid)->firstOrFail();
    }

    private function validated(Request $request, ?FinWarehouse $warehouse): array
    {
        $policyKeys = array_keys(WarehouseDirectory::POLICY_DEFAULTS);

        return $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_\-\.]+$/'],
            'name' => ['required', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'is_default' => ['boolean'],
            'warehouse_type' => ['required', Rule::in(WarehouseDirectory::TYPES)],
            'timezone' => ['required', 'timezone'],
            'work_calendar_id' => ['nullable', 'integer', $this->tenantExists('attendance_work_calendars', 'id', true)],
            'manager_user_id' => ['nullable', 'integer', Rule::in($this->tenantPeopleIds($request))],
            'sheq_site_id' => ['nullable', 'integer', $this->tenantExists('sheq_sites', 'id', true)],
            'sync_from_site' => ['boolean'],
            'capacity_basis' => ['nullable', Rule::in(WarehouseDirectory::CAPACITY_BASES)],
            'capacity_value' => ['nullable', 'numeric', 'min:0'],
            'opening_time' => ['nullable', 'date_format:H:i'],
            'closing_time' => ['nullable', 'date_format:H:i'],
            'dispatch_cutoff_time' => ['nullable', 'date_format:H:i'],
            'permitted_categories' => ['nullable', 'array'],
            'permitted_categories.*' => ['string', 'max:120'],
            'policies' => ['nullable', 'array:'.implode(',', $policyKeys)],
            'is_wms_managed' => ['boolean'],
        ]);
    }
}
