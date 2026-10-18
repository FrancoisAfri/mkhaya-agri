<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Models\StandardCost;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Profitability (MFG-011): revenue from posted sales invoices against what the product cost to
 * make, by product, category, production order, factory, line and customer, for a period.
 *
 * The cost basis is said out loud on every row. FINAL when every order that made the product in
 * the period is closed with nothing outstanding; PROVISIONAL when any is still open or missing a
 * figure; ESTIMATED when nothing was made in the period and the approved standard is all there is.
 * A margin is never labelled final on a provisional cost.
 *
 * Revenue is counted in the reporting currency only. Invoices in another currency are listed as
 * excluded rather than converted at a rate this module does not own.
 */
class Profitability
{
    public function __construct(private readonly CostAnalysis $costs) {}

    /**
     * @return array{currency: string, from: string, to: string, rows: list<array>, totals: array, excluded: list<array>}
     */
    public function report(string $dimension, string $from, string $to, ?int $factoryId = null): array
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->endOfDay();
        $currency = (string) config('finance.base_currency', 'ZAR');

        $sales = $this->sales($from, $to, $currency);
        $basis = $this->costBasis($from, $to, $factoryId);

        $rows = match ($dimension) {
            'category' => $this->byCategory($sales['lines'], $basis),
            'customer' => $this->byCustomer($sales['lines'], $basis),
            'order' => $this->byOrder($from, $to, $factoryId, $sales['lines']),
            'factory' => $this->byPlace('factory', $from, $to, $factoryId, $sales['lines']),
            'line' => $this->byPlace('line', $from, $to, $factoryId, $sales['lines']),
            default => $this->byProduct($sales['lines'], $basis),
        };

        $revenue = collect($rows)->sum('revenue');
        $cost = collect($rows)->sum('cost');

        return [
            'currency' => $currency, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'rows' => $rows,
            'totals' => ['revenue' => round($revenue, 2), 'cost' => round($cost, 2), 'margin' => round($revenue - $cost, 2),
                'margin_percent' => $revenue > 0 ? round(($revenue - $cost) / $revenue * 100, 2) : null,
                'status' => $this->worst(array_column($rows, 'cost_status'))],
            'excluded' => $sales['excluded'],
        ];
    }

    // ── Revenue ──────────────────────────────────────────────────────────────────────────────

    /**
     * Posted sales of manufactured products in the period, credit notes netted off. Invoice lines
     * name a sales item; it is matched to the stock item by code, exactly as stock relief does.
     */
    private function sales(Carbon $from, Carbon $to, string $currency): array
    {
        $base = DB::table('fin_invoice_lines as l')
            ->join('fin_invoices as i', 'i.id', '=', 'l.invoice_id')
            ->join('fin_items as fi', 'fi.id', '=', 'l.item_id')
            ->join('fin_stock_items as s', fn ($j) => $j->on('s.code', '=', 'fi.code')->on('s.company_id', '=', 'i.company_id'))
            ->join('mfg_items as m', 'm.stock_item_id', '=', 's.id')
            ->where('i.direction', 'receivable')
            ->whereIn('i.status', ['open', 'part_paid', 'paid'])
            ->whereIn('i.doc_type', ['invoice', 'credit_note'])
            ->whereBetween('i.invoice_date', [$from->toDateString(), $to->toDateString()]);

        $lines = (clone $base)->where('i.currency', $currency)
            ->select(['m.id as item_id', 'i.party_id', 'i.doc_type', 'l.quantity', 'l.line_total', 'l.discount_amount'])
            ->get()
            ->map(fn ($r) => ['item_id' => (int) $r->item_id, 'party_id' => $r->party_id ? (int) $r->party_id : null,
                'qty' => ($r->doc_type === 'credit_note' ? -1 : 1) * (float) $r->quantity,
                'revenue' => ($r->doc_type === 'credit_note' ? -1 : 1) * (float) $r->line_total]);

        $excluded = (clone $base)->where('i.currency', '!=', $currency)
            ->select(['i.number', 'i.currency', DB::raw('SUM(l.line_total) AS amount')])->groupBy('i.number', 'i.currency')->get()
            ->map(fn ($r) => ['invoice' => $r->number, 'currency' => $r->currency, 'amount' => round((float) $r->amount, 2)])->all();

        return ['lines' => $lines, 'excluded' => $excluded];
    }

    // ── Cost basis ───────────────────────────────────────────────────────────────────────────

    /**
     * Unit cost per product: the actual cost per good unit of the orders that made it in the period,
     * weighted by output; or the approved standard if nothing was made.
     *
     * @return array<int, array{unit_cost: ?float, status: string, basis: string}>
     */
    private function costBasis(Carbon $from, Carbon $to, ?int $factoryId): array
    {
        $out = [];

        foreach ($this->ordersInPeriod($from, $to, $factoryId)->groupBy('item_id') as $itemId => $orders) {
            $cost = $good = 0.0;
            $statuses = [];
            foreach ($orders as $o) {
                $actual = $this->costs->actuals($o);
                $cost += $actual['total'];
                $good += $actual['good_output'];
                $statuses[] = $this->costs->status($o, $o->standardCost, $actual)['status'];
            }
            if ($good > 0) {
                $out[$itemId] = ['unit_cost' => $cost / $good, 'status' => $this->worst($statuses),
                    'basis' => sprintf('actual, %d order(s), %s made', $orders->count(), rtrim(rtrim(number_format($good, 4, '.', ''), '0'), '.'))];
            }
        }

        foreach (StandardCost::query()->where('status', StandardCost::APPROVED)->get() as $std) {
            $out[$std->item_id] ??= ['unit_cost' => (float) $std->unit_cost, 'status' => CostAnalysis::ESTIMATED,
                'basis' => "approved standard v{$std->version} (nothing made in the period)"];
        }

        return $out;
    }

    private function ordersInPeriod(Carbon $from, Carbon $to, ?int $factoryId): Collection
    {
        return PO::query()->with(['standardCost', 'item.stockItem', 'factory:id,code,name', 'operations.workCentre.productionLine'])
            ->where('qty_completed', '>', 0)
            ->whereIn('status', [PO::IN_PRODUCTION, PO::QUALITY_REVIEW, PO::COMPLETED, PO::CLOSED])
            ->where(fn ($q) => $q->whereBetween('completed_at', [$from, $to])
                ->orWhere(fn ($w) => $w->whereNull('completed_at')->whereBetween('started_at', [$from, $to])))
            ->when($factoryId, fn ($q) => $q->where('factory_id', $factoryId))
            ->get();
    }

    // ── Dimensions ───────────────────────────────────────────────────────────────────────────

    private function byProduct(Collection $lines, array $basis): array
    {
        $items = Item::query()->with(['stockItem:id,code,name', 'category:id,name'])->whereIn('id', $lines->pluck('item_id')->unique())->get()->keyBy('id');

        return $lines->groupBy('item_id')->map(function ($g, $itemId) use ($items, $basis) {
            $item = $items[$itemId] ?? null;

            return $this->row(['key' => $item?->uuid, 'label' => trim(($item?->stockItem?->code ?? '?').' — '.($item?->stockItem?->name ?? '')),
                'group' => $item?->category?->name], $g->sum('qty'), $g->sum('revenue'), $basis[$itemId] ?? null);
        })->sortByDesc('revenue')->values()->all();
    }

    private function byCategory(Collection $lines, array $basis): array
    {
        $products = collect($this->byProduct($lines, $basis));

        return $products->groupBy(fn ($r) => $r['group'] ?? '—')->map(fn ($g, $name) => [
            'key' => $name, 'label' => $name, 'group' => null, 'qty' => round($g->sum('qty'), 4), 'revenue' => round($g->sum('revenue'), 2),
            'cost' => round($g->sum('cost'), 2), 'margin' => round($g->sum('revenue') - $g->sum('cost'), 2),
            'margin_percent' => $g->sum('revenue') > 0 ? round(($g->sum('revenue') - $g->sum('cost')) / $g->sum('revenue') * 100, 2) : null,
            'unit_cost' => null, 'cost_status' => $this->worst($g->pluck('cost_status')->all()),
            'basis' => $g->count().' product(s)',
        ])->sortByDesc('revenue')->values()->all();
    }

    private function byCustomer(Collection $lines, array $basis): array
    {
        $parties = DB::table('fin_parties')->whereIn('id', $lines->pluck('party_id')->filter()->unique())->pluck('name', 'id');

        return $lines->groupBy(fn ($l) => $l['party_id'] ?? 0)->map(function ($g, $partyId) use ($parties, $basis) {
            $cost = 0.0;
            $statuses = [];
            foreach ($g->groupBy('item_id') as $itemId => $byItem) {
                $b = $basis[$itemId] ?? null;
                $cost += $b ? $byItem->sum('qty') * $b['unit_cost'] : 0;
                $statuses[] = $b['status'] ?? 'MISSING';
            }
            $revenue = $g->sum('revenue');

            return ['key' => (string) $partyId, 'label' => $parties[$partyId] ?? '—', 'group' => null, 'qty' => round($g->sum('qty'), 4),
                'revenue' => round($revenue, 2), 'cost' => round($cost, 2), 'margin' => round($revenue - $cost, 2),
                'margin_percent' => $revenue > 0 ? round(($revenue - $cost) / $revenue * 100, 2) : null, 'unit_cost' => null,
                'cost_status' => $this->worst($statuses), 'basis' => $g->pluck('item_id')->unique()->count().' product(s)'];
        })->sortByDesc('revenue')->values()->all();
    }

    /**
     * Each order (batch) made in the period: its actual cost against its output valued at the
     * product's average selling price in the period, unless it was made for a sales order, whose
     * own invoiced lines are used where they can be matched.
     */
    private function byOrder(Carbon $from, Carbon $to, ?int $factoryId, Collection $lines): array
    {
        $asp = $lines->groupBy('item_id')->map(fn ($g) => $g->sum('qty') > 0 ? $g->sum('revenue') / $g->sum('qty') : null);

        return $this->ordersInPeriod($from, $to, $factoryId)->map(function (PO $o) use ($asp) {
            $actual = $this->costs->actuals($o);
            $status = $this->costs->status($o, $o->standardCost, $actual);
            $price = $asp[$o->item_id] ?? null;
            $revenue = $price !== null ? $actual['good_output'] * $price : 0.0;

            return ['key' => $o->uuid, 'label' => $o->number, 'group' => $o->item?->stockItem?->code, 'qty' => $actual['good_output'],
                'revenue' => round($revenue, 2), 'cost' => $actual['total'], 'margin' => round($revenue - $actual['total'], 2),
                'margin_percent' => $revenue > 0 ? round(($revenue - $actual['total']) / $revenue * 100, 2) : null,
                'unit_cost' => $actual['unit_cost'], 'cost_status' => $status['status'],
                'basis' => $price !== null ? 'output valued at the period\'s average selling price' : 'no sales of this product in the period'];
        })->sortByDesc('cost')->values()->all();
    }

    private function byPlace(string $level, Carbon $from, Carbon $to, ?int $factoryId, Collection $lines): array
    {
        $orders = collect($this->byOrder($from, $to, $factoryId, $lines))->keyBy('key');
        $models = $this->ordersInPeriod($from, $to, $factoryId)->keyBy('uuid');

        return $orders->groupBy(function ($row) use ($models, $level) {
            $o = $models[$row['key']];
            if ($level === 'factory') {
                return $o->factory ? "{$o->factory->code} — {$o->factory->name}" : '—';
            }
            $line = $o->operations->map(fn ($op) => $op->workCentre?->productionLine)->filter()->first();

            return $line ? "{$line->code} — {$line->name}" : '—';
        })->map(fn ($g, $label) => ['key' => $label, 'label' => $label, 'group' => null, 'qty' => round($g->sum('qty'), 4),
            'revenue' => round($g->sum('revenue'), 2), 'cost' => round($g->sum('cost'), 2), 'margin' => round($g->sum('revenue') - $g->sum('cost'), 2),
            'margin_percent' => $g->sum('revenue') > 0 ? round(($g->sum('revenue') - $g->sum('cost')) / $g->sum('revenue') * 100, 2) : null,
            'unit_cost' => null, 'cost_status' => $this->worst($g->pluck('cost_status')->all()), 'basis' => $g->count().' order(s)',
        ])->sortByDesc('cost')->values()->all();
    }

    // ── Internals ────────────────────────────────────────────────────────────────────────────

    private function row(array $head, float $qty, float $revenue, ?array $basis): array
    {
        $cost = $basis ? $qty * $basis['unit_cost'] : 0.0;

        return $head + [
            'qty' => round($qty, 4), 'revenue' => round($revenue, 2), 'cost' => round($cost, 2), 'margin' => round($revenue - $cost, 2),
            'margin_percent' => $revenue > 0 ? round(($revenue - $cost) / $revenue * 100, 2) : null,
            'unit_cost' => $basis ? round($basis['unit_cost'], 4) : null,
            'cost_status' => $basis['status'] ?? 'MISSING', 'basis' => $basis['basis'] ?? 'no cost: never made and no approved standard',
        ];
    }

    /** The weakest status wins: one provisional order makes the whole figure provisional. */
    private function worst(array $statuses): string
    {
        foreach (['MISSING', CostAnalysis::ESTIMATED, CostAnalysis::PROVISIONAL] as $s) {
            if (in_array($s, $statuses, true)) {
                return $s;
            }
        }

        return $statuses === [] ? 'MISSING' : CostAnalysis::FINAL;
    }
}
