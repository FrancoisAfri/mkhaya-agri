<?php

namespace App\Modules\Operations\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Operations\Manufacturing\Models\DowntimeEvent;
use App\Modules\Operations\Manufacturing\Models\DowntimeReason;
use App\Modules\Operations\Manufacturing\Models\FloorEscalation;
use App\Modules\Operations\Manufacturing\Models\ItemDocument;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder;
use App\Modules\Operations\Manufacturing\Models\ProductionOrderComponent;
use App\Modules\Operations\Manufacturing\Models\ProductionOrderOperation as Op;
use App\Modules\Operations\Manufacturing\Models\WorkCentre;
use App\Modules\Operations\Manufacturing\Services\ProductionExecution;
use App\Modules\Operations\Manufacturing\Services\ShopFloor;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The shop floor (MFG-007): a workstation terminal, downtime, and escalations.
 *
 * The terminal talks JSON so a tablet that drops off the network can queue its taps and replay them
 * later. Every write carries a client key (the Idempotency-Key header the offline queue sends, or an
 * idempotency_key field) and is applied once: a replay gets the answer the first attempt got.
 */
class ShopFloorController extends Controller
{
    use \App\Modules\HR\Support\PicksTenantPeople;
    use \App\Modules\Operations\Manufacturing\Concerns\ScopesValidationToTenant;

    public function __construct(
        private readonly ShopFloor $floor,
        private readonly ProductionExecution $execution,
    ) {}

    // ── Terminal ─────────────────────────────────────────────────────────────────────────────

    public function terminal(Request $request): Response
    {
        $this->authorize('useFloor', ProductionOrder::class);
        $user = $request->user();

        return Inertia::render('Modules/Manufacturing/Floor/Terminal', [
            'work_centres' => WorkCentre::query()->where('is_active', true)->with(['factory:id,code', 'machines:assets.id,name'])
                ->orderBy('code')->get()->map(fn (WorkCentre $c) => [
                    'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'factory' => $c->factory?->code,
                    'machines' => $c->machines->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values(),
                ])->values(),
            'reasons' => $this->reasonOptions(),
            'people' => $user->can(RoleCatalog::SUPERVISE_FLOOR) ? $this->tenantPeopleOptions($request) : [],
            'escalation_kinds' => FloorEscalation::KINDS,
            'me' => $user->id,
            'can' => [
                'execute' => $user->can(RoleCatalog::EXECUTE),
                'issue' => $user->can(RoleCatalog::ISSUE_MATERIAL),
                'supervise' => $user->can(RoleCatalog::SUPERVISE_FLOOR),
                'override' => $user->can(RoleCatalog::OVERRIDE_CONTROLS),
            ],
        ]);
    }

    /** The jobs for a work centre, or the caller's own. */
    public function queue(Request $request): JsonResponse
    {
        $this->authorize('useFloor', ProductionOrder::class);

        $data = $request->validate([
            'work_centre' => ['nullable', 'integer', $this->tenantExists('mfg_work_centres')],
            'mine' => ['nullable', 'boolean'],
        ]);

        $centreId = $data['work_centre'] ?? null;
        $jobs = $this->floor->queue($centreId, ! empty($data['mine']) ? $request->user()->id : null);

        return response()->json([
            'jobs' => $jobs->map(fn (Op $op) => $this->job($op))->values(),
            'downtime' => $centreId ? DowntimeEvent::query()->where('work_centre_id', $centreId)->whereNull('ended_at')
                ->with('reason:id,code,name')->get()->map(fn (DowntimeEvent $e) => $this->stoppage($e))->values() : [],
        ]);
    }

    /** One job as a queue row. */
    private function job(Op $op): array
    {
            $order = $op->order;

            return [
                'id' => $op->uuid, 'sequence' => (int) $op->sequence, 'name' => $op->name, 'status' => $op->status,
                'order' => ['id' => $order->uuid, 'number' => $order->number, 'priority' => (int) $order->priority,
                    'due_date' => $order->due_date?->toDateString(), 'is_overdue' => $order->isOverdue(),
                    'product' => trim(($order->item?->stockItem?->code ?? '').' — '.($order->item?->stockItem?->name ?? '')),
                    'planned_qty' => (float) $order->planned_qty, 'qty_completed' => (float) $order->qty_completed],
                'work_centre' => $op->workCentre ? "{$op->workCentre->code} — {$op->workCentre->name}" : null,
                'assignee' => $op->assignee ? $this->personName($op->assignee) : null, 'assigned_to' => $op->assigned_to,
                'is_gate' => $op->isGate(), 'gate_status' => $op->gate_status,
                'planned_minutes' => (float) $op->planned_minutes, 'started_at' => $op->started_at?->toIso8601String(),
                'blocked_by' => $op->status === Op::COMPLETED ? [] : $this->execution->blockedBy($order, $op),
                'job_code' => 'JOB:'.$op->uuid,
            ];
    }

    /** Everything a workstation shows for one job: instructions, drawings, materials. */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $op = $this->findOp($uuid);
        $this->authorize('useFloor', ProductionOrder::class);
        $op->load(['order.item.stockItem', 'workCentre', 'assignee.person', 'routingOperation', 'timeLogs']);
        $order = $op->order;

        return response()->json($this->job($op) + [
            'instructions' => $op->routingOperation?->instructions,
            'documents' => ItemDocument::query()->where('item_id', $order->item_id)->with('document:id,uuid,title')->orderBy('kind')->get()
                ->map(fn (ItemDocument $d) => ['kind' => $d->kind, 'title' => $d->title, 'reference' => $d->external_reference,
                    'url' => $d->document ? route('document-management.documents.show', $d->document->uuid, false) : null])->values(),
            'components' => $order->components()->with('stockItem:id,code,name')->get()->filter->isConsumed()
                ->map(fn (ProductionOrderComponent $c) => ['id' => $c->uuid, 'code' => $c->stockItem?->code, 'name' => $c->stockItem?->name,
                    'uom' => $c->uom, 'required' => (float) $c->required_qty, 'issued' => $c->netIssued(), 'outstanding' => $c->outstanding()])->values(),
            'logged_minutes' => round((float) $op->timeLogs->sum('minutes') + (float) $op->timeLogs->whereNull('ended_at')
                ->sum(fn ($l) => $l->started_at->diffInSeconds(now()) / 60), 1),
        ]);
    }

    public function scan(Request $request): JsonResponse
    {
        $this->authorize('useFloor', ProductionOrder::class);
        $data = $request->validate(['code' => ['required', 'string', 'max:120']]);
        $found = $this->floor->identify($data['code']);

        return $found ? response()->json($found) : response()->json(['message' => 'Nothing matches that code.'], 404);
    }

    // ── Actions on a job ─────────────────────────────────────────────────────────────────────

    public function start(Request $request, string $uuid): JsonResponse
    {
        $op = $this->findOp($uuid);
        $this->authorize('execute', $op->order);

        return $this->once($request, 'start', fn () => $this->job($this->floor->start($op, $request->user())->load('order', 'workCentre', 'assignee')));
    }

    public function pause(Request $request, string $uuid): JsonResponse
    {
        $op = $this->findOp($uuid);
        $this->authorize('execute', $op->order);
        $data = $request->validate([
            'reason_id' => ['nullable', 'integer', $this->tenantExists('mfg_downtime_reasons')],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->once($request, 'pause', fn () => $this->job($this->floor->pause($op, $request->user(),
            isset($data['reason_id']) ? DowntimeReason::query()->findOrFail($data['reason_id']) : null, $data['notes'] ?? null)->load('order', 'workCentre', 'assignee')));
    }

    public function complete(Request $request, string $uuid): JsonResponse
    {
        $op = $this->findOp($uuid);
        $this->authorize('execute', $op->order);
        $data = $request->validate([
            'completed' => ['required', 'numeric', 'min:0'],
            'rejected' => ['nullable', 'numeric', 'min:0'],
            'scrapped' => ['nullable', 'numeric', 'min:0'],
            'reworked' => ['nullable', 'numeric', 'min:0'],
            'machine_minutes' => ['nullable', 'numeric', 'min:0'],
            'labour_minutes' => ['nullable', 'numeric', 'min:0'],
            'override_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->once($request, 'complete', fn () => $this->job($this->floor->complete($op, $data, $request->user(),
            $data['override_reason'] ?? null)->load('order', 'workCentre', 'assignee')));
    }

    public function note(Request $request, string $uuid): JsonResponse
    {
        $op = $this->findOp($uuid);
        $this->authorize('execute', $op->order);
        $data = $request->validate(['text' => ['required', 'string', 'max:2000']]);

        return $this->once($request, 'note', function () use ($op, $data, $request) {
            $this->floor->note($op, $data['text'], $request->user());

            return ['ok' => true];
        });
    }

    public function assign(Request $request, string $uuid): JsonResponse
    {
        $op = $this->findOp($uuid);
        $this->authorize('supervise', $op->order);
        $data = $request->validate(['user_id' => ['nullable', 'integer', Rule::in(array_column($this->tenantPeople($request), 'id'))]]);

        return $this->once($request, 'assign', fn () => $this->job($this->floor->assign($op,
            isset($data['user_id']) ? \App\Core\User\Models\User::query()->find($data['user_id']) : null, $request->user())->load('order', 'workCentre', 'assignee')));
    }

    public function issue(Request $request, string $uuid, string $componentUuid): JsonResponse
    {
        $op = $this->findOp($uuid);
        $this->authorize('issueMaterial', $op->order);
        $component = $op->order->components()->where('uuid', $componentUuid)->firstOrFail();
        $data = $request->validate([
            'qty' => ['required', 'numeric', 'gt:0'],
            'lot_number' => ['nullable', 'string', 'max:64'],
            'override_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->once($request, 'issue', function () use ($op, $component, $data, $request) {
            $lotId = null;
            if (filled($data['lot_number'] ?? null)) {
                $lotId = \App\Modules\Finance\Accounting\Models\FinStockLot::withoutGlobalScopes()
                    ->where('item_id', $component->stock_item_id)->where('lot_number', trim($data['lot_number']))->value('id');
                if (! $lotId) {
                    throw new DomainException('That lot is not a lot of this material.');
                }
            }

            $movements = $this->execution->issue($op->order, $component, (float) $data['qty'], $request->user(), array_filter([
                'lot_id' => $lotId, 'override_reason' => $data['override_reason'] ?? null,
                'idempotency_key' => $this->key($request) ? 'floor:'.$this->key($request) : null,
            ]));

            return ['issued' => (float) $data['qty'], 'movements' => count($movements)];
        });
    }

    public function escalate(Request $request): JsonResponse
    {
        $this->authorize('useFloor', ProductionOrder::class);
        $data = $request->validate([
            'kind' => ['required', Rule::in(FloorEscalation::KINDS)],
            'message' => ['required', 'string', 'max:2000'],
            'operation' => ['nullable', 'string'],
            'work_centre' => ['nullable', 'integer', $this->tenantExists('mfg_work_centres')],
        ]);

        return $this->once($request, 'escalate', function () use ($data, $request) {
            $op = isset($data['operation']) ? $this->findOp($data['operation']) : null;
            $centre = isset($data['work_centre']) ? WorkCentre::query()->findOrFail($data['work_centre']) : null;
            $e = $this->floor->escalate($data['kind'], $data['message'], $request->user(), $op, $centre);

            return ['id' => $e->uuid];
        });
    }

    // ── Downtime ─────────────────────────────────────────────────────────────────────────────

    public function startDowntime(Request $request): JsonResponse
    {
        $this->authorize('create', DowntimeEvent::class);
        $data = $request->validate([
            'work_centre' => ['required', 'integer', $this->tenantExists('mfg_work_centres')],
            'reason_id' => ['required', 'integer', $this->tenantExists('mfg_downtime_reasons')],
            'asset_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->once($request, 'downtime', fn () => $this->stoppage($this->floor->startDowntime(
            WorkCentre::query()->findOrFail($data['work_centre']), DowntimeReason::query()->findOrFail($data['reason_id']),
            $request->user(), $data['notes'] ?? null, null, $data['asset_id'] ?? null)->load('reason')));
    }

    public function endDowntime(Request $request, string $uuid): JsonResponse
    {
        $event = DowntimeEvent::query()->where('uuid', $uuid)->firstOrFail();
        $this->authorize('create', DowntimeEvent::class);
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:1000']]);

        return $this->once($request, 'downtime-end', fn () => $this->stoppage($this->floor->endDowntime($event, $request->user(), $data['notes'] ?? null)->load('reason')));
    }

    public function downtime(Request $request): Response
    {
        $this->authorize('viewAny', DowntimeEvent::class);
        $filters = $request->validate([
            'work_centre' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
            'open' => ['nullable', 'boolean'],
        ]);
        $from = $filters['from'] ?? now()->subDays(30)->toDateString();
        $to = $filters['to'] ?? now()->toDateString();

        $base = DowntimeEvent::query()
            ->when($filters['work_centre'] ?? null, fn ($q, $c) => $q->where('work_centre_id', $c))
            ->where('started_at', '>=', $from)->where('started_at', '<', \Illuminate\Support\Carbon::parse($to)->addDay());

        return Inertia::render('Modules/Manufacturing/Floor/Downtime', [
            'events' => (clone $base)->with(['reason', 'workCentre:id,code,name', 'machine:id,name', 'operation.order:id,uuid,number'])
                ->when(! empty($filters['open']), fn ($q) => $q->whereNull('ended_at'))
                ->orderByDesc('started_at')->paginate(50)->withQueryString()
                ->through(fn (DowntimeEvent $e) => $this->stoppage($e) + [
                    'work_centre' => $e->workCentre ? "{$e->workCentre->code} — {$e->workCentre->name}" : null,
                    'machine' => $e->machine?->name,
                    'order' => $e->operation?->order ? ['id' => $e->operation->order->uuid, 'number' => $e->operation->order->number] : null,
                ]),
            // Minutes by reason over the period -- what the stoppages actually cost.
            'by_reason' => (clone $base)->join('mfg_downtime_reasons as r', 'r.id', '=', 'mfg_downtime_events.reason_id')
                ->selectRaw('r.code, r.name, r.is_planned, COUNT(*) AS stops, COALESCE(SUM(mfg_downtime_events.minutes), 0) AS minutes')
                ->groupBy('r.code', 'r.name', 'r.is_planned')->orderByDesc('minutes')->get()
                ->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'is_planned' => (bool) $r->is_planned,
                    'stops' => (int) $r->stops, 'minutes' => round((float) $r->minutes, 1)])->values(),
            'filters' => $filters + ['from' => $from, 'to' => $to],
            'work_centres' => WorkCentre::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => "{$c->code} — {$c->name}"])->values(),
            'reasons' => DowntimeReason::query()->orderBy('code')->get()->map(fn (DowntimeReason $r) => [
                'id' => $r->uuid, 'reason_id' => $r->id, 'code' => $r->code, 'name' => $r->name, 'category' => $r->category,
                'is_planned' => (bool) $r->is_planned, 'is_active' => (bool) $r->is_active])->values(),
            'categories' => DowntimeReason::CATEGORIES,
            'can' => [
                'record' => $request->user()->can('create', DowntimeEvent::class),
                'manage_reasons' => $request->user()->can('manageReasons', DowntimeEvent::class),
            ],
        ]);
    }

    public function storeReason(Request $request): RedirectResponse
    {
        $this->authorize('manageReasons', DowntimeEvent::class);
        DowntimeReason::create($this->validateReason($request) + ['company_id' => $this->tenantId($request)]);

        return back()->with('success', 'Reason code added.');
    }

    public function updateReason(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('manageReasons', DowntimeEvent::class);
        $reason = DowntimeReason::query()->where('uuid', $uuid)->firstOrFail();
        $reason->update($this->validateReason($request, $reason));

        return back()->with('success', 'Reason code saved.');
    }

    // ── Escalations ──────────────────────────────────────────────────────────────────────────

    public function escalations(Request $request): Response
    {
        $this->authorize('viewAny', FloorEscalation::class);
        $filters = $request->validate(['status' => ['nullable', Rule::in(['OPEN_ALL', FloorEscalation::OPEN, FloorEscalation::ACKNOWLEDGED, FloorEscalation::RESOLVED])]]);
        $status = $filters['status'] ?? 'OPEN_ALL';

        return Inertia::render('Modules/Manufacturing/Floor/Escalations', [
            'escalations' => FloorEscalation::query()
                ->with(['order:id,uuid,number', 'operation:id,sequence,name', 'workCentre:id,code,name', 'raiser.person', 'assignee.person'])
                ->when($status === 'OPEN_ALL', fn ($q) => $q->where('status', '!=', FloorEscalation::RESOLVED), fn ($q) => $q->where('status', $status))
                ->orderByDesc('level')->orderBy('raised_at')
                ->paginate(50)->withQueryString()
                ->through(fn (FloorEscalation $e) => [
                    'id' => $e->uuid, 'kind' => $e->kind, 'message' => $e->message, 'status' => $e->status, 'level' => (int) $e->level,
                    'order' => $e->order ? ['id' => $e->order->uuid, 'number' => $e->order->number] : null,
                    'operation' => $e->operation ? "{$e->operation->sequence} {$e->operation->name}" : null,
                    'work_centre' => $e->workCentre?->code,
                    'raised_by' => $e->raiser ? $this->personName($e->raiser) : null, 'raised_at' => $e->raised_at?->toIso8601String(),
                    'assigned_to' => $e->assignee ? $this->personName($e->assignee) : null,
                    'resolution' => $e->resolution, 'resolved_at' => $e->resolved_at?->toIso8601String(),
                ]),
            'filters' => ['status' => $status],
            'can' => ['respond' => $request->user()->can('respond', FloorEscalation::class)],
        ]);
    }

    public function acknowledge(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('respond', FloorEscalation::class);

        return $this->attempt(fn () => $this->floor->acknowledge(FloorEscalation::query()->where('uuid', $uuid)->firstOrFail(), $request->user()), 'Acknowledged.');
    }

    public function resolveEscalation(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('respond', FloorEscalation::class);
        $data = $request->validate(['resolution' => ['required', 'string', 'max:2000']]);

        return $this->attempt(fn () => $this->floor->resolveEscalation(FloorEscalation::query()->where('uuid', $uuid)->firstOrFail(),
            $data['resolution'], $request->user()), 'Resolved.');
    }

    // ── Job card ─────────────────────────────────────────────────────────────────────────────

    /** A printable job card: one QR code per operation, which the terminal scans to open the job. */
    public function jobCard(Request $request, string $uuid): Response
    {
        $order = ProductionOrder::query()->with(['item.stockItem:id,code,name', 'factory:id,code,name', 'operations.workCentre:id,code,name',
            'components.stockItem:id,code,name'])->where('uuid', $uuid)->firstOrFail();
        $this->authorize('view', $order);

        return Inertia::render('Modules/Manufacturing/Floor/JobCard', [
            'order' => [
                'id' => $order->uuid, 'number' => $order->number, 'status' => $order->status,
                'product' => trim(($order->item?->stockItem?->code ?? '').' — '.($order->item?->stockItem?->name ?? '')),
                'planned_qty' => (float) $order->planned_qty, 'due_date' => $order->due_date?->toDateString(),
                'factory' => $order->factory ? "{$order->factory->code} — {$order->factory->name}" : null,
            ],
            'operations' => $order->operations->map(fn (Op $op) => [
                'sequence' => (int) $op->sequence, 'name' => $op->name, 'job_code' => 'JOB:'.$op->uuid,
                'work_centre' => $op->workCentre?->code, 'planned_minutes' => (float) $op->planned_minutes,
                'is_gate' => $op->isGate(),
            ])->values(),
            'components' => $order->components->filter->isConsumed()->map(fn (ProductionOrderComponent $c) => [
                'code' => $c->stockItem?->code, 'name' => $c->stockItem?->name, 'required' => (float) $c->required_qty, 'uom' => $c->uom,
            ])->values(),
        ]);
    }

    // ── Internals ────────────────────────────────────────────────────────────────────────────

    /**
     * Apply a floor write once per client key. A replay returns what the first attempt returned; a
     * duplicate arriving at the same moment loses the race on the unique key and is answered the same way.
     */
    private function once(Request $request, string $action, callable $work): JsonResponse
    {
        $key = $this->key($request);
        $companyId = $this->tenantId($request);

        $replay = fn () => $key ? DB::table('mfg_floor_submissions')->where('company_id', $companyId)->where('idempotency_key', $key)->value('response') : null;

        if ($previous = $replay()) {
            return response()->json(json_decode($previous, true) + ['replayed' => true]);
        }

        try {
            $payload = DB::transaction(function () use ($work, $key, $companyId, $action, $request) {
                $payload = $work();
                if ($key) {
                    DB::table('mfg_floor_submissions')->insert([
                        'company_id' => $companyId, 'idempotency_key' => $key, 'user_id' => $request->user()->id,
                        'action' => $action, 'response' => json_encode($payload), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }

                return $payload;
            });
        } catch (UniqueConstraintViolationException) {
            return response()->json(json_decode((string) $replay(), true) + ['replayed' => true]);
        } catch (DomainException|PostingException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($payload);
    }

    private function key(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key') ?: $request->input('idempotency_key');

        return filled($key) ? substr((string) $key, 0, 100) : null;
    }

    private function attempt(callable $work, string $success): RedirectResponse
    {
        try {
            $work();
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }

    private function findOp(string $uuid): Op
    {
        return Op::query()->with(['order.item.stockItem:id,code,name', 'workCentre:id,code,name', 'assignee.person'])
            ->where('uuid', $uuid)->firstOrFail();
    }

    private function stoppage(DowntimeEvent $e): array
    {
        return [
            'id' => $e->uuid, 'reason' => $e->reason ? "{$e->reason->code} — {$e->reason->name}" : null,
            'is_planned' => (bool) $e->reason?->is_planned,
            'started_at' => $e->started_at?->toIso8601String(), 'ended_at' => $e->ended_at?->toIso8601String(),
            'minutes' => round($e->duration(), 1), 'notes' => $e->notes, 'is_open' => $e->isOpen(),
        ];
    }

    private function reasonOptions(): array
    {
        return DowntimeReason::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'is_planned'])
            ->map(fn ($r) => ['id' => $r->id, 'name' => "{$r->code} — {$r->name}", 'is_planned' => (bool) $r->is_planned])->values()->all();
    }

    private function validateReason(Request $request, ?DowntimeReason $reason = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', Rule::unique('mfg_downtime_reasons', 'code')->where('company_id', $this->tenantId($request))->ignore($reason?->id)],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(DowntimeReason::CATEGORIES)],
            'is_planned' => ['boolean'],
            'is_active' => ['boolean'],
        ]);
        $data['code'] = strtoupper(trim($data['code']));

        return $data;
    }

    private function tenantId(Request $request): ?int
    {
        return app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id() ?? $request->user()->company_id;
    }

    private function personName(\App\Core\User\Models\User $user): string
    {
        $p = $user->person;

        return $p ? trim("{$p->first_name} {$p->surname}") : (string) $user->email;
    }
}
