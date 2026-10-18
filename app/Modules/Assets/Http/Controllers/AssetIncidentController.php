<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Actions\LogAssetIncidentAction;
use App\Modules\Assets\Actions\ResolveIncidentAction;
use App\Modules\Assets\Http\Requests\ReportIncidentRequest;
use App\Modules\Assets\Models\AssetIncident;
use App\Modules\Assets\Models\Assets;
use App\Modules\Assets\Services\AssetPolicyGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AssetIncidentController extends Controller
{
    public function __construct(private readonly AssetPolicyGuard $policyGuard) {}

    public function store(
        ReportIncidentRequest $request,
        Assets $asset,
        LogAssetIncidentAction $action,
    ): RedirectResponse {
        $validated = $request->validated();

        $this->policyGuard->forIncidentReported(
            $asset->id,
            $validated['incident_type'] ?? 'other',
            isset($validated['estimated_loss']) ? (float) $validated['estimated_loss'] : null,
            $request->user()->id,
        );

        $action->execute(
            array_merge($validated, ['asset_id' => $asset->id]),
            $request->user()->id,
        );

        return back()->with('success', 'Incident reported.');
    }

    public function resolve(
        Request $request,
        Assets $asset,
        AssetIncident $incident,
        ResolveIncidentAction $action,
    ): RedirectResponse {
        abort_unless($incident->asset_id === $asset->id, 404);

        $data = $request->validate([
            'resolution_notes' => 'nullable|string|max:5000',
            'repair_cost'      => 'nullable|numeric|min:0',
        ]);

        $action->execute($incident, $data, $request->user()->id);

        return back()->with('success', 'Incident resolved.');
    }
}
