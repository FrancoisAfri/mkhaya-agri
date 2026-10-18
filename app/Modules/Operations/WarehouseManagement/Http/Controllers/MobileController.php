<?php

namespace App\Modules\Operations\WarehouseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Operations\WarehouseManagement\Concerns\RefusesPolitely;
use App\Modules\Operations\WarehouseManagement\Services\OfflineSync;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/** WMS-150/151/153: the floor screen for scanners and phones, and work done offline. */
class MobileController extends Controller
{
    use RefusesPolitely;

    public function floor(Request $request): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $u = $request->user();

        return Inertia::render('Modules/Warehouse/Mobile/Floor', [
            'user_id' => $u->id,
            'offline_kinds' => OfflineSync::KINDS,
            'can' => ['count' => $u->can(RoleCatalog::COUNT), 'pick' => $u->can(RoleCatalog::PICK), 'putaway' => $u->can(RoleCatalog::MOVE_STOCK) || $u->can(RoleCatalog::RECEIVE),
                'review' => $u->can(RoleCatalog::SUPERVISE)],
        ]);
    }

    /** What this device may keep for working offline: the person's own tasks, never balances. */
    public function bundle(Request $request, OfflineSync $sync): JsonResponse
    {
        $this->authorize('viewAny', FinStockItem::class);

        return response()->json($sync->bundle($request->user()));
    }

    /** One offline action, as queued on the device. Always answers with what happened to it. */
    public function sync(Request $request, OfflineSync $sync): JsonResponse
    {
        $this->authorize('viewAny', FinStockItem::class);
        $data = $request->validate(['client_ref' => ['required', 'string', 'max:64'], 'kind' => ['required', Rule::in(OfflineSync::KINDS)],
            'recorded_at' => ['required', 'date'], 'payload' => ['required', 'array']]);
        $user = $request->user();
        $needs = ['COUNT_ENTRY' => $user->can(RoleCatalog::COUNT), 'PICK_CONFIRM' => $user->can(RoleCatalog::PICK),
            'PUTAWAY_COMPLETE' => $user->can(RoleCatalog::MOVE_STOCK) || $user->can(RoleCatalog::RECEIVE)];
        abort_unless($needs[$data['kind']], 403);

        try {
            return response()->json($sync->apply($data, $user));
        } catch (RuntimeException $e) {
            return response()->json(['status' => 'REJECTED', 'message' => $e->getMessage()], 422);
        }
    }

    public function conflicts(Request $request): Response
    {
        abort_unless($request->user()->can(RoleCatalog::SUPERVISE), 403);
        $cid = app(CurrentCompany::class)->id() ?? $request->user()->company_id;

        return Inertia::render('Modules/Warehouse/Mobile/Conflicts', [
            'actions' => DB::table('wms_offline_actions as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')->where('a.company_id', $cid)
                ->whereIn('a.status', ['CONFLICT', 'REJECTED'])->orderByDesc('a.id')->limit(100)
                ->get(['a.id', 'a.kind', 'a.payload', 'a.recorded_at', 'a.received_at', 'a.status', 'a.message', 'u.email as by'])
                ->map(fn ($a) => (array) $a + ['payload' => json_decode($a->payload, true)]),
        ]);
    }

    public function resolve(Request $request, string $id, OfflineSync $sync): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::SUPERVISE), 403);
        $data = $request->validate(['decision' => ['required', Rule::in(['apply', 'discard'])]]);

        return $this->attempt(fn () => $sync->resolve((int) $id, $data['decision'], $request->user()), $data['decision'] === 'apply' ? 'Applied.' : 'Discarded.');
    }
}
