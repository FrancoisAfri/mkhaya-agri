<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Actions\AssignAssetAction;
use App\Modules\Assets\Actions\ReturnAssetAction;
use App\Modules\Assets\Http\Requests\AssignAssetRequest;
use App\Modules\Assets\Models\AssetAssignment;
use App\Modules\Assets\Models\Assets;
use App\Modules\Assets\Services\AssetPolicyGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AssetAssignmentController extends Controller
{
    public function __construct(private readonly AssetPolicyGuard $policyGuard) {}

    public function store(
        AssignAssetRequest $request,
        Assets $asset,
        AssignAssetAction $action,
    ): RedirectResponse {
        $validated = $request->validated();

        // Build a temporary assignment model for the guard (not yet persisted)
        $tempAssignment = new AssetAssignment(array_merge($validated, ['asset_id' => $asset->id]));
        $tempAssignment->setRelation('asset', $asset);

        $this->policyGuard->forAssetAssigned($tempAssignment, $request->user()->id);

        $action->execute(
            array_merge($validated, ['asset_id' => $asset->id]),
            $request->user()->id,
        );

        return back()->with('success', 'Asset assigned successfully.');
    }

    /**
     * Take an asset back from whoever is holding it.
     *
     * The assignment is checked against the asset in the URL rather than trusted from it: both are
     * route parameters, and without the check an assignment id from one asset could be closed
     * under another asset's address.
     */
    public function returnAsset(
        Request $request,
        Assets $asset,
        AssetAssignment $assignment,
        ReturnAssetAction $action,
    ): RedirectResponse {
        abort_unless($assignment->asset_id === $asset->id, 404);

        $data = $request->validate(['notes' => 'nullable|string|max:2000']);

        $action->execute($assignment, $request->user()->id, $data['notes'] ?? null);

        return back()->with('success', 'Asset returned.');
    }
}
