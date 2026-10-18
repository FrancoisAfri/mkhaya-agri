<?php

namespace App\Modules\Operations\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockLot;
use App\Modules\Finance\Accounting\Scopes\EntityScope;
use App\Modules\Operations\Manufacturing\Models\Recall;
use App\Modules\Operations\Manufacturing\Models\RecallAction;
use App\Modules\Operations\Manufacturing\Models\RecallLot;
use App\Modules\Operations\Manufacturing\Services\Recalls;
use App\Modules\Operations\Manufacturing\Services\Traceability;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Traceability and recall (MFG-010): look a lot up, trace it both ways, and run a recall from it.
 */
class TraceabilityController extends Controller
{
    public function __construct(
        private readonly Traceability $trace,
        private readonly Recalls $recalls,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('trace', Recall::class);

        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:64'],
            'lot' => ['nullable', 'string', 'max:64'],
        ]);

        $matches = filled($data['search'] ?? null)
            ? FinStockLot::query()->withoutGlobalScope(EntityScope::class)->where('lot_number', 'ilike', '%'.$data['search'].'%')
                ->orderByDesc('id')->limit(25)->get()->map(fn (FinStockLot $l) => $this->lotOption($l))->values()
            : [];

        $lot = filled($data['lot'] ?? null) ? $this->findLot($data['lot']) : null;

        return Inertia::render('Modules/Manufacturing/Quality/Traceability', [
            'filters' => $data,
            'matches' => $matches,
            'lot' => $lot ? $this->lotOption($lot) : null,
            'detail' => $lot ? $this->trace->lotDetail($lot) : null,
            'backward' => $lot ? $this->trace->backward($lot) : null,
            'forward' => $lot ? $this->trace->forward($lot) : null,
            'severities' => Recall::SEVERITIES,
            'can' => ['recall' => $request->user()->can('create', Recall::class)],
        ]);
    }

    // ── Recalls ──────────────────────────────────────────────────────────────────────────────

    public function recalls(Request $request): Response
    {
        $this->authorize('viewAny', Recall::class);
        $status = $request->validate(['status' => ['nullable', Rule::in([Recall::OPEN, Recall::CLOSED])]])['status'] ?? null;

        return Inertia::render('Modules/Manufacturing/Quality/Recalls', [
            'recalls' => Recall::query()->withCount('lots')->with('originLot')
                ->when($status, fn ($q, $s) => $q->where('status', $s))
                ->orderByRaw("CASE status WHEN 'OPEN' THEN 0 ELSE 1 END")->orderByDesc('opened_at')
                ->paginate(25)->withQueryString()
                ->through(fn (Recall $r) => [
                    'id' => $r->uuid, 'number' => $r->number, 'title' => $r->title, 'severity' => $r->severity, 'status' => $r->status,
                    'origin_lot' => $r->originLot?->lot_number, 'lots_count' => (int) $r->lots_count,
                    'opened_at' => $r->opened_at?->toDateString(), 'closed_at' => $r->closed_at?->toDateString(),
                ]),
            'filters' => ['status' => $status],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Recall::class);

        $data = $request->validate([
            'lot' => ['required', 'string', 'max:64'],
            'title' => ['required', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:4000'],
            'severity' => ['required', Rule::in(Recall::SEVERITIES)],
        ]);

        try {
            $recall = $this->recalls->open($this->findLot($data['lot']), $data['title'], $data['reason'], $data['severity'], $request->user());
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('manufacturing.quality.recalls.show', $recall->uuid)
            ->with('success', "{$recall->number} opened. Put the affected stock on hold.");
    }

    public function show(Request $request, string $uuid): Response
    {
        $recall = $this->find($uuid);
        $this->authorize('view', $recall);
        $recall->load(['lots.lot', 'lots.item', 'actions.lot', 'actions.actor.person', 'originLot']);

        return Inertia::render('Modules/Manufacturing/Quality/RecallShow', [
            'recall' => [
                'id' => $recall->uuid, 'number' => $recall->number, 'title' => $recall->title, 'reason' => $recall->reason,
                'severity' => $recall->severity, 'status' => $recall->status, 'origin_lot' => $recall->originLot?->lot_number,
                'origin_lot_id' => $recall->originLot?->uuid,
                'opened_at' => $recall->opened_at?->toIso8601String(), 'closed_at' => $recall->closed_at?->toIso8601String(),
                'closure_notes' => $recall->closure_notes,
            ],
            'lots' => $recall->lots->map(fn (RecallLot $rl) => [
                'id' => $rl->id, 'lot' => $rl->lot?->lot_number, 'lot_uuid' => $rl->lot?->uuid, 'item' => trim(($rl->item?->code ?? '').' — '.($rl->item?->name ?? '')),
                'depth' => (int) $rl->depth, 'in_stock_at_open' => (float) $rl->in_stock_at_open, 'dispatched_at_open' => (float) $rl->dispatched_at_open,
                'held' => (float) $rl->held, 'released' => (float) $rl->released, 'disposed' => (float) $rl->disposed, 'retrieved' => (float) $rl->retrieved,
                'on_hold' => round((float) $rl->held - (float) $rl->released - (float) $rl->disposed, 4),
                'in_stock_now' => $this->trace->inStock($rl->lot_id),
            ])->values(),
            'actions' => $recall->actions->map(fn (RecallAction $a) => [
                'id' => $a->uuid, 'kind' => $a->kind, 'lot' => $a->lot?->lot_number, 'qty' => $a->qty !== null ? (float) $a->qty : null,
                'reference' => $a->reference, 'notes' => $a->notes, 'acted_at' => $a->acted_at?->toIso8601String(),
                'actor' => $a->actor ? ($a->actor->person ? trim("{$a->actor->person->first_name} {$a->actor->person->surname}") : $a->actor->email) : null,
            ])->values(),
            'documents_panel' => \App\Modules\Operations\DocumentManagement\Support\DocumentModule::panelFor($recall, $request->user()),
            'can' => ['manage' => $request->user()->can('manage', $recall)],
        ]);
    }

    public function hold(Request $request, string $uuid): RedirectResponse
    {
        $recall = $this->find($uuid);
        $this->authorize('manage', $recall);

        return $this->attempt(function () use ($recall, $request) {
            $held = $this->recalls->holdAll($recall, $request->user());

            return $held > 0 ? "Put {$held} on hold across the affected lots." : 'Nothing usable was left in stock to hold.';
        });
    }

    public function release(Request $request, string $uuid): RedirectResponse
    {
        $recall = $this->find($uuid);
        $this->authorize('manage', $recall);
        $data = $this->lotAction($request);

        return $this->attempt(function () use ($recall, $data, $request) {
            $this->recalls->release($recall, $this->recallLot($recall, $data['recall_lot']), (float) $data['qty'], (string) $data['notes'], $request->user());

            return 'Released.';
        });
    }

    public function dispose(Request $request, string $uuid): RedirectResponse
    {
        $recall = $this->find($uuid);
        $this->authorize('manage', $recall);
        $data = $this->lotAction($request);

        return $this->attempt(function () use ($recall, $data, $request) {
            $this->recalls->dispose($recall, $this->recallLot($recall, $data['recall_lot']), (float) $data['qty'], (string) $data['notes'], $request->user());

            return 'Disposed of as scrap.';
        });
    }

    public function record(Request $request, string $uuid): RedirectResponse
    {
        $recall = $this->find($uuid);
        $this->authorize('manage', $recall);
        $data = $request->validate([
            'kind' => ['required', Rule::in(['RETRIEVED', 'CUSTOMER_NOTIFIED', 'REGULATOR_NOTIFIED', 'NOTE'])],
            'recall_lot' => ['nullable', 'integer'],
            'qty' => ['nullable', 'numeric', 'gt:0'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);

        return $this->attempt(function () use ($recall, $data, $request) {
            $this->recalls->record($recall, $data['kind'], isset($data['recall_lot']) ? $this->recallLot($recall, $data['recall_lot']) : null,
                isset($data['qty']) ? (float) $data['qty'] : null, $data['reference'] ?? null, $data['notes'] ?? null, $request->user());

            return 'Recorded.';
        });
    }

    public function close(Request $request, string $uuid): RedirectResponse
    {
        $recall = $this->find($uuid);
        $this->authorize('manage', $recall);
        $data = $request->validate(['notes' => ['required', 'string', 'max:4000']]);

        return $this->attempt(function () use ($recall, $data, $request) {
            $this->recalls->close($recall, $data['notes'], $request->user());

            return "{$recall->number} closed.";
        });
    }

    // ── Internals ────────────────────────────────────────────────────────────────────────────

    private function attempt(callable $work): RedirectResponse
    {
        try {
            $message = $work();
        } catch (DomainException|PostingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $message);
    }

    private function lotAction(Request $request): array
    {
        return $request->validate([
            'recall_lot' => ['required', 'integer'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'notes' => ['required', 'string', 'max:2000'],
        ]);
    }

    private function find(string $uuid): Recall
    {
        return Recall::query()->where('uuid', $uuid)->firstOrFail();
    }

    private function recallLot(Recall $recall, int $id): RecallLot
    {
        return $recall->lots()->whereKey($id)->firstOrFail();
    }

    /** A lot of this tenant, by uuid -- whichever of its businesses holds it. */
    private function findLot(string $uuid): FinStockLot
    {
        return FinStockLot::query()->withoutGlobalScope(EntityScope::class)->where('uuid', $uuid)->firstOrFail();
    }

    private function lotOption(FinStockLot $l): array
    {
        $item = FinStockItem::withoutGlobalScopes()->whereKey($l->item_id)->first(['code', 'name']);

        return ['id' => $l->uuid, 'lot' => $l->lot_number, 'item' => trim(($item?->code ?? '').' — '.($item?->name ?? '')),
            'origin' => $l->origin, 'expires_on' => $l->expires_on?->toDateString()];
    }
}
