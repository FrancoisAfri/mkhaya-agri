<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\PlanRecommendation as Rec;
use App\Modules\Procurement\Services\PurchaseRequestService;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A planner's decision on MRP recommendations (MFG-005, MFG-015).
 *
 * Approving makes drafts, never commitments: a production recommendation becomes a DRAFT
 * production order; purchase recommendations become DRAFT purchase requests in the procurement
 * module -- one per supplier, so demand for common materials is consolidated -- where the
 * company's own approval rules, budgets and limits take over. Nothing here places a purchase
 * order or commits funds.
 *
 * A planner may change the quantity or date of a recommendation; the change and its reason are
 * kept with it.
 */
class PlanApproval
{
    public function __construct(
        private readonly ProductionOrders $orders,
        private readonly PurchaseRequestService $requests,
    ) {}

    public function override(Rec $rec, ?float $qty, ?string $date, string $reason, ?User $actor): Rec
    {
        $this->assertProposed($rec);
        if (blank($reason)) {
            throw new DomainException('Say why the recommendation is being changed.');
        }
        if ($qty !== null && $qty <= 0) {
            throw new DomainException('A quantity greater than zero, or reject it instead.');
        }

        $rec->update(['override_qty' => $qty, 'override_date' => $date, 'decision_reason' => $reason, 'decided_by' => $actor?->id, 'decided_at' => now()]);

        return $rec;
    }

    public function reject(Rec $rec, string $reason, ?User $actor): Rec
    {
        $this->assertProposed($rec);
        if (blank($reason)) {
            throw new DomainException('Say why it is rejected.');
        }

        $rec->update(['status' => Rec::REJECTED, 'decision_reason' => $reason, 'decided_by' => $actor?->id, 'decided_at' => now()]);

        return $rec;
    }

    /**
     * Approve a set of recommendations and turn them into drafts.
     *
     * @param  Collection<int, Rec>  $recs
     * @return array{production_orders: list<string>, purchase_requests: list<string>}
     */
    public function approve(Collection $recs, ?User $actor, ?Factory $factory = null, ?FinWarehouse $warehouse = null): array
    {
        foreach ($recs as $rec) {
            $this->assertProposed($rec);
        }

        return DB::transaction(function () use ($recs, $actor, $factory, $warehouse) {
            $made = ['production_orders' => [], 'purchase_requests' => []];

            foreach ($recs->where('kind', 'PRODUCE') as $rec) {
                $made['production_orders'][] = $this->produce($rec, $actor, $factory, $warehouse);
            }

            foreach ($recs->where('kind', 'PURCHASE')->groupBy(fn ($r) => (int) $r->supplier_id) as $supplierId => $group) {
                $made['purchase_requests'][] = $this->purchase($group, $supplierId ?: null, $actor);
            }

            return $made;
        });
    }

    private function produce(Rec $rec, ?User $actor, ?Factory $factory, ?FinWarehouse $warehouse): string
    {
        $item = Item::query()->where('stock_item_id', $rec->stock_item_id)->first();
        if (! $item) {
            throw new DomainException('A production recommendation is for an item that is not a manufactured product.');
        }

        $factory ??= Factory::query()->where('is_active', true)->orderBy('id')->first();
        if (! $factory) {
            throw new DomainException('Choose the factory to make it at.');
        }
        $warehouse ??= $factory->default_warehouse_id ? FinWarehouse::query()->find($factory->default_warehouse_id) : null;
        if (! $warehouse) {
            throw new DomainException("Factory {$factory->code} has no default warehouse; choose where the output goes.");
        }

        $due = $rec->effectiveDate();
        $order = $this->orders->create([
            'item' => $item, 'quantity' => $rec->effectiveQty(), 'due_date' => $due->toDateString(),
            'start' => Carbon::parse($rec->release_by)->max(now())->toDateString(), 'factory' => $factory, 'warehouse' => $warehouse,
            'demand_type' => 'MRP', 'demand_reference' => $rec->run()->value('number'),
            'notes' => $rec->decision_reason ? "Planner override: {$rec->decision_reason}" : null,
        ], $actor);

        $this->converted($rec, 'PRODUCTION_ORDER', $order->id, $actor);

        return $order->number;
    }

    private function purchase(Collection $group, ?int $supplierId, ?User $actor): string
    {
        $needBy = $group->map(fn ($r) => $r->effectiveDate())->min();
        $request = $this->requests->create([
            'justification' => 'Raised from material requirements plan '.($group->first()->run()->value('number')).'.',
            'needed_by' => $needBy?->toDateString(), 'urgency' => $needBy && $needBy->lte(now()->addWeek()) ? 'high' : 'normal',
            'currency' => config('finance.base_currency', 'ZAR'), 'status' => 'draft',
            'estimated_total' => round($group->sum(fn ($r) => $r->effectiveQty() * (float) ($r->unit_price ?? 0)), 2),
        ], $actor?->id);

        // Same material from the same supplier is one line.
        foreach ($group->groupBy('stock_item_id') as $stockItemId => $lines) {
            $stock = FinStockItem::withoutGlobalScopes()->find($stockItemId);
            $qty = $lines->sum(fn ($r) => $r->effectiveQty());
            $price = (float) ($lines->first()->unit_price ?? 0);
            $line = $request->items()->create([
                'item_type' => 'goods', 'name' => trim(($stock?->code ?? '').' — '.($stock?->name ?? '')),
                'description' => 'Needed by '.$lines->map(fn ($r) => $r->effectiveDate()->toDateString())->unique()->implode(', '),
                'supplier_id' => $supplierId, 'quantity' => $qty, 'unit_price' => $price, 'line_total' => round($qty * $price, 2),
                'source' => 'MRP', 'external_ref' => $lines->first()->run()->value('number'),
            ]);
            $line->forceFill(['stock_item_id' => $stockItemId])->save();
        }

        foreach ($group as $rec) {
            $this->converted($rec, 'PURCHASE_REQUEST', $request->id, $actor);
        }

        return $request->request_number;
    }

    private function converted(Rec $rec, string $type, int $id, ?User $actor): void
    {
        $rec->update(['status' => Rec::CONVERTED, 'converted_type' => $type, 'converted_id' => $id,
            'decided_by' => $rec->decided_by ?? $actor?->id, 'decided_at' => $rec->decided_at ?? now()]);
    }

    private function assertProposed(Rec $rec): void
    {
        if ($rec->status !== Rec::PROPOSED) {
            throw new DomainException('That recommendation has already been decided.');
        }
        if ($rec->run()->value('status') !== 'OPEN') {
            throw new DomainException('A newer plan has been run; decide from the current one.');
        }
    }
}
