<?php

namespace App\Modules\Operations\WarehouseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\WarehouseManagement\Concerns\RefusesPolitely;
use App\Modules\Operations\WarehouseManagement\Models\Count;
use App\Modules\Operations\WarehouseManagement\Models\CountLine;
use App\Modules\Operations\WarehouseManagement\Models\CountPlan;
use App\Modules\Operations\WarehouseManagement\Services\CountInsights;
use App\Modules\Operations\WarehouseManagement\Services\Counting;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** WMS-070 plans, WMS-071 counting, WMS-072 review and posting, WMS-073 accuracy. */
class CountController extends Controller
{
    use RefusesPolitely;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $counts = Count::query()->with(['warehouse:id,code', 'plan:id,name'])->withCount([
            'lines', 'lines as waiting_count' => fn ($q) => $q->whereIn('status', [CountLine::OPEN, CountLine::RECOUNT]),
            'lines as variance_count' => fn ($q) => $q->whereIn('status', [CountLine::VARIANCE, CountLine::ACCEPTED, CountLine::ADJUSTED]),
        ])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')),
                // Grouped, so the OR cannot step outside the tenant scope.
                fn ($q) => $q->where(fn ($w) => $w->whereNotIn('status', [Count::POSTED, Count::CANCELLED])->orWhere('posted_at', '>=', now()->subDays(30))))
            ->latest('id')->paginate(25)->withQueryString();

        return Inertia::render('Modules/Warehouse/Counts/Index', [
            'counts' => $counts->through(fn (Count $c) => [
                'id' => $c->uuid, 'number' => $c->number, 'status' => $c->status, 'warehouse' => $c->warehouse?->code,
                'plan' => $c->plan?->name, 'reason' => $c->reason, 'trigger' => $c->trigger, 'blind' => $c->blind, 'freeze' => $c->freeze,
                'lines' => $c->lines_count, 'waiting' => $c->waiting_count, 'variances' => $c->variance_count,
                'deadline_at' => $c->deadline_at?->toIso8601String(), 'overdue' => ! $c->isClosed() && $c->deadline_at?->isPast(),
                'created_at' => $c->created_at?->toIso8601String(),
            ]),
            'plans' => CountPlan::query()->with('warehouse:id,code')->orderBy('name')->get()->map(fn (CountPlan $p) => [
                'id' => $p->uuid, 'name' => $p->name, 'warehouse' => $p->warehouse?->uuid, 'warehouse_code' => $p->warehouse?->code,
                'frequency' => $p->frequency, 'scope' => $p->scope, 'scope_size' => count($p->scope_ids ?? []), 'abc_class' => $p->abc_class,
                'min_value' => $p->min_value !== null ? (float) $p->min_value : null, 'max_lines' => $p->max_lines, 'blind' => $p->blind,
                'freeze' => $p->freeze, 'recount_qty' => $p->recount_qty !== null ? (float) $p->recount_qty : null,
                'recount_pct' => $p->recount_pct !== null ? (float) $p->recount_pct : null,
                'recount_value' => $p->recount_value !== null ? (float) $p->recount_value : null, 'deadline_days' => $p->deadline_days,
                'next_run_at' => $p->next_run_at?->toIso8601String(), 'last_run_at' => $p->last_run_at?->toIso8601String(), 'is_active' => $p->is_active,
            ]),
            'filters' => ['status' => $request->input('status')],
            'statuses' => [Count::OPEN, Count::COUNTING, Count::REVIEW, Count::POSTED, Count::CANCELLED],
            'frequencies' => CountPlan::FREQUENCIES,
            'scopes' => CountPlan::SCOPES,
            'warehouses' => $this->warehouseOptions(),
            'zones' => FinStockBin::query()->where('level', 'ZONE')->with('warehouse:id,uuid')->orderBy('code')->get(['id', 'uuid', 'code', 'warehouse_id'])
                ->map(fn ($b) => ['id' => $b->uuid, 'title' => $b->code, 'warehouse' => $b->warehouse?->uuid]),
            'categories' => \App\Modules\Operations\Manufacturing\Models\ItemCategory::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn ($c) => ['id' => (string) $c->id, 'title' => $c->name]),
            'can' => ['manage' => $request->user()->can(RoleCatalog::MANAGE_COUNTS)],
        ]);
    }

    public function store(Request $request, Counting $counting): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::MANAGE_COUNTS), 403);
        $data = $this->validated($request);
        $data['warehouse_id'] = FinWarehouse::query()->where('uuid', $data['warehouse'])->firstOrFail()->id;
        $data['scope_ids'] = $this->scopeIds($data['scope'], $data['scope_refs'] ?? []);

        $count = null;
        $response = $this->attempt(function () use ($counting, $data, $request, &$count) {
            $count = $counting->open($data, $request->user());
        }, 'Count opened.');

        return $count ? redirect()->route('warehouse.counts.show', $count->uuid)->with('success', "{$count->number} opened.") : $response;
    }

    public function savePlan(Request $request, ?string $uuid = null): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::MANAGE_COUNTS), 403);
        $data = $this->validated($request) + $request->validate([
            'name' => ['required', 'string', 'max:120'], 'frequency' => ['required', Rule::in(CountPlan::FREQUENCIES)],
            'next_run_at' => ['nullable', 'date'], 'is_active' => ['boolean'],
        ]);
        $warehouse = FinWarehouse::query()->where('uuid', $data['warehouse'])->firstOrFail();
        $values = collect($data)->except(['warehouse', 'reason', 'scope_refs'])->all()
            + ['warehouse_id' => $warehouse->id, 'scope_ids' => $this->scopeIds($data['scope'], $data['scope_refs'] ?? [])];
        $values['next_run_at'] ??= $data['frequency'] === 'EVENT' ? null : now();
        if ($uuid && ! $request->has('scope_refs')) {
            unset($values['scope_ids']); // pausing or rescheduling a plan keeps what it counts
        }

        if ($uuid) {
            CountPlan::query()->where('uuid', $uuid)->firstOrFail()->update($values);
        } else {
            CountPlan::create($values + ['company_id' => $warehouse->company_id, 'created_by' => $request->user()->id]);
        }

        return back()->with('success', 'Count plan saved.');
    }

    public function runPlan(Request $request, string $uuid, Counting $counting): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::MANAGE_COUNTS), 403);
        $plan = CountPlan::query()->where('uuid', $uuid)->firstOrFail();

        return $this->attempt(fn () => $counting->openFromPlan($plan, $request->user(), 'MANUAL'), 'Count opened from the plan.');
    }

    public function classify(Request $request, CountInsights $insights): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::MANAGE_COUNTS), 403);
        $companyId = app(CurrentCompany::class)->id() ?? $request->user()->company_id;
        $r = $insights->classify((int) $companyId);

        return back()->with('success', "Classified: {$r['A']} A, {$r['B']} B, {$r['C']} C items.");
    }

    public function show(Request $request, string $uuid): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $count = Count::query()->where('uuid', $uuid)->with(['warehouse:id,uuid,code', 'plan:id,name', 'creator:id,email', 'approver:id,email'])->firstOrFail();
        $user = $request->user();
        $manager = $user->can(RoleCatalog::MANAGE_COUNTS);
        // Blind means the counter does not see what the books say until the count is in review.
        $reveal = ! $count->blind || $manager || in_array($count->status, [Count::REVIEW, Count::POSTED, Count::CANCELLED], true);

        $lines = $count->lines()->with(['bin:id,code', 'item:id,uuid,code,name,uom', 'lot:id,lot_number', 'adjustment:id,uuid,number,status',
            'entries' => fn ($q) => $q->orderBy('id'), 'entries.user:id,email'])
            ->orderByRaw('(SELECT code FROM fin_stock_bins WHERE id = wms_count_lines.bin_id)')->orderBy('id')->get();

        return Inertia::render('Modules/Warehouse/Counts/Show', [
            'count' => [
                'id' => $count->uuid, 'number' => $count->number, 'status' => $count->status, 'warehouse' => $count->warehouse?->code,
                'warehouse_id' => $count->warehouse?->uuid, 'plan' => $count->plan?->name, 'reason' => $count->reason, 'trigger' => $count->trigger,
                'blind' => $count->blind, 'freeze' => $count->freeze, 'snapshot_at' => $count->snapshot_at?->toIso8601String(),
                'deadline_at' => $count->deadline_at?->toIso8601String(), 'completed_at' => $count->completed_at?->toIso8601String(),
                'posted_at' => $count->posted_at?->toIso8601String(), 'created_by' => $count->creator?->email, 'approved_by' => $count->approver?->email,
                'thresholds' => ['qty' => $count->recount_qty !== null ? (float) $count->recount_qty : null,
                    'pct' => $count->recount_pct !== null ? (float) $count->recount_pct : null,
                    'value' => $count->recount_value !== null ? (float) $count->recount_value : null],
            ],
            'lines' => $lines->map(fn (CountLine $l) => [
                'id' => $l->id, 'bin' => $l->bin?->code, 'item' => $l->item?->code, 'item_id' => $l->item?->uuid, 'name' => $l->item?->name,
                'uom' => $l->item?->uom, 'lot' => $l->lot?->lot_number, 'status' => $l->status, 'round' => $l->round,
                'unexpected' => $l->unexpected, 'found_empty' => $l->found_empty,
                'snapshot' => $reveal ? (float) $l->snapshot_qty : null, 'expected' => $reveal && $l->expected_qty !== null ? (float) $l->expected_qty : null,
                'counted' => $l->counted_qty !== null && ($reveal || $l->status !== CountLine::RECOUNT) ? (float) $l->counted_qty : null,
                'variance' => $reveal && $l->variance_qty !== null ? (float) $l->variance_qty : null,
                'variance_value' => $reveal && $l->variance_value !== null ? (float) $l->variance_value : null,
                'causes' => $l->causes ?? [], 'investigation' => $l->investigation,
                'adjustment' => $l->adjustment ? ['number' => $l->adjustment->number, 'status' => $l->adjustment->status] : null,
                'mine' => $l->entries->where('round', $l->round)->contains('user_id', $user->id),
                'entries' => $reveal || $manager ? $l->entries->map(fn ($e) => ['round' => $e->round, 'by' => $e->user?->email, 'qty' => (float) $e->qty,
                    'expected' => $reveal ? (float) $e->expected_at_entry : null, 'moved' => (float) $e->moved_since_snapshot,
                    'duplicate' => $e->duplicate, 'at' => $e->counted_at?->toIso8601String()])->values() : [],
            ]),
            'bins' => FinStockBin::query()->where('warehouse_id', $count->warehouse_id)->where('level', 'BIN')->orderBy('code')
                ->get(['id', 'code'])->map(fn ($b) => ['id' => $b->id, 'title' => $b->code]),
            'reveal' => $reveal,
            'can' => ['count' => $user->can(RoleCatalog::COUNT), 'manage' => $manager],
        ]);
    }

    public function record(Request $request, string $uuid, Counting $counting): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::COUNT), 403);
        $count = Count::query()->where('uuid', $uuid)->firstOrFail();
        $data = $request->validate([
            'line_id' => ['nullable', 'integer'], 'qty' => ['required', 'numeric', 'min:0'], 'client_ref' => ['nullable', 'string', 'max:64'],
            'bin_id' => ['required_without:line_id', 'nullable', 'integer'], 'item' => ['required_without:line_id', 'nullable', 'string'],
            'lot_id' => ['nullable', 'integer'], 'serials' => ['nullable', 'array'], 'serials.*' => ['string', 'max:120'],
        ]);

        if (! empty($data['line_id'])) {
            $line = CountLine::query()->where('count_id', $count->id)->findOrFail($data['line_id']);

            return $this->attempt(fn () => $counting->record($line, (float) $data['qty'], $request->user(), $data['client_ref'] ?? null, $data['serials'] ?? null), 'Counted.');
        }
        $item = FinStockItem::query()->where('uuid', $data['item'])->firstOrFail();

        return $this->attempt(fn () => $counting->recordUnexpected($count, (int) $data['bin_id'], $item->id, $data['lot_id'] ?? null,
            (float) $data['qty'], $request->user(), $data['client_ref'] ?? null), 'Found stock recorded.');
    }

    public function act(Request $request, string $uuid, Counting $counting): RedirectResponse
    {
        $count = Count::query()->where('uuid', $uuid)->firstOrFail();
        $data = $request->validate([
            'action' => ['required', Rule::in(['submit', 'recount', 'accept', 'dismiss', 'post', 'cancel'])],
            'line_id' => ['nullable', 'integer'], 'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $user = $request->user();
        // Counters submit their work; every judgement about it belongs to whoever manages counts.
        abort_unless($data['action'] === 'submit' ? $user->can(RoleCatalog::COUNT) || $user->can(RoleCatalog::MANAGE_COUNTS) : $user->can(RoleCatalog::MANAGE_COUNTS), 403);
        $line = fn () => CountLine::query()->where('count_id', $count->id)->findOrFail($data['line_id'] ?? 0);

        return $this->attempt(fn () => match ($data['action']) {
            'submit' => $counting->submit($count, $user),
            'recount' => $counting->requestRecount($line(), $user),
            'accept', 'dismiss' => $counting->decide($line(), $data['action'], $data['note'] ?? null, $user),
            'post' => $counting->post($count, $user),
            'cancel' => $counting->cancel($count, $user, (string) ($data['note'] ?? '')),
        }, match ($data['action']) {
            'submit' => 'Sent for review.', 'recount' => 'Sent for recount.', 'post' => 'Count posted.', 'cancel' => 'Count cancelled.', default => 'Decided.',
        });
    }

    public function accuracy(Request $request, CountInsights $insights): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $from = $request->input('from', now()->subDays(90)->toDateString());
        $to = $request->input('to', now()->toDateString());
        $warehouse = $request->filled('warehouse') ? FinWarehouse::query()->where('uuid', $request->input('warehouse'))->firstOrFail() : null;

        return Inertia::render('Modules/Warehouse/Counts/Accuracy', [
            'report' => $insights->accuracy($warehouse?->id, $from, $to),
            'filters' => ['from' => $from, 'to' => $to, 'warehouse' => $warehouse?->uuid],
            'warehouses' => $this->warehouseOptions(),
        ]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'warehouse' => ['required', 'string'], 'scope' => ['required', Rule::in(CountPlan::SCOPES)],
            'scope_refs' => ['nullable', 'array'], 'scope_refs.*' => ['string', 'max:120'], 'abc_class' => ['nullable', Rule::in(['A', 'B', 'C'])],
            'min_value' => ['nullable', 'numeric', 'min:0'], 'max_lines' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'blind' => ['boolean'], 'freeze' => ['boolean'], 'recount_qty' => ['nullable', 'numeric', 'min:0'],
            'recount_pct' => ['nullable', 'numeric', 'min:0'], 'recount_value' => ['nullable', 'numeric', 'min:0'],
            'deadline_days' => ['nullable', 'integer', 'min:0', 'max:60'], 'team' => ['nullable', 'array'], 'team.*' => ['integer'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /**
     * The screen names things the way people pick them -- a bin or item by its uuid, a lot by its
     * number, a category by its id -- and the count stores ids. Anything that does not resolve
     * (another tenant's bin, a mistyped lot) is simply not in scope.
     *
     * @param  list<string>  $refs
     * @return list<int>
     */
    private function scopeIds(string $scope, array $refs): array
    {
        $uuids = array_values(array_filter($refs, fn ($r) => \Illuminate\Support\Str::isUuid($r)));

        return array_map('intval', match ($scope) {
            'ZONE', 'BINS' => FinStockBin::query()->whereIn('uuid', $uuids)->pluck('id')->all(),
            'ITEMS' => FinStockItem::query()->whereIn('uuid', $uuids)->pluck('id')->all(),
            'LOTS' => \App\Modules\Finance\Accounting\Models\FinStockLot::query()->whereIn('lot_number', $refs)->pluck('id')->all(),
            'CATEGORY' => \App\Modules\Operations\Manufacturing\Models\ItemCategory::query()->whereIn('id', array_filter($refs, 'ctype_digit'))->pluck('id')->all(),
            default => [],
        });
    }

    private function warehouseOptions(): array
    {
        return FinWarehouse::query()->where('is_active', true)->orderBy('code')->get(['uuid', 'code', 'name'])
            ->map(fn ($w) => ['id' => $w->uuid, 'title' => "{$w->code} — {$w->name}"])->all();
    }
}
