<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockLot;
use App\Modules\Finance\Accounting\Models\FinStockLotLink;
use App\Modules\Finance\Accounting\Models\FinStockMovement;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Operations\Manufacturing\Models\Inspection;
use App\Modules\Operations\Manufacturing\Models\OperationTimeLog;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use Illuminate\Support\Collection;

/**
 * Lot genealogy (MFG-010), read from the ledger rather than kept beside it.
 *
 * Two kinds of edge connect lots:
 *  - production: a lot issued to an order is a parent of every lot that order received. The
 *    quantity is the NET issue -- what went in less what came back, and ignoring anything
 *    reversed -- so a lot fully returned is not an input, and a partial batch counts only what
 *    was used;
 *  - split and merge: recorded links, each proved by a `relot` movement.
 *
 * Backward follows edges to their parents until it reaches receipts from suppliers; forward
 * follows them to their children and then out of the building -- positions, transfers, issues.
 * Material issued without a lot is reported as untraceable rather than silently dropped.
 */
class Traceability
{
    private const MAX_DEPTH = 25;

    /**
     * Upstream of a lot: everything that went into it.
     *
     * @return array{root: int, nodes: array<int, array>, edges: list<array>, untracked: list<array>}
     */
    public function backward(FinStockLot $lot): array
    {
        return $this->walk($lot, 'up');
    }

    /**
     * Downstream of a lot: everything it went into.
     *
     * @return array{root: int, nodes: array<int, array>, edges: list<array>, untracked: list<array>}
     */
    public function forward(FinStockLot $lot): array
    {
        return $this->walk($lot, 'down');
    }

    /**
     * What is known about one lot: where it came from, what made it, how it was inspected, where it
     * is now and where it has gone.
     */
    public function lotDetail(FinStockLot $lot): array
    {
        $item = FinStockItem::withoutGlobalScopes()->find($lot->item_id);
        $movements = FinStockMovement::withoutGlobalScopes()->where('lot_id', $lot->id)->orWhere('to_lot_id', $lot->id)
            ->with('warehouse:id,code,name')->orderBy('movement_date')->orderBy('id')->get();

        $orders = PO::withoutGlobalScopes()->whereIn('id', $this->producedBy($lot->id)->keys())
            ->with(['bom:id,uuid,revision', 'routing:id,uuid,revision', 'operations.workCentre.machines:assets.id,name'])->get();

        return [
            'lot' => $this->lotSummary($lot, $item),
            'supplier' => $lot->supplier_id ? \Illuminate\Support\Facades\DB::table('suppliers')->where('id', $lot->supplier_id)->value('company_name') : null,
            'purchase_order' => $lot->source_type === 'PURCHASE_ORDER' && $lot->source_id
                ? \Illuminate\Support\Facades\DB::table('purchase_orders')->where('id', $lot->source_id)->value('po_number') : null,
            'receipts' => $movements->filter(fn ($m) => in_array($m->type, ['receipt', 'adjustment'], true) && (float) $m->qty > 0)
                ->map(fn ($m) => $this->movementRow($m))->values()->all(),
            'production' => $orders->map(fn (PO $o) => [
                'order_id' => $o->uuid, 'number' => $o->number, 'bom_revision' => $o->bom?->revision, 'routing_revision' => $o->routing?->revision,
                'work_centres' => $o->operations->pluck('workCentre.code')->filter()->unique()->values()->all(),
                'machines' => $o->operations->flatMap(fn ($op) => $op->workCentre?->machines?->pluck('name') ?? [])->unique()->values()->all(),
                'operators' => $this->operatorsOf($o),
            ])->values()->all(),
            'inspections' => Inspection::withoutGlobalScopes()
                ->where(fn ($q) => $q->where('lot_id', $lot->id)->orWhereIn('production_order_id', $orders->pluck('id')))
                ->orderBy('id')->get()->map(fn (Inspection $i) => [
                    'id' => $i->uuid, 'number' => $i->number, 'stage' => $i->stage, 'result' => $i->result,
                    'disposition' => $i->disposition, 'inspected_at' => $i->inspected_at?->toIso8601String(),
                ])->values()->all(),
            'positions' => FinStockPosition::withoutGlobalScopes()->where('lot_id', $lot->id)->where('qty', '!=', 0)
                ->with(['warehouse:id,code,name', 'bin:id,code'])->get()->map(fn ($p) => [
                    'warehouse' => $p->warehouse ? "{$p->warehouse->code} — {$p->warehouse->name}" : null,
                    'bin' => $p->bin?->code, 'status' => $p->status, 'qty' => (float) $p->qty,
                ])->values()->all(),
            'movements' => $movements->map(fn ($m) => $this->movementRow($m))->values()->all(),
            'dispatched' => $this->dispatched($lot->id),
        ];
    }

    /** Stock of a lot that has left: issued, sold or sent out other than into production. */
    public function dispatched(int $lotId): float
    {
        return round((float) FinStockMovement::withoutGlobalScopes()->where('lot_id', $lotId)->whereIn('type', ['issue', 'dispatch'])
            ->whereNull('reversal_of_id')->sum(\Illuminate\Support\Facades\DB::raw('ABS(qty)')), 4);
    }

    public function inStock(int $lotId): float
    {
        return round((float) FinStockPosition::withoutGlobalScopes()->where('lot_id', $lotId)->sum('qty'), 4);
    }

    // ── The walk ─────────────────────────────────────────────────────────────────────────────

    private function walk(FinStockLot $root, string $direction): array
    {
        $nodes = [$root->id => ['depth' => 0]];
        $edges = [];
        $untracked = [];
        $queue = [[$root->id, 0]];

        while ($queue) {
            [$lotId, $depth] = array_shift($queue);
            if ($depth >= self::MAX_DEPTH) {
                continue;
            }

            foreach ($this->neighbours($lotId, $direction, $untracked) as $edge) {
                $next = $direction === 'up' ? $edge['from'] : $edge['to'];
                $edges[] = $edge;

                if (! isset($nodes[$next])) {
                    $nodes[$next] = ['depth' => $depth + 1];
                    $queue[] = [$next, $depth + 1];
                }
            }
        }

        $lots = FinStockLot::withoutGlobalScopes()->whereIn('id', array_keys($nodes))->get()->keyBy('id');
        $items = FinStockItem::withoutGlobalScopes()->whereIn('id', $lots->pluck('item_id'))->get()->keyBy('id');

        foreach ($nodes as $id => $n) {
            $lot = $lots[$id];
            $nodes[$id] = $this->lotSummary($lot, $items[$lot->item_id] ?? null) + [
                'depth' => $n['depth'], 'in_stock' => $this->inStock($id), 'dispatched' => $this->dispatched($id),
            ];
        }

        return ['root' => $root->id, 'nodes' => $nodes, 'edges' => $this->unique($edges), 'untracked' => array_values($untracked)];
    }

    /**
     * Edges touching a lot in one direction. 'up' returns edges whose `to` is this lot; 'down'
     * returns edges whose `from` is this lot.
     *
     * @return list<array{from: int, to: int, kind: string, qty: float, order?: string, order_id?: string}>
     */
    private function neighbours(int $lotId, string $direction, array &$untracked): array
    {
        $edges = [];

        if ($direction === 'up') {
            foreach ($this->producedBy($lotId) as $orderId => $made) {
                $order = PO::withoutGlobalScopes()->find($orderId);
                foreach ($this->netInputs($orderId) as $inputLot => $qty) {
                    $edges[] = ['from' => $inputLot, 'to' => $lotId, 'kind' => 'PRODUCTION', 'qty' => $qty,
                        'order' => $order?->number, 'order_id' => $order?->uuid];
                }
                foreach ($this->untrackedInputs($orderId) as $itemId => $qty) {
                    $untracked["{$orderId}:{$itemId}"] = ['order' => $order?->number, 'item' => FinStockItem::withoutGlobalScopes()->whereKey($itemId)->value('code'), 'qty' => $qty];
                }
            }
            foreach (FinStockLotLink::withoutGlobalScopes()->where('child_lot_id', $lotId)->get() as $link) {
                $edges[] = ['from' => (int) $link->parent_lot_id, 'to' => $lotId, 'kind' => $link->kind, 'qty' => (float) $link->qty];
            }
        } else {
            foreach ($this->consumedBy($lotId) as $orderId => $qty) {
                $order = PO::withoutGlobalScopes()->find($orderId);
                foreach ($this->outputs($orderId) as $outputLot => $made) {
                    $edges[] = ['from' => $lotId, 'to' => $outputLot, 'kind' => 'PRODUCTION', 'qty' => $qty,
                        'order' => $order?->number, 'order_id' => $order?->uuid];
                }
                // Consumed by an order that has made nothing yet: still affected, and named.
                if ($this->outputs($orderId) === [] && $order) {
                    $untracked["wip:{$orderId}"] = ['order' => $order->number, 'item' => null, 'qty' => $qty, 'in_progress' => true];
                }
            }
            foreach (FinStockLotLink::withoutGlobalScopes()->where('parent_lot_id', $lotId)->get() as $link) {
                $edges[] = ['from' => $lotId, 'to' => (int) $link->child_lot_id, 'kind' => $link->kind, 'qty' => (float) $link->qty];
            }
        }

        return $edges;
    }

    /** @return Collection<int, float> order id => quantity of this lot it received */
    private function producedBy(int $lotId): Collection
    {
        return $this->effective(FinStockMovement::withoutGlobalScopes()->where('lot_id', $lotId)
            ->where('type', 'mfg_receipt')->where('source_type', PO::SOURCE_TYPE))
            ->groupBy('source_id')->map(fn ($g) => round($g->sum(fn ($m) => abs((float) $m->qty)), 4));
    }

    /** @return array<int, float> lot id => quantity received from this order */
    private function outputs(int $orderId): array
    {
        return $this->effective(FinStockMovement::withoutGlobalScopes()->where('source_type', PO::SOURCE_TYPE)->where('source_id', $orderId)
            ->where('type', 'mfg_receipt')->whereNotNull('lot_id'))
            ->groupBy('lot_id')->map(fn ($g) => round($g->sum(fn ($m) => abs((float) $m->qty)), 4))->all();
    }

    /** @return array<int, float> lot id => net quantity issued to this order (issued less returned) */
    private function netInputs(int $orderId): array
    {
        return $this->net(FinStockMovement::withoutGlobalScopes()->where('source_type', PO::SOURCE_TYPE)->where('source_id', $orderId)
            ->whereIn('type', ['mfg_issue', 'mfg_return'])->whereNotNull('lot_id'), 'lot_id');
    }

    /** @return array<int, float> item id => net quantity issued with no lot */
    private function untrackedInputs(int $orderId): array
    {
        return $this->net(FinStockMovement::withoutGlobalScopes()->where('source_type', PO::SOURCE_TYPE)->where('source_id', $orderId)
            ->whereIn('type', ['mfg_issue', 'mfg_return'])->whereNull('lot_id'), 'item_id');
    }

    /** @return array<int, float> order id => net quantity of this lot it consumed */
    private function consumedBy(int $lotId): array
    {
        return $this->net(FinStockMovement::withoutGlobalScopes()->where('lot_id', $lotId)->where('source_type', PO::SOURCE_TYPE)
            ->whereIn('type', ['mfg_issue', 'mfg_return']), 'source_id');
    }

    /** Issues count in, returns count back out; anything reversed counts as never having happened. */
    private function net($query, string $key): array
    {
        return $this->effective($query)->groupBy($key)
            ->map(fn ($g) => round($g->sum(fn ($m) => $m->type === 'mfg_issue' ? abs((float) $m->qty) : -abs((float) $m->qty)), 4))
            ->filter(fn ($qty) => $qty > 0.00005)
            ->all();
    }

    /** The query's movements, less any that a reversal has undone. */
    private function effective($query): Collection
    {
        $movements = $query->get();
        $reversed = FinStockMovement::withoutGlobalScopes()->whereIn('reversal_of_id', $movements->pluck('id'))->pluck('reversal_of_id')->all();

        return $movements->reject(fn ($m) => in_array($m->id, $reversed, true));
    }

    private function operatorsOf(PO $order): array
    {
        $ids = OperationTimeLog::withoutGlobalScopes()->whereIn('order_operation_id', $order->operations->pluck('id'))->pluck('user_id')
            ->merge($order->operations->pluck('completed_by'))->filter()->unique();

        return \App\Core\User\Models\User::withoutGlobalScopes()->with('person')->whereIn('id', $ids)->get()
            ->map(fn ($u) => $u->person ? trim("{$u->person->first_name} {$u->person->surname}") : (string) $u->email)->values()->all();
    }

    private function lotSummary(FinStockLot $lot, ?FinStockItem $item): array
    {
        return [
            'id' => $lot->id, 'uuid' => $lot->uuid, 'lot' => $lot->lot_number, 'item_id' => $lot->item_id, 'item_code' => $item?->code, 'item_name' => $item?->name,
            'origin' => $lot->origin, 'supplier_lot' => $lot->supplier_lot_number,
            'manufactured_on' => $lot->manufactured_on?->toDateString(), 'expires_on' => $lot->expires_on?->toDateString(),
        ];
    }

    private function movementRow(FinStockMovement $m): array
    {
        return [
            'id' => $m->uuid ?? $m->id, 'type' => $m->type, 'qty' => (float) $m->qty, 'date' => $m->movement_date?->toDateString(),
            'reference' => $m->reference, 'warehouse' => $m->warehouse?->code, 'status' => $m->stock_status, 'to_status' => $m->to_stock_status,
            'source_type' => $m->source_type,
        ];
    }

    private function unique(array $edges): array
    {
        $seen = [];

        return array_values(array_filter($edges, function ($e) use (&$seen) {
            $key = $e['from'].'>'.$e['to'].'>'.$e['kind'].'>'.($e['order'] ?? '');
            if (isset($seen[$key])) {
                return false;
            }

            return $seen[$key] = true;
        }));
    }
}
