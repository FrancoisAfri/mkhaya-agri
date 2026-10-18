<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Modules\Assets\Actions\CreateMisuseFlagAction;
use App\Modules\Assets\Actions\RunMisuseDetectionAction;
use App\Modules\Assets\Actions\UpdateMisuseFlagStatusAction;
use App\Modules\Assets\Models\AssetMisuseDetectionRule;
use App\Modules\Assets\Models\AssetMisuseFlag;
use App\Modules\Assets\Models\Assets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MisuseFlagController extends Controller
{
    public function index(Request $request): Response
    {
        $flags = AssetMisuseFlag::with(['asset', 'flaggedBy', 'investigator'])
            ->when($request->filled('status'),    fn($q) => $q->where('status', $request->status))
            ->when($request->filled('flag_type'), fn($q) => $q->where('flag_type', $request->flag_type))
            ->when($request->filled('severity'),  fn($q) => $q->where('severity', $request->severity))
            ->orderByDesc('detected_at')
            ->paginate(25)
            ->withQueryString();

        $stats = [
            'open'          => AssetMisuseFlag::where('status', 'open')->count(),
            'investigating' => AssetMisuseFlag::where('status', 'investigating')->count(),
            'confirmed'     => AssetMisuseFlag::where('status', 'confirmed')->count(),
        ];

        return Inertia::render('Modules/Assets/Misuse/Index', [
            'flags' => [
                'data' => $flags->map(fn($f) => [
                    'id'          => $f->id,
                    'uuid'        => $f->uuid,
                    'flag_type'   => ['value' => $f->flag_type->value, 'label' => $f->flag_type->label()],
                    'severity'    => ['value' => $f->severity->value, 'label' => $f->severity->label(), 'color' => $f->severity->color()],
                    'status'      => ['value' => $f->status->value, 'label' => $f->status->label(), 'color' => $f->status->color()],
                    'description' => $f->description,
                    'asset'       => $f->asset ? ['uuid' => $f->asset->uuid, 'name' => $f->asset->name, 'asset_tag' => $f->asset->asset_tag] : null,
                    'flaggedBy'   => $f->flaggedBy ? ['name' => $f->flaggedBy->name] : null,
                    'investigator' => $f->investigator ? ['name' => $f->investigator->name] : null,
                    'detected_at' => $f->detected_at?->toISOString(),
                ]),
                'meta'  => ['current_page' => $flags->currentPage(), 'last_page' => $flags->lastPage(), 'total' => $flags->total()],
                'links' => ['prev' => $flags->previousPageUrl(), 'next' => $flags->nextPageUrl()],
            ],
            'stats'   => $stats,
            'filters' => $request->only('status', 'flag_type', 'severity'),
        ]);
    }

    public function store(Request $request, CreateMisuseFlagAction $action): RedirectResponse
    {
        $data = $request->validate([
            'asset_id'    => 'required|integer|exists:assets,id',
            'flag_type'   => 'required|string|in:after_hours_usage,unauthorized_location,rapid_movement,repeated_incidents,other',
            'severity'    => 'required|string|in:low,medium,high,critical',
            'description' => 'required|string|max:2000',
            'detected_at' => 'nullable|date',
        ]);

        $action->execute($data, $request->user()->id);

        return back()->with('success', 'Misuse flag created.');
    }

    public function show(AssetMisuseFlag $misuseFlag): Response
    {
        $misuseFlag->load(['asset', 'flaggedBy', 'investigator', 'resolvedBy']);

        return Inertia::render('Modules/Assets/Misuse/Show', [
            'flag'  => $misuseFlag,
            'users' => User::where('status', 1)->with('person:id,user_id,first_name,surname')->get(['id'])->sortBy('name')->values()->map(fn($u) => ['id' => $u->id, 'name' => $u->name]),
        ]);
    }

    public function update(Request $request, AssetMisuseFlag $misuseFlag, UpdateMisuseFlagStatusAction $action): RedirectResponse
    {
        $data = $request->validate([
            'status'                 => 'required|string|in:investigating,dismissed,confirmed',
            'assigned_investigator'  => 'required_if:status,investigating|nullable|integer|exists:users,id',
            'resolution_notes'       => 'required_if:status,dismissed,confirmed|nullable|string|max:5000',
        ]);

        $action->execute($misuseFlag, $data, $request->user()->id);

        return back()->with('success', 'Flag updated.');
    }

    public function destroy(AssetMisuseFlag $misuseFlag): RedirectResponse
    {
        abort_unless($misuseFlag->status->value === 'open', 422, 'Only open flags can be deleted.');
        $misuseFlag->delete();

        return back()->with('success', 'Flag deleted.');
    }

    public function runDetection(Request $request, RunMisuseDetectionAction $action): RedirectResponse
    {
        $assetId = $request->integer('asset_id', 0) ?: null;
        $count   = $action->execute($assetId);

        return back()->with('success', "Detection complete. {$count} new flag(s) created.");
    }

    public function rules(): Response
    {
        $rules = AssetMisuseDetectionRule::with('category')->orderBy('name')->get();

        return Inertia::render('Modules/Assets/Misuse/Rules', [
            'rules' => $rules,
        ]);
    }

    public function storeRule(Request $request): RedirectResponse
    {
     $data = $request->validate([
    'name'                 => 'required|string|max:255',
    'flag_type'            => 'required|string|in:after_hours_usage,unauthorized_location,rapid_movement,repeated_incidents,other',
    'parameters'           => 'nullable|array',
    'applies_to_category'  => 'nullable|integer|exists:asset_categories,id',
]);

        AssetMisuseDetectionRule::create(array_merge($data, ['created_by' => $request->user()->id, 'is_active' => true]));

        return back()->with('success', 'Detection rule created.');
    }

    public function updateRule(Request $request, AssetMisuseDetectionRule $rule): RedirectResponse
    {
        $data = $request->validate([
            'name'       => 'sometimes|string|max:255',
            'parameters' => 'sometimes|array',
            'is_active'  => 'sometimes|boolean',
        ]);

        $rule->update($data);

        return back()->with('success', 'Rule updated.');
    }

    public function destroyRule(AssetMisuseDetectionRule $rule): RedirectResponse
    {
        $rule->delete();
        return back()->with('success', 'Rule deleted.');
    }
}
