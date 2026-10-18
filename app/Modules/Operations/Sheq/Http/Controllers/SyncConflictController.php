<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use App\Modules\Operations\Sheq\Sync\SyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "Needs your attention" queue (SHEQ-OFF-5).
 *
 * The requirement is explicit that conflicts must be *visible*, side by side, because silent loss of
 * a field worker's data is a P0 defect. The engine has queued them since sync was built; until now
 * there was nowhere in the product to look at them, which is only marginally better than losing them.
 */
class SyncConflictController extends Controller
{
    use ResolvesCompany;

    public function __construct(private readonly SyncService $sync) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Modules/Sheq/Sync/Conflicts', [
            'noCompany' => $companyId === null,
            'conflicts' => $companyId === null ? [] : $this->sync->conflicts($companyId),
            'resolved' => $companyId === null ? 0 : DB::table('sheq_sync_conflicts')
                ->where('company_id', $companyId)->where('status', 'RESOLVED')->count(),
            'pendingOperations' => $companyId === null ? 0 : DB::table('sheq_sync_operations')
                ->where('company_id', $companyId)->where('status', 'REJECTED')->count(),
        ]);
    }

    public function resolve(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        $validated = $request->validate([
            'resolution' => ['required', Rule::in(['TOOK_SERVER', 'TOOK_CLIENT'])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->sync->resolveConflict($companyId, $uuid, $validated['resolution'],
            $request->user()?->id, $validated['notes'] ?? null);

        return back()->with('success', 'Conflict resolved and recorded.');
    }
}
