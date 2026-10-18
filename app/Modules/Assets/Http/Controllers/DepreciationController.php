<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Actions\GenerateDepreciationScheduleAction;
use App\Modules\Assets\Actions\PostDepreciationEntryAction;
use App\Modules\Assets\Models\AssetDepreciationEntry;
use App\Modules\Assets\Models\AssetDepreciationSchedule;
use App\Modules\Assets\Models\Assets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DepreciationController extends Controller
{
    public function index(): Response
    {
        $schedules = AssetDepreciationSchedule::with(['asset', 'generatedBy', 'latestPostedEntry'])
            ->where('is_active', true)
            ->orderByDesc('generated_at')
            ->paginate(25);

        return Inertia::render('Modules/Assets/Depreciation/Index', [
            'schedules' => [
                'data' => $schedules->map(fn($s) => [
                    'uuid'                 => $s->uuid,
                    'depreciation_method'  => ['value' => $s->depreciation_method->value, 'label' => $s->depreciation_method->label()],
                    'useful_life_years'    => $s->useful_life_years,
                    'purchase_cost'        => $s->purchase_cost,
                    'salvage_value'        => $s->salvage_value,
                    'total_periods'        => $s->total_periods,
                    'generated_at'         => $s->generated_at?->toISOString(),
                    'is_active'            => $s->is_active,
                    'asset'                => $s->asset ? ['uuid' => $s->asset->uuid, 'name' => $s->asset->name, 'asset_tag' => $s->asset->asset_tag] : null,
                    'generatedBy'          => $s->generatedBy ? ['name' => $s->generatedBy->name] : null,
                    'latestPostedEntry'    => $s->latestPostedEntry ? ['period_sequence' => $s->latestPostedEntry->period_sequence, 'closing_book_value' => $s->latestPostedEntry->closing_book_value] : null,
                ]),
                'meta'  => ['current_page' => $schedules->currentPage(), 'last_page' => $schedules->lastPage(), 'total' => $schedules->total()],
                'links' => ['prev' => $schedules->previousPageUrl(), 'next' => $schedules->nextPageUrl()],
            ],
        ]);
    }

    public function show(Assets $asset): Response
    {
        $schedule = AssetDepreciationSchedule::where('asset_id', $asset->id)
            ->where('is_active', true)
            ->with(['generatedBy', 'entries'])
            ->first();

        return Inertia::render('Modules/Assets/Depreciation/Show', [
            'asset'    => ['uuid' => $asset->uuid, 'name' => $asset->name, 'asset_tag' => $asset->asset_tag, 'purchase_cost' => $asset->purchase_cost],
            'schedule' => $schedule,
        ]);
    }

    public function store(Request $request, Assets $asset, GenerateDepreciationScheduleAction $action): RedirectResponse
    {
        $data = $request->validate([
            'depreciation_method' => 'required|string|in:straight_line,reducing_balance,units_of_production,sars_wear_and_tear',
            'useful_life_years'   => 'required|integer|min:1|max:50',
            'purchase_cost'       => 'required|numeric|min:0.01',
            'salvage_value'       => 'nullable|numeric|min:0',
            'sars_wear_rate'      => 'required_if:depreciation_method,sars_wear_and_tear|nullable|numeric|between:0,1',
        ]);

        $schedule = $action->execute($asset, $data, $request->user()->id);

        return redirect()->route('assets.depreciation.show', $asset->uuid)
            ->with('success', 'Depreciation schedule generated with ' . $schedule->total_periods . ' periods.');
    }

    public function destroy(AssetDepreciationSchedule $schedule): RedirectResponse
    {
        $hasPosted = $schedule->entries()->where('is_posted', true)->exists();
        abort_if($hasPosted, 422, 'Cannot delete a schedule with posted entries.');

        $schedule->delete();

        return redirect()->route('assets.depreciation.index')
            ->with('success', 'Schedule deleted.');
    }

    public function postEntry(Request $request, AssetDepreciationEntry $entry, PostDepreciationEntryAction $action): RedirectResponse
    {
        $action->execute($entry, $request->user()->id);

        return back()->with('success', "Period {$entry->period_sequence} posted.");
    }
}
