<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Actions\RecordAssetMovementAction;
use App\Modules\Assets\Http\Requests\RecordMovementRequest;
use App\Modules\Assets\Models\Assets;
use Illuminate\Http\RedirectResponse;

class AssetMovementController extends Controller
{
    public function store(
        RecordMovementRequest $request,
        Assets $asset,
        RecordAssetMovementAction $action,
    ): RedirectResponse {
        $action->execute(
            array_merge($request->validated(), ['asset_id' => $asset->id]),
            $request->user()->id,
        );

        return back()->with('success', 'Movement recorded.');
    }
}
