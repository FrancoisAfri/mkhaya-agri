<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Actions\CompleteMaintenanceAction;
use App\Modules\Assets\Actions\ScheduleMaintenanceAction;
use App\Modules\Assets\Http\Requests\ScheduleMaintenanceRequest;
use App\Modules\Assets\Http\Resources\AssetMaintenanceResource;
use App\Modules\Assets\Models\AssetMaintenanceSchedule;
use App\Modules\Assets\Services\AssetPolicyGuard;
use App\Modules\Assets\Models\Assets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AssetMaintenanceController extends Controller
{
    public function __construct(private readonly AssetPolicyGuard $policyGuard) {}
    public function index(Request $request): Response
    {
        $query = AssetMaintenanceSchedule::with(['asset', 'assignedTo'])
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))
            ->when($request->filled('search'), fn($q) => $q->where('title', 'ilike', "%{$request->search}%"))
            ->orderBy('due_date');

        $schedules = $query->paginate(25)->withQueryString();

        $stats = [
            'scheduled'   => AssetMaintenanceSchedule::where('status', 'scheduled')->count(),
            'in_progress' => AssetMaintenanceSchedule::where('status', 'in_progress')->count(),
            'overdue'     => AssetMaintenanceSchedule::where('status', 'scheduled')
                                ->whereDate('due_date', '<', now())->count(),
            'completed'   => AssetMaintenanceSchedule::where('status', 'completed')->count(),
        ];

        return Inertia::render('Modules/Assets/Maintenance/Index', [
            'schedules' => AssetMaintenanceResource::collection($schedules),
            'filters'   => $request->only('search', 'status'),
            'stats'     => $stats,
        ]);
    }

    public function show(Assets $asset, AssetMaintenanceSchedule $schedule): Response
    {
        abort_unless($schedule->asset_id === $asset->id, 404);

        $schedule->load(['asset', 'assignedTo', 'logs.performedBy']);

        return Inertia::render('Modules/Assets/Maintenance/Show', [
            'asset'    => ['uuid' => $asset->uuid, 'name' => $asset->name, 'asset_tag' => $asset->asset_tag],
            'schedule' => (new AssetMaintenanceResource($schedule))->resolve(),
        ]);
    }

    public function store(
        ScheduleMaintenanceRequest $request,
        Assets $asset,
        ScheduleMaintenanceAction $action,
    ): RedirectResponse {
        $validated = $request->validated();

        $this->policyGuard->forMaintenanceRequested(
            $asset->id,
            $validated['maintenance_type'] ?? 'scheduled',
            isset($validated['estimated_cost']) ? (float) $validated['estimated_cost'] : null,
            $request->user()->id,
        );

        $action->execute(
            array_merge($validated, ['asset_id' => $asset->id]),
            $request->user()->id,
        );

        return back()->with('success', 'Maintenance scheduled.');
    }

    public function complete(
        Request $request,
        Assets $asset,
        AssetMaintenanceSchedule $schedule,
        CompleteMaintenanceAction $action,
    ): RedirectResponse {
        abort_unless($schedule->asset_id === $asset->id, 404);

        $data = $request->validate([
            'actual_cost'     => 'nullable|numeric|min:0',
            'action_taken'    => 'required|string|max:2000',
            'condition_after' => 'nullable|string|in:new,good,fair,poor,damaged',
            'notes'           => 'nullable|string|max:2000',
        ]);

        $action->execute($schedule, $data, $request->user()->id);

        return back()->with('success', 'Maintenance marked complete.');
    }
}
