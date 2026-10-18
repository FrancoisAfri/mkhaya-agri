<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Actions\LogPerformanceAction;
use App\Modules\Assets\Http\Requests\LogPerformanceRequest;
use App\Modules\Assets\Models\Assets;
use Illuminate\Http\RedirectResponse;

class AssetPerformanceController extends Controller
{
    public function store(
        LogPerformanceRequest $request,
        Assets $asset,
        LogPerformanceAction $action,
    ): RedirectResponse {
        $action->execute(
            array_merge($request->validated(), ['asset_id' => $asset->id]),
            $request->user()->id,
        );

        return back()->with('success', 'Performance data logged.');
    }
}
