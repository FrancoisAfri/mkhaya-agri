<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\CropInputTemplate;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\FarmMachine;
use App\Modules\HR\Hr\Attendance\Models\Worker as FarmWorker;
use App\Modules\Agri\Models\IrrigationZone;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Services\StockLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Phase 2 §3.2: what a production cycle needs — seed, fertiliser, chemicals, water, labour,
 * machinery and packaging — from the crop's approved input templates, the plan's area and its
 * expected yield; and whether the farm can have it in time: stock in the farm store, purchase
 * orders on the way, supplier lead times and the plan's budget.
 *
 * Only approved templates count: a rate is agronomic advice, and somebody accountable signs it off.
 */
class InputPlanning
{
    /** Hours, litres and millimetres have no stock item; they are costed from the farm's own rates. */
    private const UNSTOCKED = ['water', 'labour', 'machinery'];

    public function __construct(private readonly StockLedger $stock, private readonly FarmStores $stores) {}

    public function approveTemplate(CropInputTemplate $t, User $actor): CropInputTemplate
    {
        if ((int) $t->created_by === (int) $actor->id) {
            throw ValidationException::withMessages(['template' => 'Somebody other than the author approves an input rate.']);
        }
        if ($t->rate <= 0) {
            throw ValidationException::withMessages(['rate' => 'A rate must be more than zero.']);
        }
        $t->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]);

        return $t;
    }

    /**
     * Every line a plan needs, with what covers it.
     *
     * @return array{lines: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function forPlan(CropPlan $plan): array
    {
        $ha = $plan->hectares();
        $yieldT = $this->expectedTonnes($plan, $ha);
        $plant = $plan->planted_on ?? $plan->planned_planting_on;
        $templates = CropInputTemplate::query()->where('crop_type_id', $plan->crop_type_id)->where('status', 'approved')
            ->where(fn ($q) => $q->whereNull('production_method')->orWhere('production_method', $plan->production_method))->orderBy('timing_days')->get();
        $farm = $plan->farm;
        $store = $farm?->warehouse_id ? $this->stores->storeFor($farm) : null;
        $rates = $this->farmRates($plan);

        $lines = $templates->map(function (CropInputTemplate $t) use ($ha, $yieldT, $plant, $store, $rates, $plan) {
            $qty = $t->basis === 'per_t_yield' ? ($yieldT !== null ? $t->rate * $yieldT : null) : $t->rate * $ha;
            $needBy = $plant && $t->timing_days !== null ? $plant->copy()->addDays($t->timing_days) : $plant;
            $line = ['category' => $t->category, 'description' => $t->description, 'rate' => $t->rate, 'basis' => $t->basis, 'unit' => $t->unit,
                'quantity' => $qty !== null ? round($qty, 3) : null, 'need_by' => $needBy?->toDateString(), 'guidance' => $t->guidance_ref,
                'item' => null, 'in_store' => null, 'on_order' => null, 'lead_time_days' => null, 'order_by' => null, 'cost' => null, 'status' => 'unknown', 'note' => null];
            if ($qty === null) {
                $line['note'] = 'Needs an expected yield on the plan.';

                return $line;
            }
            if (in_array($t->category, self::UNSTOCKED, true) || ! $t->stock_item_id) {
                $line['cost'] = $this->unstockedCost($t, $qty, $rates);
                $line['status'] = 'not_stocked';

                return $line;
            }
            $item = FinStockItem::withoutGlobalScopes()->find($t->stock_item_id);
            if (! $item) {
                return $line;
            }
            $line['item'] = $item->name;
            $line['cost'] = round($qty * (float) ($item->avg_cost ?: $item->standard_cost), 2);
            $line['in_store'] = $store ? round(max(0, $this->stock->available($item, $store->id)), 3) : 0.0;
            $orders = $this->onOrder($item, $plan->company_id);
            $line['on_order'] = round($orders->sum('outstanding'), 3);
            $line['po_due'] = $orders->pluck('expected')->filter()->min();
            $lead = $this->leadTime($item, $plan->company_id);
            $line['lead_time_days'] = $lead;
            $line['order_by'] = $needBy && $lead !== null ? $needBy->copy()->subDays($lead)->toDateString() : null;

            $short = $qty - $line['in_store'];
            if ($short <= 0.0005) {
                $line['status'] = 'covered';
            } elseif ($short - $line['on_order'] <= 0.0005) {
                $line['status'] = $line['po_due'] && $needBy && Carbon::parse($line['po_due'])->gt($needBy) ? 'arrives_late' : 'on_order';
            } else {
                $line['short'] = round($short - $line['on_order'], 3);
                $line['status'] = $line['order_by'] && Carbon::parse($line['order_by'])->lt(today()) ? 'too_late' : 'to_order';
            }

            return $line;
        })->values()->all();

        $cost = array_sum(array_map(fn ($l) => (float) ($l['cost'] ?? 0), $lines));
        $budget = $plan->budget_amount !== null ? (float) $plan->budget_amount : null;

        return ['lines' => $lines, 'totals' => [
            'hectares' => round($ha, 4), 'expected_tonnes' => $yieldT !== null ? round($yieldT, 3) : null, 'templates' => count($lines),
            'planned_cost' => round($cost, 2), 'budget' => $budget, 'over_budget' => $budget !== null && $cost > $budget + 0.005,
            'by_category' => collect($lines)->groupBy('category')->map(fn ($g) => round($g->sum(fn ($l) => (float) ($l['cost'] ?? 0)), 2))->all(),
            'problems' => collect($lines)->whereIn('status', ['to_order', 'too_late', 'arrives_late'])->count(),
        ]];
    }

    /**
     * Everything a season's plans need, item by item, against what the farms hold and have on order —
     * the buying list for the season.
     *
     * @return list<array<string, mixed>>
     */
    public function forPlans(Collection $plans): array
    {
        $rows = [];
        foreach ($plans as $plan) {
            foreach ($this->forPlan($plan)['lines'] as $l) {
                if (! $l['item'] || $l['quantity'] === null) {
                    continue;
                }
                $k = $l['item'].'|'.$l['unit'];
                $rows[$k] ??= ['item' => $l['item'], 'unit' => $l['unit'], 'quantity' => 0.0, 'plans' => [], 'earliest_need' => null,
                    'in_store' => $l['in_store'], 'on_order' => $l['on_order'], 'lead_time_days' => $l['lead_time_days'], 'cost' => 0.0];
                $rows[$k]['quantity'] += $l['quantity'];
                $rows[$k]['cost'] += (float) $l['cost'];
                $rows[$k]['plans'][] = $plan->code;
                $dates = array_filter([$rows[$k]['earliest_need'], $l['need_by']]);
                $rows[$k]['earliest_need'] = $dates ? min($dates) : null;
            }
        }

        return collect($rows)->map(function ($r) {
            $r['quantity'] = round($r['quantity'], 3);
            $r['cost'] = round($r['cost'], 2);
            $r['plans'] = array_values(array_unique($r['plans']));
            $r['to_buy'] = round(max(0, $r['quantity'] - (float) $r['in_store'] - (float) $r['on_order']), 3);
            $r['order_by'] = $r['earliest_need'] && $r['lead_time_days'] !== null ? Carbon::parse($r['earliest_need'])->subDays($r['lead_time_days'])->toDateString() : null;

            return $r;
        })->sortByDesc('to_buy')->values()->all();
    }

    private function expectedTonnes(CropPlan $plan, float $ha): ?float
    {
        if ($plan->expected_yield === null) {
            return null;
        }
        $total = (float) $plan->expected_yield * $ha;

        return match ($plan->yield_unit) {
            't' => $total, 'kg' => $total / 1000, default => $total,   // other units: the rate is per unit of yield
        };
    }

    /** Open purchase-order lines for the item: ordered, approved, not yet all received. */
    private function onOrder(FinStockItem $item, int $companyId): Collection
    {
        if (! Schema::hasTable('purchase_order_items')) {
            return collect();
        }

        return DB::table('purchase_order_items as i')->join('purchase_orders as o', 'o.id', '=', 'i.purchase_order_id')
            ->where('i.company_id', $companyId)->where('i.stock_item_id', $item->id)->whereNull('o.deleted_at')
            ->whereNotIn('o.status', ['draft', 'received', 'closed', 'cancelled', 'rejected'])
            ->get(['i.quantity', 'i.received_qty', 'o.expected_delivery'])
            ->map(fn ($r) => ['outstanding' => max(0, (float) $r->quantity - (float) $r->received_qty), 'expected' => $r->expected_delivery])
            ->filter(fn ($r) => $r['outstanding'] > 0);
    }

    /** The shortest lead time a supplier has agreed for the item, else the item's own. */
    private function leadTime(FinStockItem $item, int $companyId): ?int
    {
        $supplier = Schema::hasTable('wms_supplier_terms') ? DB::table('wms_supplier_terms')->where('stock_item_id', $item->id)
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))->whereNotNull('lead_time_days')->min('lead_time_days') : null;
        $own = Schema::hasTable('wms_item_profiles') ? DB::table('wms_item_profiles')->where('stock_item_id', $item->id)->value('lead_time_days') : null;
        $v = $supplier ?? $own;

        return $v !== null ? (int) $v : null;
    }

    /** The farm's own rates for what is not bought from a store: an hour of labour, of machine time, a cubic metre of water. */
    private function farmRates(CropPlan $plan): array
    {
        return [
            'labour' => (float) FarmWorker::query()->where('farm_id', $plan->farm_id)->where('status', 'active')->whereNotNull('hourly_cost_rate')->avg('hourly_cost_rate'),
            'machinery' => (float) FarmMachine::query()->where('farm_id', $plan->farm_id)->where('status', '!=', 'disposed')->whereNotNull('hourly_rate')->avg('hourly_rate'),
            'water' => (float) IrrigationZone::query()->where('field_id', $plan->field_id)->whereNotNull('water_cost_per_m3')->avg('water_cost_per_m3'),
        ];
    }

    private function unstockedCost(CropInputTemplate $t, float $qty, array $rates): ?float
    {
        return match ($t->category) {
            'labour' => $rates['labour'] ? round($qty * $rates['labour'], 2) : null,
            'machinery' => $rates['machinery'] ? round($qty * $rates['machinery'], 2) : null,
            // Water planned in millimetres: 1 mm on a hectare is 10 m³.
            'water' => $rates['water'] ? round(($t->unit === 'mm' ? $qty * 10 : $qty) * $rates['water'], 2) : null,
            default => null,
        };
    }
}
