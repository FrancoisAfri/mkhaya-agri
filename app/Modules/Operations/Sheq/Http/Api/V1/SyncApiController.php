<?php

namespace App\Modules\Operations\Sheq\Http\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Sync\SyncRegistry;
use App\Modules\Operations\Sheq\Sync\SyncService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The sync endpoints a field device talks to (§6.1, SHEQ-API-1).
 *
 * Thin on purpose: validation, tenant resolution and shape. Every decision about what a merge does
 * lives in SyncService, which is where it can be tested without an HTTP layer in the way.
 *
 * The tenant is never taken from the request. It comes from the token's user via the api group, and
 * `sheq.tenant` refuses anything that did not resolve — a device cannot ask for another company's
 * data by changing a parameter.
 */
class SyncApiController extends Controller
{
    public function __construct(
        private readonly SyncService $sync,
        private readonly SyncRegistry $registry,
        private readonly CurrentCompany $current,
    ) {}

    /**
     * Delta pull (SHEQ-OFF-3).
     *
     * `GET /api/v1/sheq/sync/pull?cursors[INCIDENT]=418&site_ids[]=7`
     *
     * Cursors are per entity because a device may be caught up on hazards and behind on incidents;
     * one global cursor would force it to re-pull everything whenever any entity moved.
     */
    public function pull(Request $request): JsonResponse
    {
        $companyId = (int) $this->current->id();

        $validated = $request->validate([
            'cursors' => ['sometimes', 'array'],
            'cursors.*' => ['integer', 'min:0'],
            'site_ids' => ['sometimes', 'array'],
            'site_ids.*' => ['integer'],
            // Capped so one device cannot ask for a payload that times out the request for
            // everybody else on a shared worker.
            'limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);

        $cursors = array_intersect_key(
            $validated['cursors'] ?? [],
            array_flip($this->registry->types())
        );

        return response()->json($this->sync->pull(
            $companyId,
            array_map('intval', $cursors),
            $this->sites($companyId, $validated['site_ids'] ?? []),
            (int) ($validated['limit'] ?? 500),
        ));
    }

    /**
     * Push a device's operation log (SHEQ-OFF-2).
     *
     * `POST /api/v1/sheq/sync/push  {"operations":[...],"device_id":"..."}`
     *
     * Returns a result per operation rather than one status for the batch: a device needs to know
     * which of its forty queued operations landed, which conflicted and which were refused, so it
     * can clear exactly those from its local queue and no others. A single 200 would force it to
     * either re-push everything or trust that nothing failed.
     */
    public function push(Request $request): JsonResponse
    {
        $companyId = (int) $this->current->id();

        $validated = $request->validate([
            'device_id' => ['nullable', 'string', 'max:100'],
            'operations' => ['required', 'array', 'min:1', 'max:200'],
            'operations.*.client_id' => ['required', 'uuid'],
            'operations.*.entity_type' => ['required', Rule::in($this->registry->types())],
            'operations.*.operation' => ['required', Rule::in(['CREATE', 'UPDATE', 'TRANSITION', 'DELETE'])],
            'operations.*.entity_uuid' => ['nullable', 'uuid'],
            'operations.*.base' => ['sometimes', 'array'],
            'operations.*.payload' => ['sometimes', 'array'],
        ], [], [
            'operations.*.client_id' => 'operation id',
        ]);

        $result = $this->sync->push(
            $companyId,
            $validated['operations'],
            $request->user()?->id,
            $validated['device_id'] ?? null,
        );

        /**
         * 207, not 200. A batch where some operations conflicted and some were refused is genuinely
         * a mixed outcome, and a flat 200 invites a client to treat the whole push as accepted —
         * which is how a rejected transition quietly disappears from a device's queue.
         */
        $status = ($result['rejected'] > 0 || $result['conflicts'] > 0) ? 207 : 200;

        return response()->json($result, $status);
    }

    /**
     * The "Needs your attention" queue (SHEQ-OFF-5).
     *
     * Both values travel to the device, because the whole point is that the person who typed the
     * losing value gets to see it next to the one that won.
     */
    public function conflicts(Request $request): JsonResponse
    {
        $companyId = (int) $this->current->id();

        $validated = $request->validate([
            'include_resolved' => ['sometimes', 'boolean'],
        ]);

        $conflicts = $this->sync->conflicts($companyId, ! ($validated['include_resolved'] ?? false));

        return response()->json([
            'conflicts' => $conflicts,
            'open' => count(array_filter($conflicts, fn ($c) => $c['status'] === 'OPEN')),
        ]);
    }

    public function resolveConflict(Request $request, string $uuid): JsonResponse
    {
        $companyId = (int) $this->current->id();

        $validated = $request->validate([
            'resolution' => ['required', Rule::in(['TOOK_SERVER', 'TOOK_CLIENT'])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json($this->sync->resolveConflict(
            $companyId, $uuid, $validated['resolution'],
            $request->user()?->id, $validated['notes'] ?? null,
        ));
    }

    /**
     * The device's sync state (SHEQ-OFF-9): where the server is, and what is waiting for a human.
     *
     * A device shows "last synced" from its own clock, which is exactly what it cannot trust after
     * a week offline. Answering with the server's cursor and open-conflict count lets it say
     * something true instead.
     */
    public function status(Request $request): JsonResponse
    {
        $companyId = (int) $this->current->id();

        return response()->json([
            'cursor' => $this->sync->cursor($companyId),
            'open_conflicts' => count($this->sync->conflicts($companyId)),
            'entities' => $this->registry->types(),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /**
     * Narrow the pull to the sites a device cares about (SHEQ-OFF-1).
     *
     * This is a payload filter, not an authorisation boundary — every site here already belongs to
     * the tenant, and tenancy is enforced by RLS rather than by this list. Ids from another company
     * are dropped rather than refused, so a stale device that still holds a decommissioned site's
     * id keeps syncing the rest instead of failing outright.
     *
     * Negative space worth naming: SHEQ has no user-to-site assignment table yet, so "the user's
     * assigned sites" is currently whatever the client asks for. Adding that table is what would
     * turn this into a real scope.
     *
     * @param  list<int>  $requested
     * @return list<int>
     */
    private function sites(int $companyId, array $requested): array
    {
        if ($requested === []) {
            return [];
        }

        return DB::table('sheq_sites')
            ->where('company_id', $companyId)
            ->whereIn('id', $requested)
            ->pluck('id')->map('intval')->all();
    }
}
