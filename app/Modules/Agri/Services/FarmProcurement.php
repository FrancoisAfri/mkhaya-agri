<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmStore;
use App\Modules\Agri\Models\SupplierDispute;
use App\Modules\Operations\WarehouseManagement\Services\SupplierScorecard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 2 §5.4–5.5 from the farm's side. Purchasing itself is Procurement's (request → requisition →
 * quotation → order → goods received → invoice match → payment); this shows a farm what it has on
 * order for its stores, what is still owed on partly delivered orders (backorders), where prices
 * moved between order, delivery and invoice, the disputes it raised, and how its suppliers perform —
 * each score with the deliveries it was worked out from.
 */
class FarmProcurement
{
    /** Orders delivering to the farm's stores, line by line, with what is still to come. */
    public function orders(Farm $farm): array
    {
        $whs = FarmStore::query()->where('farm_id', $farm->id)->pluck('warehouse_id');
        $lines = DB::table('purchase_order_items as i')->join('purchase_orders as o', 'o.id', '=', 'i.purchase_order_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'o.supplier_id')
            ->whereIn('o.warehouse_id', $whs)->whereNull('o.deleted_at')->whereNotIn('o.status', ['cancelled'])
            ->orderByDesc('o.order_date')->limit(500)
            ->get(['o.id as po_id', 'o.uuid as po_uuid', 'o.po_number', 'o.status', 'o.order_date', 'o.expected_delivery', 's.id as supplier_id', 's.company_name as supplier',
                'i.id as line_id', 'i.name', 'i.quantity', 'i.received_qty', 'i.unit_price', 'i.stock_item_id']);

        return $lines->groupBy('po_id')->map(function ($g) {
            $o = $g->first();
            $open = $g->sum(fn ($l) => max(0, (float) $l->quantity - (float) $l->received_qty));

            return ['po' => $o->po_number, 'id' => $o->po_uuid, 'supplier' => $o->supplier, 'status' => $o->status, 'ordered' => $o->order_date,
                'expected' => $o->expected_delivery, 'late' => $o->expected_delivery && Carbon::parse($o->expected_delivery)->lt(today()) && $open > 0,
                'backordered' => $open > 0 && $g->sum(fn ($l) => (float) $l->received_qty) > 0,
                'lines' => $g->map(fn ($l) => ['name' => $l->name, 'ordered' => (float) $l->quantity, 'received' => (float) $l->received_qty,
                    'outstanding' => round(max(0, (float) $l->quantity - (float) $l->received_qty), 3), 'unit_price' => (float) $l->unit_price])->values()->all()];
        })->values()->all();
    }

    /**
     * Price variance: what each line was ordered at against what was invoiced, and against the
     * previous order of the same item — the movement a buyer wants to explain.
     *
     * @return list<array<string, mixed>>
     */
    public function priceVariance(Farm $farm, ?string $from = null): array
    {
        $whs = FarmStore::query()->where('farm_id', $farm->id)->pluck('warehouse_id');
        $rows = DB::table('purchase_order_items as i')->join('purchase_orders as o', 'o.id', '=', 'i.purchase_order_id')
            ->leftJoin('supplier_invoice_items as v', 'v.purchase_order_item_id', '=', 'i.id')->leftJoin('suppliers as s', 's.id', '=', 'o.supplier_id')
            ->whereIn('o.warehouse_id', $whs)->whereNull('o.deleted_at')->when($from, fn ($q) => $q->where('o.order_date', '>=', $from))
            ->orderBy('o.order_date')
            ->get(['o.po_number', 'o.order_date', 's.company_name as supplier', 'i.stock_item_id', 'i.name', 'i.quantity', 'i.unit_price', 'v.unit_price as invoiced']);
        $last = [];
        $out = [];
        foreach ($rows as $r) {
            $prev = $r->stock_item_id ? ($last[$r->stock_item_id] ?? null) : null;
            $inv = $r->invoiced !== null ? (float) $r->invoiced : null;
            $vsInvoice = $inv !== null ? round(($inv - (float) $r->unit_price) * (float) $r->quantity, 2) : null;
            $vsPrevPct = $prev ? round(((float) $r->unit_price - $prev) / $prev * 100, 1) : null;
            if (($vsInvoice !== null && abs($vsInvoice) >= 0.01) || ($vsPrevPct !== null && abs($vsPrevPct) >= 5)) {
                $out[] = ['po' => $r->po_number, 'date' => $r->order_date, 'supplier' => $r->supplier, 'item' => $r->name, 'qty' => (float) $r->quantity,
                    'ordered_at' => (float) $r->unit_price, 'invoiced_at' => $inv, 'invoice_variance' => $vsInvoice, 'previous_price' => $prev, 'change_pct' => $vsPrevPct];
            }
            if ($r->stock_item_id) {
                $last[$r->stock_item_id] = (float) $r->unit_price;
            }
        }

        return array_reverse($out);
    }

    public function dispute(?Farm $farm, array $data, User $actor): SupplierDispute
    {
        if (! in_array($data['kind'], SupplierDispute::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => 'Choose what the dispute is about.']);
        }

        return SupplierDispute::create(array_intersect_key($data, array_flip(['supplier_id', 'purchase_order_id', 'grn_id', 'kind', 'description', 'amount']))
            + ['company_id' => $actor->company_id, 'farm_id' => $farm?->id, 'status' => 'open', 'raised_by' => $actor->id]);
    }

    public function settle(SupplierDispute $d, string $outcome, string $resolution, User $actor): SupplierDispute
    {
        if ($d->status !== 'open' || ! in_array($outcome, ['resolved', 'rejected'], true)) {
            throw ValidationException::withMessages(['dispute' => 'Only an open dispute can be resolved or rejected.']);
        }
        $d->update(['status' => $outcome, 'resolution' => $resolution, 'resolved_by' => $actor->id, 'resolved_at' => now()]);

        return $d;
    }

    /**
     * Supplier performance over a period: the warehouse scorecard (on time, lead time, quantity and
     * quality, price discrepancies) with this organisation's disputes added, and the deliveries
     * behind each supplier's numbers for anyone who wants to check them.
     *
     * @return list<array<string, mixed>>
     */
    public function suppliers(Carbon $from, Carbon $to): array
    {
        $scores = collect(app(SupplierScorecard::class)->scores($from, $to))->keyBy('supplier_id');
        $disputes = SupplierDispute::query()->whereBetween('created_at', [$from, $to->copy()->endOfDay()])->get()->groupBy('supplier_id');
        $ids = $scores->keys()->merge($disputes->keys())->unique();
        $names = DB::table('suppliers')->whereIn('id', $ids)->pluck('company_name', 'id');

        return $ids->map(function ($id) use ($scores, $disputes, $names) {
            $r = (array) ($scores[$id] ?? []) + ['supplier_id' => $id, 'supplier_uuid' => DB::table('suppliers')->where('id', $id)->value('uuid'), 'supplier' => $names[$id] ?? "#{$id}",
                'disputes' => ($disputes[$id] ?? collect())->count(), 'open_disputes' => ($disputes[$id] ?? collect())->where('status', 'open')->count()];
            // One score from whatever was measured — each part 0–100, averaged, every part shown.
            $parts = array_filter([
                'on time' => $r['on_time_rate'] ?? null, 'quantity right' => $r['quantity_accuracy'] ?? null,
                'undamaged' => isset($r['damage_rate']) ? 1 - $r['damage_rate'] : null, 'accepted' => isset($r['rejection_rate']) ? 1 - $r['rejection_rate'] : null,
                'passed inspection' => $r['inspection_pass_rate'] ?? null, 'invoiced at order price' => isset($r['price_discrepancy_rate']) ? 1 - $r['price_discrepancy_rate'] : null,
                'no disputes' => ($r['deliveries'] ?? 0) > 0 ? max(0, 1 - $r['disputes'] / max(1, $r['deliveries'])) : null,
            ], fn ($v) => $v !== null);
            $r['score'] = $parts ? (int) round(array_sum($parts) / count($parts) * 100) : null;
            $r['score_basis'] = array_map(fn ($v) => (int) round($v * 100), $parts);
            $r['evidence_size'] = $r['deliveries'] ?? 0;

            return $r;
        })->sortByDesc(fn ($r) => $r['score'] ?? -1)->values()->all();
    }

    /** The deliveries and disputes behind one supplier's score. */
    public function evidence(int $supplierId, Carbon $from, Carbon $to): array
    {
        return [
            'deliveries' => DB::table('goods_received_notes as g')->join('purchase_orders as o', 'o.id', '=', 'g.purchase_order_id')
                ->where('o.supplier_id', $supplierId)->whereBetween('g.received_at', [$from, $to->copy()->endOfDay()])->orderByDesc('g.received_at')
                ->get(['g.grn_number', 'g.received_at', 'o.po_number', 'o.order_date', 'o.expected_delivery', 'g.discrepancy_status', 'g.inspection_status'])
                ->map(fn ($g) => (array) $g + ['on_time' => $g->expected_delivery ? Carbon::parse($g->received_at)->toDateString() <= $g->expected_delivery : null])->all(),
            'disputes' => SupplierDispute::query()->where('supplier_id', $supplierId)->whereBetween('created_at', [$from, $to->copy()->endOfDay()])->orderByDesc('id')->get()
                ->map(fn ($d) => ['kind' => $d->kind, 'status' => $d->status, 'description' => $d->description, 'amount' => $d->amount, 'raised' => $d->created_at->toDateString()])->all(),
        ];
    }

    /**
     * A purchase requisition in Procurement for a farm store: drafted, for the requester to submit
     * there for approval. Delivered to the store named, else the farm's main store.
     *
     * @param  array{store_id?: ?string, needed_by?: ?string, justification: string, lines: list<array{stock_item_id: int, quantity: float, unit_price?: ?float}>}  $data
     */
    public function requisition(\App\Modules\Agri\Models\Farm $farm, array $data, \App\Core\User\Models\User $actor): \App\Modules\Procurement\Models\PurchaseRequest
    {
        $warehouseId = ! empty($data['store_id'])
            ? \App\Modules\Agri\Models\FarmStore::query()->where('uuid', $data['store_id'])->where('farm_id', $farm->id)->firstOrFail()->warehouse_id
            : app(FarmStores::class)->storeFor($farm)->id;

        return DB::transaction(function () use ($data, $farm, $actor, $warehouseId) {
            $pr = app(\App\Modules\Procurement\Services\PurchaseRequestService::class)->create([
                'department' => 'Farm: '.$farm->name, 'project_code' => $farm->code, 'urgency' => 'normal',
                'justification' => $data['justification'], 'delivery_location' => $farm->name.($farm->location ? ', '.$farm->location : ''),
                'needed_by' => $data['needed_by'] ?? null, 'currency' => $farm->currency ?: 'ZAR',
            ], $actor->id);
            foreach ($data['lines'] as $line) {
                $item = \App\Modules\Finance\Accounting\Models\FinStockItem::query()->findOrFail($line['stock_item_id']);
                $price = (float) ($line['unit_price'] ?? $item->avg_cost ?? 0);
                \App\Modules\Procurement\Models\PurchaseRequestItem::create([
                    'purchase_request_id' => $pr->id, 'item_type' => 'goods', 'name' => $item->name,
                    'description' => "{$item->code} for {$farm->name}", 'quantity' => $line['quantity'],
                    'unit_price' => $price, 'line_total' => round($price * (float) $line['quantity'], 2),
                    'source' => 'agri', 'external_ref' => "fin_stock_item:{$item->id}", 'stock_item_id' => $item->id,
                ]);
            }
            $pr->recalcTotal();
            $pr->update(['warehouse_id' => $warehouseId]);

            return $pr;
        });
    }
}
