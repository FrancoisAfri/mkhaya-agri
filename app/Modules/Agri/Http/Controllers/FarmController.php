<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\FarmLocation;
use App\Modules\Agri\Models\FarmMembership;
use App\Modules\Agri\Services\FarmAccess;
use App\Modules\Agri\Services\FarmRegistry;
use App\Modules\Agri\Support\RoleCatalog;
use App\Modules\HR\Support\PicksTenantPeople;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Farm registration and multi-farm management (spec Module A).
 *
 * Every farm read goes through Farm's access scope, so a farm the user may not reach is a 404 —
 * the request is refused without confirming the farm exists (spec 3.4).
 */
class FarmController extends Controller
{
    use PicksTenantPeople;

    public function __construct(private FarmRegistry $registry, private FarmAccess $access) {}

    public function index(Request $request): Response
    {
        $this->allow($request, RoleCatalog::VIEW_FARMS);

        $status = $request->query('status', 'active');
        $search = trim((string) $request->query('search', ''));

        $farms = Farm::query()
            ->withCount([
                'fields' => fn ($q) => $q->where('status', '!=', 'archived'),
                'locations',
                'memberships' => fn ($q) => $q->where('status', FarmMembership::ACTIVE),
            ])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%")->orWhere('location', 'ilike', "%{$search}%")))
            ->orderBy('name')
            ->get()
            ->map(fn (Farm $f) => $this->farmRow($f));

        return Inertia::render('Modules/Agri/Farms/Index', [
            'farms' => $farms,
            'filters' => ['status' => $status, 'search' => $search],
            'options' => $this->options(),
            'can' => [
                'manage' => $request->user()->can(RoleCatalog::MANAGE_FARMS),
            ],
            'scope' => [
                'all_farms' => $this->access->isUnrestricted($request->user()),
            ],
        ]);
    }

    public function show(Request $request, Farm $farm): Response
    {
        $this->allow($request, RoleCatalog::VIEW_FARMS);
        $user = $request->user();

        $fields = FarmField::query()->where('farm_id', $farm->id)->orderBy('code')->get()
            ->map(fn (FarmField $f) => [
                'id' => $f->uuid, 'code' => $f->code, 'name' => $f->name, 'land_use_type' => $f->land_use_type,
                'area' => $f->area !== null ? (float) $f->area : null, 'area_unit' => $f->area_unit,
                'hectares' => $f->hectares(), 'soil_type' => $f->soil_type, 'irrigation' => $f->irrigation,
                'latitude' => $f->latitude !== null ? (float) $f->latitude : null,
                'longitude' => $f->longitude !== null ? (float) $f->longitude : null,
                'status' => $f->status, 'notes' => $f->notes,
            ]);

        $locations = FarmLocation::query()->where('farm_id', $farm->id)->orderBy('name')->get()
            ->map(fn (FarmLocation $l) => [
                'id' => $l->uuid, 'name' => $l->name, 'location_type' => $l->location_type,
                'latitude' => $l->latitude !== null ? (float) $l->latitude : null,
                'longitude' => $l->longitude !== null ? (float) $l->longitude : null,
                'status' => $l->status, 'notes' => $l->notes,
            ]);

        $canManageAccess = $user->can(RoleCatalog::MANAGE_ACCESS);
        $people = $canManageAccess ? $this->tenantPeople($request) : [];
        $names = collect($people)->keyBy('id');
        $members = FarmMembership::query()->with('user:id,email')
            ->where('farm_id', $farm->id)->orderBy('status')->get()
            ->map(fn (FarmMembership $m) => [
                'id' => $m->uuid,
                'user_id' => $m->user_id,
                'name' => $names[$m->user_id]['name'] ?? ($m->user?->email ?? 'Former user'),
                'email' => $m->user?->email,
                'role' => $m->role,
                'status' => $m->status,
                'alert_emails' => (bool) $m->alert_emails,
                'since' => $m->created_at?->toDateString(),
            ]);

        $totalHa = $fields->where('status', '!=', 'archived')->sum(fn ($f) => $f['hectares'] ?? 0);

        return Inertia::render('Modules/Agri/Farms/Show', [
            'farm' => $this->farmRow($farm) + [
                'address' => $farm->address,
                'latitude' => $farm->latitude !== null ? (float) $farm->latitude : null,
                'longitude' => $farm->longitude !== null ? (float) $farm->longitude : null,
                'timezone' => $farm->timezone,
                'currency' => $farm->currency,
                'fields_hectares' => round($totalHa, 2),
                'has_history' => $this->registry->hasHistory($farm),
            ],
            'fields' => $fields,
            'locations' => $locations,
            'members' => $canManageAccess ? $members : [],
            'people' => $people,
            'options' => $this->options(),
            'can' => [
                'manage' => $user->can(RoleCatalog::MANAGE_FARMS),
                'manage_fields' => $user->can(RoleCatalog::MANAGE_FIELDS),
                'manage_access' => $canManageAccess,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->allow($request, RoleCatalog::MANAGE_FARMS);

        $farm = $this->registry->createFarm($request->validate($this->rules()), $request->user());

        return redirect()->route('agri.farms.show', $farm->uuid)->with('success', "Farm {$farm->name} registered.");
    }

    public function update(Request $request, Farm $farm): RedirectResponse
    {
        $this->allow($request, RoleCatalog::MANAGE_FARMS);

        $this->registry->updateFarm($farm, $request->validate($this->rules()));

        return back()->with('success', 'Farm updated.');
    }

    public function status(Request $request, Farm $farm): RedirectResponse
    {
        $this->allow($request, RoleCatalog::MANAGE_FARMS);

        $data = $request->validate(['status' => ['required', Rule::in(Farm::STATUSES)]]);
        $this->registry->setStatus($farm, $data['status']);

        return back()->with('success', match ($data['status']) {
            Farm::ARCHIVED => "{$farm->name} archived. Its history stays available in reports.",
            Farm::INACTIVE => "{$farm->name} set inactive.",
            default => "{$farm->name} is active again.",
        });
    }

    public function destroy(Request $request, Farm $farm): RedirectResponse
    {
        $this->allow($request, RoleCatalog::MANAGE_FARMS);

        $name = $farm->name;
        $this->registry->deleteFarm($farm);

        return redirect()->route('agri.farms.index')->with('success', "Farm {$name} deleted.");
    }

    /** @return array<string, mixed> */
    private function farmRow(Farm $f): array
    {
        return [
            'id' => $f->uuid,
            'code' => $f->code,
            'name' => $f->name,
            'farm_type' => $f->farm_type,
            'location' => $f->location,
            'area' => $f->area !== null ? (float) $f->area : null,
            'area_unit' => $f->area_unit,
            'status' => $f->status,
            'fields_count' => (int) ($f->fields_count ?? 0),
            'locations_count' => (int) ($f->locations_count ?? 0),
            'members_count' => (int) ($f->memberships_count ?? 0),
        ];
    }

    private function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9][A-Za-z0-9\-_.\/]*$/'],
            'name' => ['required', 'string', 'max:150'],
            'farm_type' => ['required', Rule::in(Farm::TYPES)],
            'location' => ['nullable', 'string', 'max:200'],
            'address' => ['nullable', 'string', 'max:1000'],
            'area' => ['nullable', 'numeric', 'gt:0', 'max:9999999999'],
            'area_unit' => ['required', Rule::in(Farm::AREA_UNITS)],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'timezone' => ['required', 'timezone:all'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
        ];
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'farm_types' => Farm::TYPES,
            'area_units' => Farm::AREA_UNITS,
            'statuses' => Farm::STATUSES,
            'land_use_types' => FarmField::LAND_USE_TYPES,
            'irrigation' => FarmField::IRRIGATION,
            'location_types' => FarmLocation::TYPES,
            'member_roles' => FarmMembership::ROLES,
        ];
    }

    private function allow(Request $request, string $permission): void
    {
        abort_unless($request->user()?->can($permission), 403, 'You do not have permission to do that.');
    }
}
