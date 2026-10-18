<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Modules\Assets\Actions\CreateExitClearanceAction;
use App\Modules\Assets\Actions\ResolveExitClearanceItemAction;
use App\Modules\Assets\Models\AssetExitClearance;
use App\Modules\Assets\Models\AssetExitClearanceItem;
use App\Modules\Assets\Models\AssetLocation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ExitClearanceController extends Controller
{
    public function index(Request $request): Response
    {
        $clearances = AssetExitClearance::with(['user.person', 'initiator.person'])
            ->withCount(['items', 'pendingItems'])
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Modules/Assets/ExitClearance/Index', [
            'clearances' => [
                'data' => $clearances->map(fn($c) => [
                    'id'                  => $c->id,
                    'uuid'                => $c->uuid,
                    'status'              => ['value' => $c->status->value, 'label' => $c->status->label(), 'color' => $c->status->color()],
                    'user'                => $c->user ? ['id' => $c->user->id, 'name' => $c->user->name] : null,
                    'initiator'           => $c->initiator ? ['id' => $c->initiator->id, 'name' => $c->initiator->name] : null,
                    'target_exit_date'    => $c->target_exit_date?->toDateString(),
                    'items_count'         => $c->items_count,
                    'pending_items_count' => $c->pending_items_count,
                    'created_at'          => $c->created_at?->toISOString(),
                ]),
                'meta'  => ['current_page' => $clearances->currentPage(), 'last_page' => $clearances->lastPage(), 'total' => $clearances->total()],
                'links' => ['prev' => $clearances->previousPageUrl(), 'next' => $clearances->nextPageUrl()],
            ],
            'filters' => $request->only('status'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Modules/Assets/ExitClearance/Create', [
            'users' => User::where('status', 1)
                ->with('person:id,user_id,first_name,surname')
                ->get(['id', 'email']),
        ]);
    }

    public function store(Request $request, CreateExitClearanceAction $action): RedirectResponse
    {
        $data = $request->validate([
            'user_id'          => 'required|integer|exists:users,id',
            'target_exit_date' => 'required|date|after:today',
            'notes'            => 'nullable|string|max:2000',
        ]);

        // Block duplicate open/in_progress clearance
        $existing = AssetExitClearance::where('user_id', $data['user_id'])
            ->whereIn('status', ['pending', 'in_progress'])
            ->first();

        if ($existing) {
            return back()->withErrors(['user_id' => 'This employee already has an active clearance.']);
        }

        $clearance = $action->execute($data, $request->user()->id);

        return redirect()->route('assets.exit-clearances.show', $clearance->uuid)
            ->with('success', 'Exit clearance created.');
    }

    public function show(AssetExitClearance $exitClearance): Response
    {
        $exitClearance->load([
            'user.person', 'initiator.person', 'items.asset', 'items.assignment', 'items.resolvedBy',
        ]);

        $locations = AssetLocation::where('status', 1)->get(['id', 'name']);
        $users     = User::where('status', 1)
            ->with('person:id,user_id,first_name,surname')
            ->get(['id', 'email']);

        return Inertia::render('Modules/Assets/ExitClearance/Show', [
            'clearance' => $exitClearance,
            'locations' => $locations,
            'users'     => $users,
        ]);
    }

    public function resolveItem(
        Request $request,
        AssetExitClearance $exitClearance,
        AssetExitClearanceItem $item,
        ResolveExitClearanceItemAction $action,
    ): RedirectResponse {
        abort_unless($item->clearance_id === $exitClearance->id, 404);
        abort_if($exitClearance->status->value === 'cleared', 422, 'Clearance is already cleared.');

        $data = $request->validate([
            'resolution'             => 'required|string|in:returned,transferred,waived',
            'transfer_to_user_id'    => 'required_if:resolution,transferred|nullable|integer|exists:users,id',
            'transfer_to_location_id' => 'nullable|integer|exists:asset_locations,id',
            'waiver_reason'          => 'required_if:resolution,waived|nullable|string|max:2000',
            'notes'                  => 'nullable|string|max:2000',
        ]);

        $action->execute($item, $data, $request->user()->id);

        return back()->with('success', 'Item resolved.');
    }

    public function destroy(AssetExitClearance $exitClearance): RedirectResponse
    {
        abort_unless($exitClearance->status->value === 'pending', 422, 'Only pending clearances can be deleted.');
        $exitClearance->delete();

        return redirect()->route('assets.exit-clearances.index')->with('success', 'Clearance deleted.');
    }
}
