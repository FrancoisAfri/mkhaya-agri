<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Actions\RegisterAssetAction;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Enums\SarsAllowanceSection;
use App\Modules\Assets\Http\Requests\RegisterAssetRequest;
use App\Modules\Assets\Http\Resources\AssetAssignmentResource;
use App\Modules\Assets\Http\Resources\AssetMaintenanceResource;
use App\Modules\Assets\Http\Resources\AssetResource;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Assets\Models\AssetLocation;
use App\Modules\Assets\Models\Assets;
use App\Modules\Assets\Models\AssetType;
use App\Core\User\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AssetController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Assets::with(['AssetType', 'category', 'location', 'currentAssignment.assignedTo'])
            ->withCount(['assignments', 'maintenanceSchedules', 'incidents'])
            ->when($request->filled('search'), fn($q) => $q->where(function ($s) use ($request) {
                $s->where('name', 'ilike', "%{$request->search}%")
                  ->orWhere('asset_tag', 'ilike', "%{$request->search}%")
                  ->orWhere('asset_number', 'ilike', "%{$request->search}%")
                  ->orWhere('serial_number', 'ilike', "%{$request->search}%");
            }))
            ->when($request->filled('status'), fn($q) => $q->where('asset_status', $request->status))
            ->when($request->filled('category'), fn($q) => $q->where('asset_category_id', $request->category))
            ->orderByDesc('created_at');

        $assets = $query->paginate(25)->withQueryString();

        $stats = [
            'total'       => Assets::count(),
            'active'      => Assets::where('asset_status', AssetStatus::Active->value)->count(),
            'unallocated' => Assets::where('asset_status', AssetStatus::UnAllocated->value)->count(),
            'maintenance' => Assets::where('asset_status', AssetStatus::Maintenance->value)->count(),
        ];

        $statusOptions = collect(AssetStatus::cases())->map(fn($s) => [
            'value' => $s->value,
            'label' => $s->label(),
        ]);

        $categories = AssetCategory::where('status', 1)->get(['id', 'name']);

        return Inertia::render('Modules/Assets/Index', [
            'assets'        => AssetResource::collection($assets)->response()->getData(true),
            'filters'       => $request->only('search', 'status', 'category'),
            'stats'         => $stats,
            'statusOptions' => $statusOptions,
            'categories'    => $categories,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Modules/Assets/Create', [
            'assetTypes'             => AssetType::where('status', 1)->get(['id', 'name']),
            'categories'             => AssetCategory::where('status', 1)->get(['id', 'name']),
            'locations'              => AssetLocation::where('status', 1)->get(['id', 'name', 'code']),
            'sarsAllowanceSections'  => array_map(
                fn($case) => ['value' => $case->value, 'label' => $case->label()],
                SarsAllowanceSection::cases()
            ),
            'makes'   => \App\Modules\Assets\Models\AssetMake::where('status', true)->orderBy('name')->get(['id', 'name']),
            'models'  => \App\Modules\Assets\Models\AssetModel::where('status', true)->orderBy('name')->get(['id', 'make_id', 'name']),
        ]);
    }

    public function store(RegisterAssetRequest $request, RegisterAssetAction $action): RedirectResponse
    {
        $asset = $action->execute($request->validated(), $request->user()->id);

        return redirect()->route('assets.show', $asset->uuid)
            ->with('success', 'Asset registered successfully.');
    }

    public function show(Assets $asset): Response
    {
        $asset->load([
            'AssetType',
            'category',
            'location',
            'make',
            'model',
            'currentAssignment.assignedTo',
            'currentAssignment.location',
            'images',
        ]);

        $assignments = $asset->assignments()
            ->with(['assignedTo', 'assignedBy', 'location'])
            ->orderByDesc('created_at')
            ->get();

        $maintenance = $asset->maintenanceSchedules()
            ->orderByDesc('due_date')
            ->limit(10)
            ->get();

        $incidents = $asset->incidents()
            ->orderByDesc('occurred_at')
            ->limit(10)
            ->get();

        $custodyLog = $asset->custodyLogs()
            ->limit(20)
            ->get()
            ->map(fn($log) => [
                'id'          => $log->id,
                'action'      => $log->action,
                'notes'       => $log->notes,
                'occurred_at' => $log->occurred_at?->toISOString(),
            ]);

        $locations = AssetLocation::where('status', 1)->get(['id', 'name']);

        // Assignable users — restricted to those with an employee (hr_people)
        // record, which is RLS-scoped to the active company. Without this filter
        // the `users` table (no RLS) would leak every tenant's users into the
        // dropdown. Eager-load `person` so the `name` accessor resolves without
        // an N+1 query.
        $users = User::query()
            ->whereHas('person')
            ->where('status', 1)
            ->with(['person:id,user_id,first_name,surname'])
            ->orderBy('email')
            ->get(['id', 'email'])
            ->map(fn(User $u) => [
                'id'    => $u->id,
                'name'  => $u->name,
                'email' => $u->email,
            ])
            ->values();

        return Inertia::render('Modules/Assets/Show', [
            'asset'       => (new AssetResource($asset))->resolve(),
            'assignments' => AssetAssignmentResource::collection($assignments)->resolve(),
            'maintenance' => AssetMaintenanceResource::collection($maintenance)->resolve(),
            'incidents'   => $incidents->map(fn($i) => [
                'id'          => $i->id,
                'title'       => $i->title,
                'severity'    => $i->severity?->value ?? $i->severity,
                'is_resolved' => $i->is_resolved,
                'occurred_at' => $i->occurred_at?->toISOString(),
            ]),
            'custody_log' => $custodyLog,
            'locations'   => $locations,
            'users'       => $users,
        ]);
    }

    public function update(Request $request, Assets $asset): RedirectResponse
    {
        $validated = $request->validate([
            'name'             => 'sometimes|string|max:255',
            'description'      => 'nullable|string',
            'condition'        => 'nullable|string|in:new,good,fair,poor,damaged,irreparable',
            'asset_status'     => 'nullable|string',
            'location_id'      => 'nullable|integer|exists:asset_locations,id',
            'warranty_expiry'  => 'nullable|date',
            'barcode'          => 'nullable|string|max:60',
        ]);

        $asset->update($validated);

        return back()->with('success', 'Asset updated.');
    }
}
